<?php
/**
 * Receives Nomba webhooks. Verifies the HMAC signature, then fulfils the
 * order idempotently. This is the authoritative fulfilment path.
 *
 * Signature: Nomba signs over a colon-joined field string (see
 * NombaApi::verifyWebhookSignature) using the `nomba-signature` and
 * `nomba-timestamp` headers.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class NombaWebhookModuleFrontController extends ModuleFrontController
{
    /** No CSRF / no human session on this endpoint. */
    public $auth = false;
    public $ssl = false;

    public function initContent()
    {
        $raw = Tools::file_get_contents('php://input');

        PrestaShopLogger::addLog('Nomba webhook: raw payload — ' . $raw, 1);

        // Decode first — the new HMAC scheme signs over fields inside the body.
        $event = json_decode($raw, true);
        if (!is_array($event)) {
            PrestaShopLogger::addLog('Nomba webhook: invalid JSON', 3);
            $this->respond(400, 'bad payload');
        }

        // Headers arrive as nomba-signature / nomba-timestamp.
        $signature = isset($_SERVER['HTTP_NOMBA_SIGNATURE']) ? $_SERVER['HTTP_NOMBA_SIGNATURE'] : '';
        $timestamp = isset($_SERVER['HTTP_NOMBA_TIMESTAMP']) ? $_SERVER['HTTP_NOMBA_TIMESTAMP'] : '';
        PrestaShopLogger::addLog('Nomba webhook: signature=' . $signature . ' timestamp=' . $timestamp, 1);

        if (!$this->module->getApiClient()->verifyWebhookSignature($event, $timestamp, $signature)) {
            PrestaShopLogger::addLog('Nomba webhook: bad signature', 3);
            $this->respond(401, 'invalid signature');
        }

        $type = isset($event['event_type']) ? $event['event_type'] : '';
        $data = isset($event['data']) ? $event['data'] : [];
        $transaction = isset($data['transaction']) ? $data['transaction'] : [];

        PrestaShopLogger::addLog('Nomba webhook: event_type=' . $type, 1);
        PrestaShopLogger::addLog('Nomba webhook: data keys=' . implode(',', array_keys($data)), 1);

        $orderReference = $this->extractOrderReference($data, $transaction);
        PrestaShopLogger::addLog('Nomba webhook: extracted orderReference=' . $orderReference, 1);
        if ($orderReference === '') {
            PrestaShopLogger::addLog('Nomba webhook: no order reference in payload', 2);
            $this->respond(400, 'no order reference');
        }

        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'nomba_transaction` WHERE order_reference = "' . pSQL($orderReference) . '"'
        );
        if (!$row) {
            $this->respond(404, 'unknown order reference');
        }

        // Idempotency: already fulfilled => ack and stop.
        if ($row['status'] === 'SUCCESS' && (int) $row['id_order'] > 0) {
            // Still update transaction_id — return controller may have saved a wrong one.
            $txId = isset($transaction['transactionId']) ? $transaction['transactionId'] : '';
            if ($txId !== '') {
                Db::getInstance()->update(
                    'nomba_transaction',
                    ['transaction_id' => pSQL($txId), 'date_upd' => date('Y-m-d H:i:s')],
                    'order_reference = "' . pSQL($orderReference) . '"'
                );
            }
            $this->respond(200, 'already processed');
        }

        if ($type === 'payment_success') {
            $this->fulfil($row, $transaction);
        } elseif ($type === 'payment_failed') {
            $this->markFailed($row);
        }

        $this->respond(200, 'ok');
    }

    private function markFailed(array $row)
    {
        Db::getInstance()->update(
            'nomba_transaction',
            ['status' => 'FAILED', 'date_upd' => date('Y-m-d H:i:s')],
            'order_reference = "' . pSQL($row['order_reference']) . '"'
        );
    }

    /**
     * The order reference we sent at checkout. Its exact location in the
     * webhook payload is still to be confirmed against the sandbox, so we
     * probe the likely fields. See PROGRESS.md §6.
     */
    private function extractOrderReference(array $data, array $transaction)
    {
        $candidates = [
            isset($transaction['merchantTxRef']) ? $transaction['merchantTxRef'] : null,
            isset($transaction['orderReference']) ? $transaction['orderReference'] : null,
            isset($data['order']['orderReference']) ? $data['order']['orderReference'] : null,
            isset($data['orderReference']) ? $data['orderReference'] : null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c;
            }
        }

        return '';
    }

    private function fulfil(array $row, array $transaction)
    {
        $cart = new Cart((int) $row['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog('Nomba webhook: cart gone for ref ' . $row['order_reference'], 3);
            $this->respond(200, 'cart gone');
        }

        $transactionId = isset($transaction['transactionId']) ? $transaction['transactionId'] : '';

        // Idempotency: skip if this cart already has an order.
        if ($cart->orderExists()) {
            // Still update the transaction ID — return controller may have saved a wrong one.
            if ($transactionId !== '') {
                Db::getInstance()->update(
                    'nomba_transaction',
                    ['transaction_id' => pSQL($transactionId), 'date_upd' => date('Y-m-d H:i:s')],
                    'order_reference = "' . pSQL($row['order_reference']) . '"'
                );
            }
            PrestaShopLogger::addLog('Nomba webhook: cart ' . $cart->id . ' already has an order', 2);
            $this->respond(200, 'already has order');
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            PrestaShopLogger::addLog('Nomba webhook: customer not found for cart ' . $cart->id, 3);
            $this->respond(200, 'customer gone');
        }

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PAYMENT'),
                (float) $row['amount'],
                $this->module->displayName,
                null,
                ['transaction_id' => $transactionId],
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );

            Db::getInstance()->update(
                'nomba_transaction',
                [
                    'status' => 'SUCCESS',
                    'transaction_id' => pSQL($transactionId),
                    'id_order' => (int) $this->module->currentOrder,
                    'date_upd' => date('Y-m-d H:i:s'),
                ],
                'order_reference = "' . pSQL($row['order_reference']) . '"'
            );
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Nomba webhook: validateOrder failed — ' . $e->getMessage(), 3);
            $this->respond(200, 'order creation failed');
        }
    }

    private function respond($code, $message)
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode(['status' => $code < 300, 'message' => $message]);
        exit;
    }
}
