<?php

ob_start();
error_reporting(0);

require_once __DIR__ . '../../lib/Alepay.php';
require_once __DIR__ . '../../utils/AleConfiguration.php';

if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

class DLHMemeberpressWebhookHandler
{
    private array $args;
    private Alepay $alepayAPI;

    public function __construct()
    {
        $this->args = [
            'apiKey' => get_option(AleConfiguration::$API_KEY),
            'encryptKey' => get_option(AleConfiguration::$ENCRYPT_KEY),
            'checksumKey' => get_option(AleConfiguration::$CHECKSUM_KEY),
            'base_urls' => array(
                'sanbox' => array(
                    'v3' => get_option(AleConfiguration::$BASE_URL_V3),
                    'v1' => get_option(AleConfiguration::$BASE_URL_V1),
                ),
                'live' => get_option(AleConfiguration::$BASE_URL_LIVE),
            ),
            'is_test_mode' => get_option(AleConfiguration::$TEST_MODE),
            'callbackUrl' => 'callbackUrl',
        ];

        $this->alepayAPI = new Alepay($this->args);

        add_action('rest_api_init', function () {
            register_rest_route('tronghieu', '/test', array(
                'methods' => 'POST',
                'callback' => [$this, 'handle_memeberpress_webhook']
            ));

            register_rest_route('tronghieu', '/reactive/success', array(
                'methods' => 'GET',
                'callback' => [$this, 'handle_reactive_subscription_success']
            ));

            register_rest_route('tronghieu', '/reactive/failure', array(
                'methods' => 'GET',
                'callback' => [$this, 'handle_reactive_subscription_failure']
            ));
        });
    }

    public function get_server_protocol()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    }

    public function handle_memeberpress_webhook(WP_REST_Request $request)
    {
        $request_data = json_decode($request->get_body());

        $merchant_id = $request_data->data->member->id;

        $customer_token = $request_data->data->token;
        $order_code = $request_data->data->subscr_id;
        $amount = $request_data->data->total;
        $currency = 'VND';
        $description = __('Auto subscription for member ' . $merchant_id);
        $return_url = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]" . '/buddy/wp-json/tronghieu/reactive/success';
        $cancelUrl = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]" . '/buddy/wp-json/tronghieu/reactive/failure';
        $paymentHours = '1';

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

        error_log('Prepared data');
        error_log(print_r($data, true));

        error_log('Webhook data');
        error_log(print_r($request_data, true));

        $result = $this->alepayAPI->sendTokenizationPayment($data);

        if (is_object($result)) {
            error_log('result' . print_r($result, true));
            $token = $result->token;
            $checkout_url = $result->checkoutUrl;
            error_log('checkout url' . $result->checkoutUrl);
            MeprUtils::wp_redirect($checkout_url);
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
            // TODO: Error handling
            return [
                'status' => 500,
                'message' => 'Failure',
            ];
        }

        $decrypted_data = json_decode($decrypted_data);

        if ($decrypted_data->errorCode != '000' || $decrypted_data->cancel) {
            // TODO: User reject the auto subscription
            return [
                'status' => 400,
                'message' => 'User cancel the auto subscription',
            ];
        }

        $transaction_code = $decrypted_data->data;

        // TODO: Get transaction info if needed

        return [
            'status' => 200,
            'message' => 'Success',
            'data' => $decrypted_data
        ];
    }

    public function handle_reactive_subscription_failure(WP_REST_Request $request)
    {
        error_log('Failure');
        error_log(print_r($request->get_params(), true));

        $comming_data = $request->get_params();

        $encrypted_data = $comming_data->data;
        $checksum = $comming_data->checksum;

        return [
            'status' => 200,
            'message' => 'Failure'
        ];
    }
}