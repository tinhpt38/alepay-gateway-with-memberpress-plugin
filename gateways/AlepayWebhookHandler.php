<?php

ob_start();
error_reporting(0);

require_once __DIR__ . '../../lib/Alepay.php';
require_once __DIR__ . '../../utils/AleConfiguration.php';
require_once __DIR__ . '/../plugins.php';

if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

class AlepayWebhookHandler
{
    private array $args;

    private Alepay $alepayAPI;

    private string $webhook_url;
    private string $success_url;
    private string $failure_url;

    public function __construct()
    {
        error_log(__METHOD__);
        $settings = udoo_get_settings();
        $encrypt_key = $settings['encrypt'];
        $api_key = $settings['api'];
        $checksum_key = $settings['checksum'];
        $base_url_v3 = $settings['url_v3'];
        $base_url_v1 = $settings['url_v1'];
        $base_url_live = $settings['url_live'];
        $connected = $settings['connected'];
        $test_mode = $settings['test_mode'];
        $namespace = $settings['namespace'];
        $chekout_message = $settings['checkout_message'];
        $payment_hours = $settings['payment_hours'];


        if (!$encrypt_key || !$api_key || !$checksum_key) {
            return;
        }

        $this->args = [
            'apiKey' => $api_key,
            'encryptKey' => $encrypt_key,
            'checksumKey' => $checksum_key,
            'base_urls' => array(
                'sanbox' => array(
                    'v3' => $base_url_v3,
                    'v1' => $base_url_v1,
                ),
                'live' => $base_url_live,
            ),
            'is_test_mode' => $test_mode,
            'connected' => $connected,
            'namespace' => $namespace,
            'chekout_message' => $chekout_message,
            'payment_hours' => $payment_hours,
        ];

        $this->alepayAPI = new Alepay($this->args);

        add_action('rest_api_init', function () {
            $route = $this->args['namespace'];
            register_rest_route($route, '/alepay-whk', array(
                'methods' => 'POST',
                'callback' => [$this, 'handle_memeberpress_webhook']
            ));

            register_rest_route($route, '/reactive/success', array(
                'methods' => 'GET',
                'callback' => [$this, 'handle_reactive_subscription_success']
            ));

            register_rest_route($route, '/reactive/failure', array(
                'methods' => 'GET',
                'callback' => [$this, 'handle_reactive_subscription_failure']
            ));
        });

        $webhooks = get_option('mpdt_webhooks', false);
        if ($webhooks == "")
            $webhooks = [];

        $this->webhook_url = get_site_url() . '/wp-json/' . $namespace . '/alepay-whk';
        $this->success_url = get_site_url() . '/wp-json/' . $namespace . '/reactive/success';
        $this->failure_url = get_site_url() . '/wp-json/' . $namespace . '/reactive/failure';

        $urls = array_column($webhooks, 'url');
        if (count($webhooks) == 0 || !in_array($this->webhook_url, $urls)) {
            $id = rand(1, 99999);
            $webhooks[$id] = [
                'url' => $this->webhook_url,
                'events' => [
                    'all' => 'on'
                ]
            ];
            update_option('mpdt_webhooks', $webhooks);
        }
    }

    public function handle_memeberpress_webhook(WP_REST_Request $request)
    {
        $request_data = json_decode($request->get_body());

        error_log('WEBHOOKS');
        error_log(print_r($request_data, true));

        $webhook_event = $request_data->event;
        $webhook_type = $request_data->type;
        $webhook_payload = $request_data->data;

        if ($webhook_event != 'subscription-created')
            return;

        $merchant_id = $request_data->data->member->id;

        $customer_token = $request_data->data->token;
        $order_code = $request_data->data->subscr_id;
        $amount = $request_data->data->total;
        $currency = 'VND';
        $description = __('Auto subscription for member ' . $merchant_id);
        $return_url = $this->success_url;
        $cancelUrl = $this->failure_url;
        $paymentHours = $this->args['payment_hours'];

        // Prepare data
        $data = [
            'customerToken' => $customer_token,
            'orderCode' => $order_code,
            'amount' => $amount,
            'currency' => $currency,
            'orderDescription' => $description,
            'returnUrl' => $return_url,
            'cancelUrl' => $cancelUrl,
            'paymentHours' => $paymentHours
        ];

        //        error_log('Prepared data');
        //        error_log(print_r($data, true));
        //
        //        error_log('Webhook data');
        //        error_log(print_r($request_data, true));

        $result = $this->alepayAPI->sendTokenizationPayment($data);

        if (is_object($result)) {
            error_log('result' . print_r($result, true));
            if (!$this->args['connected']) {
                $checkout_url = $result->checkoutUrl;
                $message = get_option(AleConfiguration::$CHECKOUT_MESSAGE);
                $message = str_replace(['$sub_id', '$url'], [$order_code, $checkout_url], $message);
                wp_mail($request_data->data->member->email, $message);
            }
        } else {
            error_log('MeprGatewayException');
            throw new MeprGatewayException($result['errorDescription']);
        }

        return 'success';
    }

    public function handle_reactive_subscription_success(WP_REST_Request $request)
    {
        error_log('Success');
        error_log(print_r($request->get_params(), true));

        $comming_data = $request->get_params();

        $encrypted_data = $comming_data['data'];
        $checksum = $comming_data['checksum'];

        $decrypted_data = $this->alepayAPI->decryptCallbackData($encrypted_data);

        if (!$decrypted_data) {
            return [
                'status' => 500,
                'message' => 'Failure',
            ];
        }

        $decrypted_data = json_decode($decrypted_data);

        if ($decrypted_data->errorCode != '000') {
            error_log(print_r($decrypted_data, true));
            return [
                'status' => 500,
                'message' => 'Error! Try again later',
            ];
        }

        if ($decrypted_data->cancel) {
            return [
                'status' => 400,
                'message' => 'User cancel the auto subscription',
            ];
        }

        $transaction_code = $decrypted_data->data;

        $transaction_info = $this->alepayAPI->getTransactionInfo($transaction_code);

        if (!$transaction_info) {
            return [
                'status' => 500,
                'message' => 'Get transaction info failed',
            ];
        }

        $subscription_id = $transaction_info->orderCode;
        $subscription = MeprSubscription::get_one_by_subscr_id($subscription_id);

        if (!$subscription) {
            return [
                'status' => 500,
                'message' => 'Subscription not found',
            ];
        }
        $subscription->status = MeprSubscription::$active_str;
        $subscription->save();

        // TODO: Get transaction and call `send_transaction_receipt_notices` to send email
        $latest_transaction = $subscription->latest_txn();

        MeprUtils::send_resumed_sub_notices($subscription);

        return [
            'status' => 200,
            'message' => 'Success',
            'data' => $decrypted_data,
            'transaction_info' => $transaction_info,
            'subscription' => $subscription->rec,
            'transaction' => $latest_transaction->rec
        ];
    }

    public function handle_reactive_subscription_failure(WP_REST_Request $request)
    {
        error_log('Failure');
        error_log(print_r($request->get_params(), true));

        $comming_data = $request->get_params();

        $encrypted_data = $comming_data['data'];
        $checksum = $comming_data['checksum'];

        // TODO: Error handling
        $decrypted_data = $this->alepayAPI->decryptCallbackData($encrypted_data);

        if (!$decrypted_data) {
            return [
                'status' => 500,
                'message' => 'Failure',
            ];
        }

        $decrypted_data = json_decode($decrypted_data);

        $transaction_code = $decrypted_data->data;

        $transacion = MeprTransaction::get_one($transaction_code);

        MeprUtils::send_failed_txn_notices($transacion);

        return [
            'status' => 200,
            'message' => 'Failure'
        ];
    }
}
