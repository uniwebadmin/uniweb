<?php
/**
 * Plugin Name: UniWeb Payments for WooCommerce
 * Description: Accept UniWeb payment links / checkout for WooCommerce after partner Live activation.
 * Version: 1.0.0
 * Author: UniWeb
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('plugins_loaded', static function (): void {
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_UniWeb extends WC_Payment_Gateway
    {
        /** @var string WooCommerce gateway id */
        public $id;
        /** @var string Admin settings title */
        public $method_title;
        /** @var string Admin settings description */
        public $method_description;
        /** @var bool Show fields on checkout */
        public $has_fields;
        /** @var array<int,string> Supported features */
        public $supports;
        /** @var string Customer-facing title */
        public $title;
        /** @var string yes|no */
        public $enabled;
        /** @var array<string,mixed> Admin form fields */
        public $form_fields;

        public function __construct()
        {
            $this->id = 'uniweb';
            $this->method_title = 'UniWeb';
            $this->method_description = 'Redirect customers to UniWeb hosted checkout. Live credentials require merchant Live Mode.';
            $this->has_fields = false;
            $this->supports = ['products', 'refunds'];
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title', 'Pay with UniWeb');
            $this->enabled = $this->get_option('enabled', 'no');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, [$this, 'process_admin_options']);
        }

        public function init_form_fields(): void
        {
            $this->form_fields = [
                'enabled' => [
                    'title' => 'Enable',
                    'type' => 'checkbox',
                    'label' => 'Enable UniWeb',
                    'default' => 'no',
                ],
                'title' => [
                    'title' => 'Title',
                    'type' => 'text',
                    'default' => 'UPI / Cards via UniWeb',
                ],
                'api_key' => [
                    'title' => 'API Key',
                    'type' => 'text',
                    'description' => 'Get keys from UniWeb Merchant Portal → API Keys (https://uniweb.co.in/dashboard.php)',
                ],
                'api_secret' => [
                    'title' => 'API Secret',
                    'type' => 'password',
                    'description' => 'Get keys from UniWeb Merchant Portal → API Keys. Never share your secret.',
                ],
                'mode' => [
                    'title' => 'Mode',
                    'type' => 'select',
                    'options' => ['test' => 'Test (sandbox — no real money)', 'live' => 'Live (real payments)'],
                    'default' => 'test',
                    'description' => 'Test: use test API keys. Live: use live API keys (requires KYC verified).',
                ],
                'api_base' => [
                    'title' => 'API Base URL',
                    'type' => 'text',
                    'default' => 'https://uniweb.co.in/api.php',
                ],
            ];
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $payload = [
                'action' => 'create_payment_link',
                'amount' => (float)$order->get_total(),
                'description' => 'WooCommerce order #' . $order->get_order_number(),
                'customer_name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
                'customer_email' => $order->get_billing_email(),
                'customer_phone' => $order->get_billing_phone(),
            ];
            $response = wp_remote_post($this->get_option('api_base'), [
                'timeout' => 30,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-API-Key' => $this->get_option('api_key'),
                    'X-API-Secret' => $this->get_option('api_secret'),
                    'Idempotency-Key' => 'woo-' . $order_id . '-' . time(),
                ],
                'body' => wp_json_encode($payload),
            ]);
            if (is_wp_error($response)) {
                wc_add_notice('UniWeb unavailable: ' . $response->get_error_message(), 'error');
                return ['result' => 'fail'];
            }
            $body = json_decode(wp_remote_retrieve_body($response), true);
            $url = $body['checkout_url'] ?? $body['data']['checkout_url'] ?? '';
            if ($url === '') {
                wc_add_notice('UniWeb did not return a checkout URL.', 'error');
                return ['result' => 'fail'];
            }
            $order->update_status('pending', 'Awaiting UniWeb payment');
            return ['result' => 'success', 'redirect' => $url];
        }
    }

    add_filter('woocommerce_payment_gateways', static function (array $methods): array {
        $methods[] = 'WC_Gateway_UniWeb';
        return $methods;
    });
});
