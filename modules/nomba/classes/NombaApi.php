<?php
/**
 * Thin, dependency-free client for the Nomba REST API.
 *
 * Covers the full lifecycle the module needs: auth (with token caching),
 * checkout order creation, transaction verification, and refunds — plus
 * HMAC webhook signature verification.
 *
 * Base URLs:
 *   sandbox  https://sandbox.nomba.com
 *   live     https://api.nomba.com
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class NombaApi
{
    const SANDBOX_BASE = 'https://sandbox.nomba.com';
    const LIVE_BASE = 'https://api.nomba.com';

    /** @var string */
    private $clientId;
    /** @var string */
    private $clientSecret;
    /** @var string */
    private $accountId;
    /** @var string */
    private $signatureKey;
    /** @var bool */
    private $testMode;
    /** @var string|null */
    private $token;

    public function __construct($clientId, $clientSecret, $accountId, $signatureKey, $testMode = true)
    {
        $this->clientId = (string) $clientId;
        $this->clientSecret = (string) $clientSecret;
        $this->accountId = (string) $accountId;
        $this->signatureKey = (string) $signatureKey;
        $this->testMode = (bool) $testMode;
    }

    public function getBaseUrl()
    {
        return $this->testMode ? self::SANDBOX_BASE : self::LIVE_BASE;
    }

    /**
     * Issue (and cache for this request and across requests) a bearer token
     * via client_credentials. Tokens are cached in Configuration for 50 minutes.
     *
     * @throws NombaApiException
     */
    public function getToken()
    {
        if ($this->token !== null) {
            return $this->token;
        }

        $cachedToken = Configuration::get('NOMBA_CACHED_TOKEN');
        $cachedExpiry = (int) Configuration::get('NOMBA_CACHED_TOKEN_EXPIRY');
        if ($cachedToken && $cachedExpiry > time()) {
            $this->token = $cachedToken;

            return $this->token;
        }

        $res = $this->request('POST', '/v1/auth/token/issue', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], false);

        // Nomba nests the token under data.access_token.
        $token = isset($res['data']['access_token']) ? $res['data']['access_token'] : null;
        if (!$token) {
            throw new NombaApiException('Could not obtain access token from Nomba.', 0, $res);
        }

        $this->token = $token;

        Configuration::updateValue('NOMBA_CACHED_TOKEN', $token);
        Configuration::updateValue('NOMBA_CACHED_TOKEN_EXPIRY', time() + 3000);

        return $this->token;
    }

    /**
     * Clear the persistent token cache (called when credentials change).
     */
    public static function clearTokenCache()
    {
        Configuration::deleteByName('NOMBA_CACHED_TOKEN');
        Configuration::deleteByName('NOMBA_CACHED_TOKEN_EXPIRY');
    }

    /**
     * Create a hosted checkout order. Returns the decoded `data` block,
     * which contains `checkoutLink` and order identifiers.
     *
     * @param array $params orderReference, amount, currency, customerEmail, callbackUrl, ...
     * @throws NombaApiException
     */
    public function createOrder(array $params)
    {
        $payload = [
            'order' => array_merge([
                'orderReference' => '',
                'callbackUrl' => '',
                'customerEmail' => '',
                'amount' => 0,
                'currency' => 'NGN',
            ], $params),
            'tokenizeCard' => false,
        ];

        $res = $this->request('POST', '/v1/checkout/order', $payload, true);

        if (empty($res['data']['checkoutLink'])) {
            throw new NombaApiException('Nomba did not return a checkout link.', 0, $res);
        }

        return $res['data'];
    }

    /**
     * Verify a transaction by its order reference (server-side source of truth).
     * Uses /v1/transactions/accounts/single which works in both sandbox and production.
     *
     * @throws NombaApiException
     */
    public function verifyByOrderReference($orderReference)
    {
        $query = '?orderReference=' . rawurlencode($orderReference);

        return $this->request('GET', '/v1/transactions/accounts/single' . $query, null, true);
    }

    /**
     * Refund a (fully or partially) settled checkout transaction.
     *
     * @param string     $transactionId Nomba transaction id
     * @param float|null $amount         null => full refund
     * @throws NombaApiException
     */
    public function refund($transactionId, $amount = null)
    {
        $payload = ['transactionId' => $transactionId];
        if ($amount !== null) {
            $payload['amount'] = (float) $amount;
        }

        return $this->request('POST', '/v1/checkout/refund', $payload, true);
    }

    /**
     * Verify the HMAC signature on an incoming webhook per Nomba's spec.
     *
     * Nomba computes the HMAC-SHA256 over a colon-joined string of 9 fields
     * extracted from the event payload plus the nomba-timestamp header:
     *
     *   event_type:requestId:userId:walletId:transactionId:type:time:responseCode:timestamp
     *
     * The result is Base64-encoded and compared against the nomba-signature header.
     *
     * @param array  $event     decoded webhook payload
     * @param string $timestamp value from the nomba-timestamp header
     * @param string $signature value from the nomba-signature header
     */
    public function verifyWebhookSignature(array $event, $timestamp, $signature)
    {
        if ($this->signatureKey === '' || $signature === '' || $timestamp === '') {
            return false;
        }

        $data = isset($event['data']) ? $event['data'] : [];
        $merchant = isset($data['merchant']) ? $data['merchant'] : [];
        $transaction = isset($data['transaction']) ? $data['transaction'] : [];

        $eventType = isset($event['event_type']) ? $event['event_type'] : '';
        $requestId = isset($event['requestId']) ? $event['requestId'] : '';
        $userId = isset($merchant['userId']) ? $merchant['userId'] : '';
        $walletId = isset($merchant['walletId']) ? $merchant['walletId'] : '';
        $transactionId = isset($transaction['transactionId']) ? $transaction['transactionId'] : '';
        $transactionType = isset($transaction['type']) ? $transaction['type'] : '';
        $transactionTime = isset($transaction['time']) ? $transaction['time'] : '';
        $responseCode = isset($transaction['responseCode']) ? $transaction['responseCode'] : '';

        if (strtolower($responseCode) === 'null') {
            $responseCode = '';
        }

        $fields = [$eventType, $requestId, $userId, $walletId,
                    $transactionId, $transactionType, $transactionTime,
                    $responseCode, $timestamp];

        $hashingPayload = implode(':', $fields);

        $computed = base64_encode(hash_hmac('sha256', $hashingPayload, $this->signatureKey, true));

        return hash_equals($computed, $signature);
    }

    /**
     * Low-level HTTP. Returns decoded JSON as an array.
     *
     * @throws NombaApiException
     */
    private function request($method, $path, $body, $withAuth)
    {
        $url = $this->getBaseUrl() . $path;

        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'accountId: ' . $this->accountId,
        ];
        if ($withAuth) {
            $headers[] = 'Authorization: Bearer ' . $this->getToken();
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        $raw = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new NombaApiException('Network error contacting Nomba: ' . $curlErr);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new NombaApiException('Invalid JSON from Nomba (HTTP ' . $httpCode . ').', $httpCode);
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $msg = isset($decoded['description']) ? $decoded['description']
                : (isset($decoded['message']) ? $decoded['message'] : 'Nomba API error');
            throw new NombaApiException($msg, $httpCode, $decoded);
        }

        return $decoded;
    }
}

class NombaApiException extends Exception
{
    /** @var mixed */
    public $response;

    public function __construct($message, $code = 0, $response = null)
    {
        parent::__construct($message, (int) $code);
        $this->response = $response;
    }
}
