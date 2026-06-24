<?php
/**
 * Creates a Nomba checkout order for the current cart and redirects the
 * customer to the hosted checkout link.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class NombaRedirectModuleFrontController extends ModuleFrontController
{
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$cart->id || $cart->nbProducts() <= 0 || !$this->module->active) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');
        }

        $currency = new Currency($cart->id_currency);
        $amount = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $orderReference = 'PS-' . (int) $cart->id . '-' . time();

        $callbackUrl = $this->context->link->getModuleLink($this->module->name, 'return', [
            'order_reference' => $orderReference,
        ], true);

        try {
            $data = $this->module->getApiClient()->createOrder([
                'orderReference' => $orderReference,
                'amount' => $amount,
                'currency' => $currency->iso_code,
                'customerEmail' => $customer->email,
                'callbackUrl' => $callbackUrl,
            ]);
        } catch (NombaApiException $e) {
            PrestaShopLogger::addLog('Nomba createOrder failed: ' . $e->getMessage(), 3);
            $this->errors[] = $this->module->l('We could not start your Nomba payment. Please try again.', 'redirect');
            return $this->setTemplate('module:nomba/views/templates/front/error.tpl');
        }

        try {
            Db::getInstance()->insert('nomba_transaction', [
                'order_reference' => pSQL($orderReference),
                'id_cart' => (int) $cart->id,
                'status' => 'PENDING',
                'amount' => $amount,
                'currency' => pSQL($currency->iso_code),
                'date_add' => date('Y-m-d H:i:s'),
                'date_upd' => date('Y-m-d H:i:s'),
            ]);
        } catch (Exception $e) {
            PrestaShopLogger::addLog('Nomba DB insert failed: ' . $e->getMessage(), 3);
            $this->errors[] = $this->module->l('Could not record your payment. Please try again.', 'redirect');

            return $this->setTemplate('module:nomba/views/templates/front/error.tpl');
        }

        Tools::redirect($data['checkoutLink']);
    }
}
