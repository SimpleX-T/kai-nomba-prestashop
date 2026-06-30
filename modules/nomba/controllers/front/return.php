<?php
/**
 * Customer-facing return URL after the hosted checkout. Verifies the
 * transaction server-side; if the webhook hasn't fired yet, fulfils the
 * order directly so the customer sees a completed order.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class NombaReturnModuleFrontController extends ModuleFrontController
{
    public function initContent()
    {
        parent::initContent();

        $orderReference = Tools::getValue('order_reference');
        $row = Db::getInstance()->getRow(
            'SELECT * FROM `' . _DB_PREFIX_ . 'nomba_transaction` WHERE order_reference = "' . pSQL($orderReference) . '"'
        );

        if (!$row) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $status = $row['status'];
        $failureMessage = Tools::getValue('message', '');

        $orderId = 0;
        $secureKey = '';

        if ($status !== 'SUCCESS') {
            try {
                $res = $this->module->getApiClient()->verifyByOrderReference($orderReference);
                $remoteStatus = isset($res['data']['status']) ? strtoupper($res['data']['status']) : '';

                if (empty($failureMessage)) {
                    $failureMessage = isset($res['data']['gatewayMessage']) ? $res['data']['gatewayMessage'] : '';
                }

                if (in_array($remoteStatus, ['SUCCESS', 'COMPLETED'], true)) {
                    $transactionId = isset($res['data']['id']) ? $res['data']['id'] : '';

                    if ((int) $row['id_order'] > 0) {
                        // Webhook already created the order — just update status.
                        $this->updateTransaction($row, $orderReference, $transactionId);
                        $status = 'SUCCESS';
                        $orderId = (int) $row['id_order'];
                    } else {
                        // Webhook hasn't fired (no tunnel) — fulfil now.
                        $result = $this->fulfil($row, $transactionId, $orderReference);
                        $status = $result['status'];
                        $orderId = (int) $result['order_id'];
                        $secureKey = isset($result['secure_key']) ? $result['secure_key'] : '';
                    }
                } elseif (in_array($remoteStatus, ['FAILED', 'DECLINED'], true)) {
                    if (empty($failureMessage)) {
                        $failureMessage = $remoteStatus === 'DECLINED'
                            ? $this->module->l('Your card was declined.', 'return')
                            : $this->module->l('Payment failed.', 'return');
                    }
                    Db::getInstance()->update(
                        'nomba_transaction',
                        ['status' => 'FAILED', 'date_upd' => date('Y-m-d H:i:s')],
                        'order_reference = "' . pSQL($orderReference) . '"'
                    );
                    $status = 'FAILED';
                }
            } catch (NombaApiException $e) {
                PrestaShopLogger::addLog('Nomba verify failed: ' . $e->getMessage(), 2);
                if (empty($failureMessage)) {
                    $failureMessage = $this->module->l('Could not verify payment status.', 'return');
                }
            }
        }

        if ($status === 'SUCCESS' && $orderId > 0) {
            if ($secureKey === '') {
                // Status came from webhook path — get key from the existing order.
                $order = new Order($orderId);
                $cart = new Cart((int) $order->id_cart);
                $customer = new Customer((int) $cart->id_customer);
                $secureKey = $customer->secure_key;
            }
            Tools::redirect('index.php?controller=order-confirmation&id_cart=' . (int) $row['id_cart']
                . '&id_module=' . (int) $this->module->id
                . '&id_order=' . $orderId
                . '&key=' . $secureKey);
        }

        $this->context->smarty->assign([
            'nomba_status' => $status,
            'nomba_order_reference' => $orderReference,
            'nomba_amount' => $row['amount'],
            'nomba_currency' => $row['currency'],
            'nomba_message' => $failureMessage,
        ]);
        $this->setTemplate('module:nomba/views/templates/front/payment_return.tpl');
    }

    /**
     * Create the PrestaShop order (same logic as webhook.php::fulfil).
     * Errors are caught and logged so the customer sees a friendly page.
     * Returns ['status' => 'SUCCESS', 'order_id' => N] on success,
     * or ['status' => 'ORDER_FAILED', 'order_id' => 0] on failure.
     */
    private function fulfil(array $row, $transactionId, $orderReference)
    {
        $cart = new Cart((int) $row['id_cart']);
        if (!Validate::isLoadedObject($cart)) {
            PrestaShopLogger::addLog('Nomba return: cart ' . $row['id_cart'] . ' not found', 3);
            return ['status' => 'ORDER_FAILED', 'order_id' => 0];
        }

        // Double-check the cart hasn't already been used (idempotency guard).
        if ($cart->orderExists()) {
            PrestaShopLogger::addLog('Nomba return: cart ' . $cart->id . ' already has an order', 2);
            return ['status' => 'ORDER_FAILED', 'order_id' => 0];
        }

        $customer = new Customer((int) $cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            PrestaShopLogger::addLog('Nomba return: customer not found for cart ' . $cart->id, 3);
            return ['status' => 'ORDER_FAILED', 'order_id' => 0];
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

            $orderId = (int) $this->module->currentOrder;

            Db::getInstance()->update(
                'nomba_transaction',
                [
                    'status' => 'SUCCESS',
                    'transaction_id' => pSQL($transactionId),
                    'id_order' => $orderId,
                    'date_upd' => date('Y-m-d H:i:s'),
                ],
                'order_reference = "' . pSQL($orderReference) . '"'
            );

            return ['status' => 'SUCCESS', 'order_id' => $orderId, 'secure_key' => $customer->secure_key];
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog('Nomba return: validateOrder failed — ' . $e->getMessage(), 3);
            return ['status' => 'ORDER_FAILED', 'order_id' => 0];
        }
    }

    /**
     * Webhook already fulfilled — just persist any missing fields.
     */
    private function updateTransaction(array $row, $orderReference, $transactionId)
    {
        $updates = ['status' => 'SUCCESS', 'date_upd' => date('Y-m-d H:i:s')];
        if ($transactionId !== '' && empty($row['transaction_id'])) {
            $updates['transaction_id'] = pSQL($transactionId);
        }

        Db::getInstance()->update('nomba_transaction', $updates, 'order_reference = "' . pSQL($orderReference) . '"');
    }
}
