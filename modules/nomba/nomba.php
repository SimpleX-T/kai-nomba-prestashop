<?php
/**
 * Nomba payment module for PrestaShop 8.
 *
 * Lifecycle: install/configure -> checkout (hosted) -> webhook fulfilment
 * -> verify -> refund. See classes/NombaApi.php for the API client.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/NombaApi.php';

class Nomba extends PaymentModule
{
    const CONFIG_KEYS = [
        'NOMBA_TEST_MODE',
        'NOMBA_CLIENT_ID',
        'NOMBA_CLIENT_SECRET',
        'NOMBA_ACCOUNT_ID',
        'NOMBA_SIGNATURE_KEY',
    ];

    public function __construct()
    {
        $this->name = 'nomba';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'devtochukwu (Kai)';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['redirect', 'webhook', 'return'];
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Kai — Nomba Payments');
        $this->description = $this->l('Accept card and bank transfer payments via Nomba, with webhook fulfilment and refunds.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall Kai — Nomba Payments?');
    }

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('paymentReturn')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->registerHook('header')
            && $this->installDb()
            && Configuration::updateValue('NOMBA_TEST_MODE', 1);
    }

    public function uninstall()
    {
        foreach (self::CONFIG_KEYS as $key) {
            Configuration::deleteByName($key);
        }
        Configuration::deleteByName('NOMBA_CACHED_TOKEN');
        Configuration::deleteByName('NOMBA_CACHED_TOKEN_EXPIRY');

        return $this->uninstallDb() && parent::uninstall();
    }

    private function installDb()
    {
        return (bool) Db::getInstance()->execute(
            require __DIR__ . '/sql/install.php'
        );
    }

    private function uninstallDb()
    {
        return (bool) Db::getInstance()->execute(
            require __DIR__ . '/sql/uninstall.php'
        );
    }

    /** Build an API client from stored config. */
    public function getApiClient()
    {
        return new NombaApi(
            Configuration::get('NOMBA_CLIENT_ID'),
            Configuration::get('NOMBA_CLIENT_SECRET'),
            Configuration::get('NOMBA_ACCOUNT_ID'),
            Configuration::get('NOMBA_SIGNATURE_KEY'),
            (bool) Configuration::get('NOMBA_TEST_MODE')
        );
    }

    /* ------------------------------------------------------------------ */
    /* Configuration screen                                                */
    /* ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitNomba')) {
            foreach (self::CONFIG_KEYS as $key) {
                Configuration::updateValue($key, trim(Tools::getValue($key)));
            }
            NombaApi::clearTokenCache();
            $output .= $this->displayConfirmation($this->l('Settings updated.'));
        }

        if (Tools::isSubmit('testNombaConnection')) {
            $output .= $this->testConnection();
        }

        return $output . $this->renderConfigForm();
    }

    private function testConnection()
    {
        try {
            $client = $this->getApiClient();
            $client->getToken();

            return $this->displayConfirmation($this->l('Connection successful! Nomba API token obtained.'));
        } catch (NombaApiException $e) {
            $detail = '';
            if (is_array($e->response)) {
                $detail = ' <pre>' . htmlspecialchars(json_encode($e->response, JSON_PRETTY_PRINT)) . '</pre>';
            }
            return $this->displayError($this->l('Connection failed: ') . $e->getMessage() . $detail);
        }
    }

    private function renderConfigForm()
    {
        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->l('Nomba API credentials'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Test (sandbox) mode'),
                        'name' => 'NOMBA_TEST_MODE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Yes')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    ['type' => 'text', 'label' => $this->l('Client ID'), 'name' => 'NOMBA_CLIENT_ID', 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Client Secret'), 'name' => 'NOMBA_CLIENT_SECRET', 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Account ID'), 'name' => 'NOMBA_ACCOUNT_ID', 'required' => true],
                    ['type' => 'text', 'label' => $this->l('Signature Key (webhook HMAC)'), 'name' => 'NOMBA_SIGNATURE_KEY', 'required' => true],
                ],
                'submit' => ['title' => $this->l('Save'), 'class' => 'btn btn-default pull-right'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitNomba';
        $helper->fields_value = [];
        foreach (self::CONFIG_KEYS as $key) {
            $helper->fields_value[$key] = Configuration::get($key);
        }

        $form = $helper->generateForm([$fields_form]);

        $testUrl = AdminController::$currentIndex . '&configure=' . $this->name
            . '&token=' . Tools::getAdminTokenLite('AdminModules')
            . '&testNombaConnection=1';
        $form .= '<div class="panel"><div class="panel-heading">' . $this->l('Connection test') . '</div>
            <div class="panel-body"><a href="' . $testUrl . '" class="btn btn-primary">'
            . $this->l('Test Nomba Connection') . '</a>
            <p class="help-block">' . $this->l('Saves current settings and attempts to fetch an API token.') . '</p>
            </div></div>';

        return $form;
    }

    /* ------------------------------------------------------------------ */
    /* Front office header assets                                         */
    /* ------------------------------------------------------------------ */

    public function hookHeader()
    {
        $this->context->controller->addCSS($this->_path . 'views/css/nomba.css');
    }

    /* ------------------------------------------------------------------ */
    /* Checkout                                                            */
    /* ------------------------------------------------------------------ */

    public function hookPaymentOptions($params)
    {
        if (!$this->active || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $option = new \PrestaShop\PrestaShop\Core\Payment\PaymentOption();
        $option->setCallToActionText($this->l('Pay with Nomba (card or bank transfer)'))
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setModuleName($this->name);

        return [$option];
    }

    public function hookPaymentReturn($params)
    {
        if (!$this->active) {
            return '';
        }

        $order = isset($params['order']) ? $params['order'] : null;
        $status = 'UNKNOWN';
        $reference = '';

        if (Validate::isLoadedObject($order)) {
            $txn = Db::getInstance()->getRow(
                'SELECT status, order_reference FROM `' . _DB_PREFIX_ . 'nomba_transaction`
                 WHERE id_order = ' . (int) $order->id
            );
            if ($txn) {
                $status = $txn['status'];
                $reference = $txn['order_reference'];
            } else {
                $status = 'SUCCESS';
                $reference = $order->reference;
            }
        }

        $this->context->smarty->assign([
            'nomba_status' => $status,
            'nomba_order_reference' => $reference,
        ]);

        return $this->fetch('module:nomba/views/templates/front/payment_return.tpl');
    }

    private function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /* ------------------------------------------------------------------ */
    /* Admin order screen — refund                                         */
    /* ------------------------------------------------------------------ */

    public function getTransactionByOrderId($idOrder)
    {
        return Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'nomba_transaction`
             WHERE id_order = ' . (int) $idOrder
        );
    }

    public function hookDisplayAdminOrderMainBottom($params)
    {
        $order = new Order((int) $params['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return '';
        }
        $transaction = $this->getTransactionByOrderId((int) $order->id);

        if (!$transaction || empty($transaction['transaction_id'])) {
            return '';
        }

        $refundedAmount = (float) $transaction['refunded_amount'];
        $remainingAmount = (float) $transaction['amount'] - $refundedAmount;

        if (Tools::isSubmit('submitNombaRefund')) {
            $refundAmount = Tools::getValue('nomba_refund_amount');
            $refundAmount = $refundAmount !== '' && $refundAmount !== null ? (float) $refundAmount : null;

            if ($refundAmount !== null && ($refundAmount <= 0 || $refundAmount > $remainingAmount)) {
                $this->context->controller->informations[] = $this->l('Invalid refund amount.');
            } else {
                try {
                    $this->getApiClient()->refund($transaction['transaction_id'], $refundAmount);
                    $newRefunded = $refundAmount !== null ? $refundedAmount + $refundAmount : $transaction['amount'];
                    Db::getInstance()->update(
                        'nomba_transaction',
                        [
                            'refunded_amount' => (float) $newRefunded,
                            'date_upd' => date('Y-m-d H:i:s'),
                        ],
                        'id_nomba_transaction = ' . (int) $transaction['id_nomba_transaction']
                    );
                    $this->context->controller->confirmations[] = $this->l('Refund processed successfully.');
                    // Refresh transaction data
                    $transaction = $this->getTransactionByOrderId((int) $order->id);
                    $refundedAmount = (float) $transaction['refunded_amount'];
                    $remainingAmount = (float) $transaction['amount'] - $refundedAmount;
                } catch (NombaApiException $e) {
                    PrestaShopLogger::addLog('Nomba refund failed: ' . $e->getMessage(), 3);
                    $this->context->controller->errors[] = $this->l('Refund failed: ') . $e->getMessage();
                }
            }
        }

        $this->context->smarty->assign([
            'nomba_transaction' => $transaction,
            'nomba_refunded_amount' => $refundedAmount,
            'nomba_remaining_amount' => $remainingAmount,
        ]);

        return $this->fetch('module:nomba/views/templates/hook/refund.tpl');
    }
}
