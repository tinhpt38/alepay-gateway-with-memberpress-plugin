<?php
ob_start();
error_reporting(0);

require_once __DIR__ . '../../lib/Alepay.php';
require_once __DIR__ . '../../utils/AleConfiguration.php';
require_once __DIR__ . '../../plugins.php';


if (!defined('ABSPATH')) {
    die('You are not allowed to call this page directly.');
}

class MeprAlepayGateway extends MeprBaseRealGateway
{

    private Alepay $alepayAPI;

    /** Used in the view to identify the gateway */
    public function __construct()
    {
        error_log(__METHOD__);
        $this->name = __("Alepay", 'alepay-gateway');
        $this->icon = plugins_url('/alepay-gateway') . '/images/alepay.png';
        $this->desc = __('Payment with Alepay', 'alepay-gateway');
        $this->key = __('Alepay', 'alepay-gateway');
        $this->set_defaults();
        $this->has_spc_form = true;

        $this->capabilities = array(
            'process-credit-cards',
            'process-payments',
            'process-refunds',
            'create-subscriptions',
            'cancel-subscriptions',
            'update-subscriptions',
            'suspend-subscriptions',
            'resume-subscriptions',
            'send-cc-expirations',
            'subscription-trial-payment'
        );
        $this->notifiers = array(
            'whk' => 'webhook_listener',
        );
        $this->message_pages = array('cancel' => 'cancel_message', 'payment_failed' => 'payment_failed_message');
    }

    public function load($settings)
    {
        $this->settings = (object)$settings;
        $this->set_defaults();
    }



    protected function set_defaults()
    {
        error_log(__METHOD__);
        if (!isset($this->settings)) {
            $this->settings = array();
        }

        $settings = udoo_get_settings();
        $encrypt_key = $settings['encrypt'];
        $api_key = $settings['api'];
        $checksum_key = $settings['checksum'];
        $base_url_v3 = $settings['url_v3'];
        $base_url_v1 = $settings['url_v1'];
        $base_url_live = $settings['url_live'];
        $connected = $settings['connected'];
        $test_mode = $settings['test_mode'];
        $payment_hours = $settings['payment_hours'];
        $email = $settings['email'];


        $test_mode = $test_mode == 'checked' ? true : false;

        $this->settings = (object)array_merge(
            array(
                'gateway' => 'MeprAlepayGateway',
                'id' => $this->generate_id(),
                'label' => 'AlePay',
                'use_label' => true,
                'use_icon' => true,
                'use_desc' => true,
                'sandbox' => $test_mode,
                'force_ssl' => false,
                'debug' => false,
                'test_mode' => $test_mode,
                'payment_hours' => $payment_hours,
                'connect_status' => $connected,
                'email' => $email,
                'encrypt_key' => $encrypt_key,
                'api_key' => $api_key,
                'checksum_key' => $checksum_key,
                'callback_url' => '',
                'base_urls' => array(
                    'sanbox' => array(
                        'v3' => $base_url_v3,
                        'v1' => $base_url_v1,
                    ),
                    'live' => $base_url_live,
                ),
            ),
            (array)$this->settings
        );

        $this->id = $this->settings->id;
        $this->label = $this->settings->label;
        $this->use_label = $this->settings->use_label;
        $this->use_icon = $this->settings->use_icon;
        $this->use_desc = $this->settings->use_desc;
        $this->connect_status = $this->settings->connect_status;
    }


    /**
     * @param $subscription MeprSubscription
     *
     * @return bool
     */
    protected function hide_update_link($subscription)
    {
        if ($subscription->status === MeprSubscription::$suspended_str) {
            return true;
        }

        return false;
    }

    public function process_payment_form($txn)
    {
        parent::process_payment_form($txn);
    }

    /**
     * @imp
     * Used to send data to a given payment gateway. In gateways which redirect
     * before this step is necessary this method should just be left blank.
     */

    public function process_payment($txn)
    {
        error_log(__METHOD__);
        if (!isset($txn) || !($txn instanceof MeprTransaction)) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        $usr = $txn->user();
        $prd = $txn->product();

        $this->initialize_payment_api();

        update_user_meta($usr->ID, 'first_name', trim($_REQUEST['mepr-buyer-first-name']));
        update_user_meta($usr->ID, 'last_name', trim($_REQUEST['mepr-buyer-last-name']));
        update_user_meta($usr->ID, 'billing_address_1', trim($_REQUEST['mepr-buyer-address']));
        update_user_meta($usr->ID, 'billing_phone', trim($_REQUEST['mepr-buyer-phone']));
        update_user_meta($usr->ID, 'billing_city', trim($_REQUEST['mepr-buyer-city']));
        update_user_meta($usr->ID, 'billing_country', trim($_REQUEST['mepr-buyer-country']));
        update_user_meta($usr->ID, 'billing_state', trim($_REQUEST['mepr-buyer-state']));
        update_user_meta($usr->ID, 'billing_post_code', trim($_REQUEST['mepr-buyer-postal-code']));

        // TODO: txn amount
        if ($prd->trial) {
            $txn->set_subtotal($prd->trial_amount);
        } else {
            $txn->set_subtotal($prd->price);
        }

        $amount = $txn->amount;

        $des = isset($_REQUEST['mepr-buyer-des']) ? $_REQUEST['mepr-buyer-des'] : null;
        if (!$des) {
            $des = __('The order create by Buddy Press for product ' . $prd->post_title);
        }

        $buyer_name = trim($_REQUEST['mepr-buyer-last-name']) . ' ' . trim($_REQUEST['mepr-buyer-first-name']);
        $data['cancelUrl'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" . '&returnUrl=1';
        $data['allowDomestic'] = true;
        $data['amount'] = doubleval($amount);
        $data['orderCode'] = $txn->id;
        $data['customMerchantId'] = strval($usr->ID);
        $data['currency'] = 'VND';
        $data['orderDescription'] = $des;
        $data['totalItem'] = intval(1);
        $data['checkoutType'] = intval(4);
        $data['buyerName'] = $buyer_name;
        $data['buyerEmail'] = trim($_REQUEST['mepr-buyer-email']);
        $data['buyerPhone'] = trim($_REQUEST['mepr-buyer-phone']);
        $data['buyerAddress'] = trim($_REQUEST['mepr-buyer-address']);
        $data['buyerCity'] = trim($_REQUEST['mepr-buyer-city']);
        $data['buyerCountry'] = trim($_REQUEST['mepr-buyer-country']);
        $data['installment'] = false;

        $data['returnUrl'] = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        $payment_type = $_REQUEST['alepay_payment_type'];

        set_transient('raw_data', json_encode($data), 60 * 60);

        if ($payment_type == 'one-time-international') {
            $this->process_one_time_international($txn, $data, $usr);
        } else if ($payment_type == 'one-time-domestic') {
            $this->process_one_time_domestic($txn, $data);
        } else {
            throw new MeprGatewayException(__('Invalid payment type', 'memberpress'));
        }
    }

    /** Used to record a successful recurring payment by the given gateway. It
     * should have the ability to record a successful payment or a failure. It is
     * this method that should be used when receiving an IPN from PayPal or a
     * Silent Post from Authorize.net.
     */
    public function record_subscription_payment()
    {
        return false;
    }

    /**
     * Record a subscription payment that doesn't have an associated charge
     *
     * Called when the invoice payment was 0.00, which can happen if the subscription amount is less than
     * the Alepay minimum payment. We want to record these as subscription payments unless it's the first "payment"
     * of a free trial.
     *
     * @return MeprTransaction|false The created transaction or false if no transaction was created
     */
    public function record_subscription_free_invoice_payment()
    {
        return false;
    }


    /** Used to record a declined payment. */
    public function record_payment_failure()
    {
        return false;
    }

    /** Used to record a successful payment by the given gateway. It should have
     * the ability to record a successful payment or a failure. It is this method
     * that should be used when receiving an IPN from PayPal or a Silent Post
     * from Authorize.net.
     */
    public function record_payment($charge = null)
    {
        $this->email_status("Starting record_payment: " . MeprUtils::object_to_string($_REQUEST), $this->settings->debug);

        if (empty($charge)) {
            $charge = isset($_REQUEST['data']) ? (object)$_REQUEST['data'] : [];
        } else {
            $charge = (object)$charge;
        }

        if (!empty($charge)) {
            $this->email_status("record_payment: \n" . MeprUtils::object_to_string($charge, true) . "\n", $this->settings->debug);
            $obj = MeprTransaction::get_one_by_trans_num($charge->id);

            if (is_object($obj) and isset($obj->id)) {
                $txn = new MeprTransaction();
                $txn->load_data($obj);
                $usr = $txn->user();

                // Just short circuit if the txn has already completed
                if ($txn->status == MeprTransaction::$complete_str)
                    return;

                $txn->status = MeprTransaction::$complete_str;
                // This will only work before maybe_cancel_old_sub is run
                $upgrade = $txn->is_upgrade();
                $downgrade = $txn->is_downgrade();

                $event_txn = $txn->maybe_cancel_old_sub();
                $txn->store();

                $this->email_status("Standard Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

                $prd = $txn->product();

                if ($prd->period_type == 'lifetime') {
                    if ($upgrade) {
                        $this->upgraded_sub($txn, $event_txn);
                    } else if ($downgrade) {
                        $this->downgraded_sub($txn, $event_txn);
                    } else {
                        $this->new_sub($txn);
                    }

                    MeprUtils::send_signup_notices($txn);
                }

                MeprUtils::send_transaction_receipt_notices($txn);
                MeprUtils::send_cc_expiration_notices($txn);
            }
        }

        return false;
    }

    /** This method should be used by the class to record a successful refund from
     * the gateway. This method should also be used by any IPN requests or Silent Posts.
     */
    public function process_refund(MeprTransaction $txn)
    {
        error_log(__METHOD__);
        return $this->record_refund();
    }

    /** This method should be used by the class to record a successful refund from
     * the gateway. This method should also be used by any IPN requests or Silent Posts.
     */
    public function record_refund()
    {
        error_log(__METHOD__);
        return false;
    }

    public function process_trial_payment($txn)
    {


        error_log(__METHOD__);
        $mepr_options = MeprOptions::fetch();
        $sub = $txn->subscription();
        $txn->set_subtotal($sub->trial_amount);
        $txn->status = MeprTransaction::$pending_str;
        return $this->record_trial_payment($txn);
    }

    public function record_trial_payment($txn)
    {
        error_log(__METHOD__);
        $sub = $txn->subscription();

        //Update the txn member vars and store
        $txn->txn_type = MeprTransaction::$payment_str;
        $txn->status = MeprTransaction::$complete_str;
        $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($sub->trial_days), 'Y-m-d 23:59:59');
        $txn->store();

        return true;
    }

    /**
     * Activate the subscription
     *
     * Also sets up the grace period confirmation transaction (if enabled).
     *
     * @param MeprTransaction $txn The MemberPress transaction
     * @param MeprSubscription $sub The MemberPress subscription
     */
    public function activate_subscription(MeprTransaction $txn, MeprSubscription $sub)
    {
        error_log(__METHOD__);
        $mepr_options = MeprOptions::fetch();

        $sub->status = MeprSubscription::$active_str;
        $sub->created_at = gmdate('c');
        $sub->store();


        $txn->trans_num = $sub->subscr_id;
        $txn->status = MeprTransaction::$complete_str;
        $txn->txn_type = MeprTransaction::$payment_str;
        // $txn->expires_at = $expires_at;
        // $txn->set_subtotal(0.0);
        $txn->store();

        // TODO: Check it out and uncomment later
        // MeprUtils::send_transaction_receipt_notices($txn);
    }


    /** Used to send subscription data to a given payment gateway. In gateways
     * which redirect before this step is necessary this method should just be
     * left blank.
     * @throws MeprGatewayException
     */
    public function process_create_subscription($txn)
    {
        error_log(__METHOD__);

        if (!isset($txn) || !($txn instanceof MeprTransaction)) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        $usr = $txn->user();
        $prd = $txn->product();

        $sub = $txn->subscription();


        update_user_meta($usr->ID, 'first_name', trim($_REQUEST['mepr-buyer-first-name']));
        update_user_meta($usr->ID, 'last_name', trim($_REQUEST['mepr-buyer-last-name']));
        update_user_meta($usr->ID, 'billing_address_1', trim($_REQUEST['mepr-buyer-address']));
        update_user_meta($usr->ID, 'billing_phone', trim($_REQUEST['mepr-buyer-phone']));
        update_user_meta($usr->ID, 'billing_city', trim($_REQUEST['mepr-buyer-city']));
        update_user_meta($usr->ID, 'billing_country', trim($_REQUEST['mepr-buyer-country']));
        update_user_meta($usr->ID, 'billing_state', trim($_REQUEST['mepr-buyer-state']));
        update_user_meta($usr->ID, 'billing_post_code', trim($_REQUEST['mepr-buyer-postal-code']));

        $first_txn = $sub->first_txn();
        if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
            MeprUtils::send_new_sub_notices($sub);
            $sub->store();
        }

        $amount = $sub->trial ? $sub->trial_amount : $sub->total;

        $des = isset($_REQUEST['mepr-buyer-des']) ? $_REQUEST['mepr-buyer-des'] : null;
        if (!$des) {
            $des = __('The order create by Buddy Press for product ' . $prd->post_title);
        }

        $buyer_name = trim($_REQUEST['mepr-buyer-last-name']) . ' ' . trim($_REQUEST['mepr-buyer-first-name']);
        $data['cancelUrl'] = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $data['allowDomestic'] = true;
        $data['amount'] = doubleval($amount);
        $data['orderCode'] = $sub->subscr_id;
        $data['customMerchantId'] = strval($usr->ID);
        $data['currency'] = 'VND';
        $data['orderDescription'] = $des;
        $data['totalItem'] = intval(1);
        $data['checkoutType'] = intval(4);
        $data['buyerName'] = $buyer_name;
        $data['buyerEmail'] = trim($_REQUEST['mepr-buyer-email']);
        $data['buyerPhone'] = trim($_REQUEST['mepr-buyer-phone']);
        $data['buyerAddress'] = trim($_REQUEST['mepr-buyer-address']);
        $data['buyerCity'] = trim($_REQUEST['mepr-buyer-city']);
        $data['buyerCountry'] = trim($_REQUEST['mepr-buyer-country']);
        $data['installment'] = false;
        $data['returnUrl'] = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $payment_type = $_REQUEST['alepay_payment_type'];
        set_transient('raw_data', json_encode($data), 60 * 60);
        $this->initialize_payment_api();
        if ($payment_type == 'international' || $payment_type == 'international-none-link') {
            $this->process_internation_payment($txn, $data, $usr, $payment_type);
        } else if ($payment_type == 'domestic') {
            $this->process_domestic_payment($txn, $data);
        } else if ($payment_type == 'one_click_payment') {
            $this->process_one_click_payment($txn);
        } else {
            throw new MeprGatewayException(__('Invalid payment type', 'memberpress'));
        }
    }

    public function get_server_protocol()
    {
        return (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
    }

    public function process_one_time_domestic($txn, $data)
    {
        $data['returnUrl'] = $data['returnUrl'] . '&onetimeDomesticResult=1';
        $result = $this->alepayAPI->sendOrderToAlepayDomesticATM($data);

        if ($result->code != '000') {
            throw new MeprGatewayException(__($result->message, 'memberpress'));
        }

        $checkout_url = $result->checkoutUrl;
        $this->email_status("process_domestic_payment: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);

        MeprUtils::wp_redirect($checkout_url);
    }



    public function process_one_time_international($txn, $data, $usr)
    {
        unset($data['allowDomestic']);
        unset($data['customMerchantId']);
        unset($data['installment']);
        $data['merchantSideUserId'] = strval($usr->ID);
        $data['buyerPostalCode'] = trim($_REQUEST['mepr-buyer-postal-code']);
        $data['buyerState'] = trim($_REQUEST['mepr-buyer-state']);
        $data['paymentHours'] = $this->settings['payment_hours'];
        $data['checkoutType'] = intval(1);
        $data['returnUrl'] = $data['returnUrl'] . '&onetimeInternationalResult=1';

        $isCardLink = $_REQUEST['is-card-link'];
        $data['isCardLink'] = true;

        if ($isCardLink != 'on') {
            $data['isCardLink'] = false;
            unset($data['merchantSideUserId']);
            unset($data['buyerPostalCode']);
            unset($data['buyerState']);
        }

        $result = $this->alepayAPI->sendRequestOrderInternational($data);
        if (!is_object($result)) {
            throw new MeprGatewayException(__($result->errorDescription, 'memberpress'));
        }

        $checkout_url = $result->checkoutUrl;
        $this->email_status("process_international_payment: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);

        MeprUtils::wp_redirect($checkout_url);
    }

    public function process_internation_payment($txn, $data, $usr, $payment_type)
    {

        unset($data['allowDomestic']);
        unset($data['customMerchantId']);
        unset($data['installment']);
        $data['merchantSideUserId'] = strval($usr->ID);
        $data['buyerPostalCode'] = trim($_REQUEST['mepr-buyer-postal-code']);
        $data['buyerState'] = trim($_REQUEST['mepr-buyer-state']);
        $data['paymentHours'] = '1';
        $data['checkoutType'] = intval(1);
        $data['returnUrl'] = $data['returnUrl'] . '&internationalResult=1';

        $isCardLink = $_REQUEST['is-card-link'];
        if (isset($isCardLink)) {
            if ($payment_type == 'international') {
                $data['isCardLink'] = true;
            } else {
                $data['isCardLink'] = false;
            }
        } else {
            $data['isCardLink'] = true;
            if ($isCardLink != 'on') {
                $data['isCardLink'] = false;
                unset($data['merchantSideUserId']);
                unset($data['buyerPostalCode']);
                unset($data['buyerState']);
            }
        }

        $result = $this->alepayAPI->sendRequestOrderInternational($data);
        if (!is_object($result)) {
            throw new MeprGatewayException(__($result->errorDescription, 'memberpress'));
        }

        $checkout_url = $result->checkoutUrl;
        $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);
        MeprUtils::wp_redirect($checkout_url);
    }

    public function process_domestic_payment($txn, $data)
    {
        $data['cancelUrl'] = $data['cancelUrl'] . '&domesticResultUserCancel';
        $data['returnUrl'] = $data['returnUrl'] . '&domesticResult=1';
        $this->initialize_payment_api();
        $result = $this->alepayAPI->sendOrderToAlepayDomesticATM($data);
        if ($result->code != '000') {
            throw new MeprGatewayException(__($result->message, 'memberpress'));
        }

        $checkout_url = $result->checkoutUrl;
        $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);
        MeprUtils::wp_redirect($checkout_url);
    }

    public function process_one_click_payment($txn)
    {
        if (isset($txn) and $txn instanceof MeprTransaction) {
            $usr = $txn->user();
            $prd = $txn->product();
        } else {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }
        // $sub = $txn->subscription();
        $data['id'] = $usr->ID;
        $data['firstName'] = trim($_REQUEST['mepr-buyer-first-name']);
        $data['lastName'] = trim($_REQUEST['mepr-buyer-last-name']);
        $data['street'] = trim($_REQUEST['mepr-buyer-address']);
        $data['city'] = trim($_REQUEST['mepr-buyer-city']);
        $data['state'] = trim($_REQUEST['mepr-buyer-state']);
        $data['postalCode'] = trim($_REQUEST['mepr-buyer-postal-code']);
        $data['country'] = trim($_REQUEST['mepr-buyer-country']);
        $data['email'] = trim($_REQUEST['mepr-buyer-email']);
        $data['phoneNumber'] = trim($_REQUEST['mepr-buyer-phone']);

        $callback = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $data['callback'] = $callback . '&cardLinkRequest=1';

        $this->initialize_payment_api();
        $result = $this->alepayAPI->sendCardLinkRequest($data);

        if (isset($result->errCode)) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
            exit;
        }

        MeprUtils::wp_redirect($result->url);
    }

    /** Used to record a successful subscription by the given gateway.It should have
     * the ability to record a successful subscription or a failure. It is this method
     * that should be used when receiving an IPN from PayPal or a Silent P ost
     * from Authorize.net.
     */
    public function record_create_subscription()
    {
        error_log(__METHOD__);
        $mepr_options = MeprOptions::fetch();

        if (isset($_REQUEST['data'])) {
            $customer = (object)$_REQUEST['data'];
            $subscription = isset($_REQUEST['subscription']) ? (object)$_REQUEST['subscription'] : null;
            $sub = isset($subscription, $subscription->id) ? MeprSubscription::get_one_by_subscr_id($subscription->id) : null;
            if (!($sub instanceof MeprSubscription)) {
                $sub = MeprSubscription::get_one_by_subscr_id($customer->orderCode);
            }

            // Skip if the subscription was not found
            if ($sub instanceof MeprSubscription) {
                $sub->status = MeprSubscription::$active_str;

                if (empty($sub->created_at)) {
                    $sub->created_at = gmdate('c');
                }

                $sub->store();

                // This will only work before maybe_cancel_old_sub is run
                $upgrade = $sub->is_upgrade();
                $downgrade = $sub->is_downgrade();

                $event_txn = $sub->maybe_cancel_old_sub();

                $txn = $sub->first_txn();
                if ($txn == false || !($txn instanceof MeprTransaction)) {
                    $txn = new MeprTransaction();
                    $txn->user_id = $sub->user_id;
                    $txn->product_id = $sub->product_id;
                }
                $txn->store();

                if ($upgrade) {
                    $this->upgraded_sub($sub, $event_txn);
                } else if ($downgrade) {
                    $this->downgraded_sub($sub, $event_txn);
                } else {
                    $this->new_sub($sub, true);
                }

                MeprUtils::send_signup_notices($txn);
                return array('subscription' => $sub, 'transaction' => $txn);
            }
        }
        return false;
    }

    public function process_update_subscription($sub_id)
    {
        error_log(__METHOD__);
        // This is handled via Ajax
        $sub = new MeprSubscription($sub_id);


        $userID = $sub->user_id;

        $this->initialize_payment_api();

        update_user_meta($userID, 'first_name', trim($_REQUEST['mepr-buyer-first-name']));
        update_user_meta($userID, 'last_name', trim($_REQUEST['mepr-buyer-last-name']));
        update_user_meta($userID, 'billing_address_1', trim($_REQUEST['mepr-buyer-address']));
        update_user_meta($userID, 'billing_phone', trim($_REQUEST['mepr-buyer-phone']));
        update_user_meta($userID, 'billing_city', trim($_REQUEST['mepr-buyer-city']));
        update_user_meta($userID, 'billing_country', trim($_REQUEST['mepr-buyer-country']));
        update_user_meta($userID, 'billing_state', trim($_REQUEST['mepr-buyer-state']));
        update_user_meta($userID, 'billing_post_code', trim($_REQUEST['mepr-buyer-postal-code']));

        $des = isset($_REQUEST['mepr-buyer-des']) ? $_REQUEST['mepr-buyer-des'] : null;
        if (!$des) {
            $des = __('The order create by Buddy Press for product ' . $sub->subscr_id);
        }

        $buyer_name = trim($_REQUEST['mepr-buyer-last-name']) . ' ' . trim($_REQUEST['mepr-buyer-first-name']);
        $data['cancelUrl'] = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $data['allowDomestic'] = true;
        $data['orderCode'] = $sub->subscr_id;
        $data['customMerchantId'] = strval($userID);
        $data['currency'] = 'VND';
        $data['orderDescription'] = $des;
        $data['totalItem'] = intval(1);
        $data['checkoutType'] = intval(4);
        $data['buyerName'] = $buyer_name;
        $data['buyerEmail'] = trim($_REQUEST['mepr-buyer-email']);
        $data['buyerPhone'] = trim($_REQUEST['mepr-buyer-phone']);
        $data['buyerAddress'] = trim($_REQUEST['mepr-buyer-address']);
        $data['buyerCity'] = trim($_REQUEST['mepr-buyer-city']);
        $data['buyerCountry'] = trim($_REQUEST['mepr-buyer-country']);
        $data['installment'] = false;
        $data['returnUrl'] = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $payment_type = $_REQUEST['alepay_payment_type'];
        set_transient('raw_data', json_encode($data), 60 * 60);

        if ($payment_type == 'international' || $payment_type == 'international-none-link') {
            unset($data['allowDomestic']);
            unset($data['customMerchantId']);
            unset($data['installment']);
            $data['merchantSideUserId'] = strval($userID);
            $data['buyerPostalCode'] = trim($_REQUEST['mepr-buyer-postal-code']);
            $data['buyerState'] = trim($_REQUEST['mepr-buyer-state']);
            $data['paymentHours'] = '1';
            $data['checkoutType'] = intval(1);
            $data['returnUrl'] = $data['returnUrl'] . '&internationalResult=1';

            $isCardLink = $_REQUEST['is-card-link'];
            if (isset($isCardLink)) {
                if ($payment_type == 'international') {
                    $data['isCardLink'] = true;
                } else {
                    $data['isCardLink'] = false;
                }
            } else {
                $data['isCardLink'] = true;
                if ($isCardLink != 'on') {
                    $data['isCardLink'] = false;
                    unset($data['merchantSideUserId']);
                    unset($data['buyerPostalCode']);
                    unset($data['buyerState']);
                }
            }

            $result = $this->alepayAPI->sendRequestOrderInternational($data);
            if (!is_object($result)) {
                throw new MeprGatewayException(__($result->errorDescription, 'memberpress'));
            };

            $checkout_url = $result->checkoutUrl;
            MeprUtils::wp_redirect($checkout_url);
        } else if ($payment_type == 'domestic') {
            // $this->process_domestic_payment($txn, $data);/
        } else if ($payment_type == 'one_click_payment') {
            $this->process_update_onclickpayment($userID);
        } else {
            throw new MeprGatewayException(__('Invalid payment type', 'memberpress'));
        }
    }

    public function process_update_onclickpayment($userID)
    {
        $data['id'] = $userID;
        $data['firstName'] = trim($_REQUEST['mepr-buyer-first-name']);
        $data['lastName'] = trim($_REQUEST['mepr-buyer-last-name']);
        $data['street'] = trim($_REQUEST['mepr-buyer-address']);
        $data['city'] = trim($_REQUEST['mepr-buyer-city']);
        $data['state'] = trim($_REQUEST['mepr-buyer-state']);
        $data['postalCode'] = trim($_REQUEST['mepr-buyer-postal-code']);
        $data['country'] = trim($_REQUEST['mepr-buyer-country']);
        $data['email'] = trim($_REQUEST['mepr-buyer-email']);
        $data['phoneNumber'] = trim($_REQUEST['mepr-buyer-phone']);

        $callback = $this->get_server_protocol() . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $data['callback'] = $callback . '&cardLinkRequest=1';
        $this->initialize_payment_api();
        $result = $this->alepayAPI->sendCardLinkRequest($data);

        if (isset($result->errCode)) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
            exit;
        }

        MeprUtils::wp_redirect($result->url);
    }

    /** This method should be used by the class to record a successful cancellation
     * from the gateway. This method should also be used by any IPN requests or
     * Silent Posts.
     */
    public function record_update_subscription()
    {
        // No need for this one with Alepay
    }

    /** Used to suspend a subscription by the given gateway.
     */
    public function process_suspend_subscription($sub_id)
    {
        error_log(__METHOD__);
        //Todo: Send email cho khach hanfg
        $mepr_options = MeprOptions::fetch();
        $sub = new MeprSubscription($sub_id);

        if ($sub->status == MeprSubscription::$suspended_str) {
            throw new MeprGatewayException(__('This subscription has already been paused.', 'memberpress'));
        }

        if (!MeprUtils::is_mepr_admin() && $sub->in_free_trial()) {
            throw new MeprGatewayException(__('Sorry, subscriptions cannot be paused during a free trial.', 'memberpress'));
        }

        $args = MeprHooks::apply_filters('mepr_alepay_suspend_subscription_args', array(), $sub);

        $_REQUEST['data'] = $sub;

        return $this->record_suspend_subscription();
    }

    /** This method should be used by the class to record a successful suspension
     * from the gateway.
     */
    public function record_suspend_subscription()
    {
        if (isset($_REQUEST['data'])) {
            $sub = (object)$_REQUEST['data'];
            // Seriously ... if sub was already cancelled what are we doing here?
            if ($sub->status == MeprSubscription::$suspended_str) {
                return $sub;
            }

            $sub->status = MeprSubscription::$suspended_str;
            $sub->store();
            MeprUtils::send_suspended_sub_notices($sub);
        } else {
            return false;
        }
    }

    /** Used to suspend a subscription by the given gateway.
     */
    public function process_resume_subscription($sub_id)
    {
        error_log(__METHOD__);
        $mepr_options = MeprOptions::fetch();
        MeprHooks::do_action('mepr-pre-alepay-resume-subscription', $sub_id); //Allow users to change the subscription programatically before resuming it
        $sub = new MeprSubscription($sub_id);
        if ($sub->status == MeprSubscription::$active_str) {
            throw new MeprGatewayException(__('This subscription has already been resumed.', 'memberpress'));
        }

        if ($sub->is_expired() and !$sub->is_lifetime()) {
            $expiring_txn = $sub->expiring_txn();

            // if it's already expired with a real transaction
            // then we want to resume immediately
            if (
                $expiring_txn != false && $expiring_txn instanceof MeprTransaction &&
                $expiring_txn->status != MeprTransaction::$confirmed_str
            ) {
                $sub->trial = false;
                $sub->trial_days = 0;
                $sub->trial_amount = 0.00;
                $sub->store();
            }
        } else {
            $sub->trial = true;
            $sub->trial_days = MeprUtils::tsdays(strtotime($sub->expires_at) - time());
            $sub->trial_amount = 0.00;
            $sub->store();
        }

        $customer = $sub->user();
        $prd = $sub->product();

        $args = MeprHooks::apply_filters('mepr_alepay_resume_subscription_args', [
            'customer' => $customer->ID,
            'items' => [],
            'expand' => [
                'latest_invoice',
            ],
            'metadata' => [
                'platform' => 'MemberPress Connect acct_1FIIDhKEEWtO8ZWC',
                'site_url' => get_site_url(),
                'ip_address' => $_SERVER['REMOTE_ADDR']
            ],
            'off_session' => 'true'
        ], $sub);

        // Specifically set a default_payment_method on the subscription
        if (!empty($customer->invoice_settings['default_payment_method']['id'])) {
            $args = array_merge(['default_payment_method' => $customer->invoice_settings['default_payment_method']['id']], $args);
        }

        if ($sub->trial) {
            $args = array_merge(['trial_period_days' => $sub->trial_days], $args);
        }
        $sub->store();
        $this->email_status("process_resume_subscription: \n" . MeprUtils::object_to_string($sub) . "\n", $this->settings->debug);

        $_REQUEST['data'] = $customer;
        $_REQUEST['sub'] = $sub;

        return $this->record_resume_subscription();
    }

    /** This method should be used by the class to record a successful resuming of
     * as subscription from the gateway.
     */
    public function record_resume_subscription()
    {
        if (isset($_REQUEST['data'], $_REQUEST['sub'])) {
            $customer = (object)$_REQUEST['data'];

            $sub = $_REQUEST['sub'];
            if ($sub instanceof MeprSubscription) {
                $sub->status = MeprSubscription::$active_str;
                $sub->store();
                //Check if prior txn is expired yet or not, if so create a temporary txn so the user can access the content immediately
                $prior_txn = $sub->latest_txn();
                if ($prior_txn == false || !($prior_txn instanceof MeprTransaction) || strtotime($prior_txn->expires_at) < time()) {
                    $txn = new MeprTransaction();
                    $txn->subscription_id = $sub->id;
                    $txn->trans_num = $sub->subscr_id . '-' . uniqid();
                    $txn->status = MeprTransaction::$confirmed_str;
                    $txn->txn_type = MeprTransaction::$subscription_confirmation_str;
                    $txn->expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days(0), 'Y-m-d 23:59:59');
                    $txn->set_subtotal(0.00); // Just a confirmation txn
                    $txn->store();
                }

                MeprUtils::send_resumed_sub_notices($sub);
                return array('subscription' => $sub, 'transaction' => (isset($txn)) ? $txn : $prior_txn);
            }
        }
        return false;
    }

    /** Used to cancel a subscription by the given gateway. This method should be used
     * by the class to record a successful cancellation from the gateway. This method
     * should also be used by any IPN requests or Silent Posts.
     */
    public function process_cancel_subscription($sub_id)
    {
        error_log(__METHOD__);
        $sub = new MeprSubscription($sub_id);
        if ($sub->status == MeprSubscription::$cancelled_str || $sub->status == MeprSubscription::$suspended_str) {
            throw new MeprGatewayException(__('This subscription has already been cancelled.', 'memberpress'));
        }
        $args = MeprHooks::apply_filters('mepr_alepay_cancel_subscription_args', [], $sub);
        $this->initialize_payment_api();
        $this->alepayAPI->sendCardLinkRequest($sub->token);
        $_REQUEST['data'] = $sub;
        return $this->record_cancel_subscription();
    }

    /** This method should be used by the class to record a successful cancellation
     * from the gateway. This method should also be used by any IPN requests or
     * Silent Posts.
     */
    public function record_cancel_subscription()
    {
        if (isset($_REQUEST['data'])) {
            $subscription = (object)$_REQUEST['data'];
            $sub = MeprSubscription::get_one_by_subscr_id($subscription->id);
            $subscription->status = MeprSubscription::$cancelled_str;
            $subscription->store();
            MeprUtils::send_cancelled_sub_notices($subscription);
        }

        return false;
    }

    /** This gets called on the 'init' hook when the signup form is processed ...
     * this is in place so that payment solutions like paypal can redirect
     * before any content is rendered.
     */
    public function process_signup_form($txn)
    {
        error_log(__METHOD__);
        if ($txn->amount <= 0.00) {
            MeprTransaction::create_free_transaction($txn);
            return;
        }

        return false;
    }

    public function display_payment_page($txn)
    {
        // Nothing to do here ...

    }

    /** This gets called on wp_enqueue_script and enqueues a set of
     * scripts for use on the page containing the payment formx
     */
    public function enqueue_payment_form_scripts()
    {
        //not have any scripts to equeue

        // wp_register_script('alepay-js-controller', assets_url('/raw/js/alepay.js', __FILE__), array('jquery'), null, true);
        // wp_localize_script('alepay-js-controller', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
        // wp_enqueue_script('alepay-js-controller');


    }

    public function update_card_link_request($sub_id)
    {
        $this->initialize_payment_api();
        error_log(__METHOD__);
        $data = isset($_REQUEST['data']) ? $_REQUEST['data'] : null;
        if (isset($data)) {
            $result = $this->alepayAPI->decryptCallbackData($data);
            $tokenization_payment = json_decode($result, true);
            if ($tokenization_payment['errorCode'] == '000') {
                if ($tokenization_payment['cancel'] == true) {
                    return false;
                } else {
                    $card_link_status = $tokenization_payment['data']['data']['cardLinkStatus'];
                    $email = $tokenization_payment['data']['email'];
                    $customer_id = $tokenization_payment['data']['customerId'];
                    $card_number = $tokenization_payment['data']['cardNumber'];
                    $card_holder_name = $tokenization_payment['data']['cardHolderName'];
                    $card_expire_month = $tokenization_payment['data']['cardExpireMonth'];
                    $card_expire_year = $tokenization_payment['data']['cardExpireYear'];
                    $payment_method = $tokenization_payment['data']['paymentMethod'];
                    $bank_code = $tokenization_payment['data']['bankCode'];
                    $token = $tokenization_payment['data']['token'];
                    $cc_last4 = substr($card_number, strlen($card_number) - 4, 4);
                    $sub = new MeprSubscription($sub_id);
                    $sub->cc_last4 = $cc_last4;
                    $sub->cc_exp_month = $card_expire_month;
                    $sub->cc_exp_year = $card_expire_year;
                    $sub->created_at = gmdate('c');
                    $sub->token = $token;
                    $sub->store();
                    return $sub;
                }
            } else {
                throw new MeprGatewayException(__('Update was unsuccessful, please check your payment details and try again.', 'memberpress'));
            }
        }
        return false;
    }


    public function record_card_link_request($txn_id)
    {
        $this->initialize_payment_api();
        error_log(__METHOD__);
        $data = isset($_REQUEST['data']) ? $_REQUEST['data'] : null;
        if (isset($data)) {
            $result = $this->alepayAPI->decryptCallbackData($data);
            $tokenization_payment = json_decode($result, true);
            if ($tokenization_payment['errorCode'] == '000') {
                if ($tokenization_payment['cancel'] == true) {
                    return false;
                } else {
                    $card_link_status = $tokenization_payment['data']['data']['cardLinkStatus'];
                    $email = $tokenization_payment['data']['email'];
                    $customer_id = $tokenization_payment['data']['customerId'];
                    $card_number = $tokenization_payment['data']['cardNumber'];
                    $card_holder_name = $tokenization_payment['data']['cardHolderName'];
                    $card_expire_month = $tokenization_payment['data']['cardExpireMonth'];
                    $card_expire_year = $tokenization_payment['data']['cardExpireYear'];
                    $payment_method = $tokenization_payment['data']['paymentMethod'];
                    $bank_code = $tokenization_payment['data']['bankCode'];
                    $token = $tokenization_payment['data']['token'];
                    $cc_last4 = substr($card_number, strlen($card_number) - 4, 4);
                    $txn = new MeprTransaction($txn_id);
                    $prd = $txn->product();
                    $usr = $txn->user();
                    $sub = $txn->subscription();
                    $sub->product_id = $prd->ID;
                    $sub->user_id = $usr->ID;
                    $sub->cc_last4 = $cc_last4;
                    $sub->cc_exp_month = $card_expire_month;
                    $sub->cc_exp_year = $card_expire_year;
                    $sub->created_at = gmdate('c');
                    $sub->token = $token;
                    $sub->store();
                    return $sub;
                }
            } else {
                throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
            }
        }
        return false;
    }

    public function request_tokenization_payment($sub)
    {
        error_log(__METHOD__);
        $raw = json_decode(get_transient('raw_data'));
        $data_forpayment['customerToken'] = $sub->token;
        $data_forpayment['orderCode'] = $raw->orderCode;
        $data_forpayment['amount'] = $raw->amount;
        $data_forpayment['currency'] = $raw->currency;
        $data_forpayment['orderDescription'] = $raw->orderDescription;
        $data_forpayment['returnUrl'] = $raw->returnUrl . '&oneClickSuccess=1';
        $data_forpayment['cancelUrl'] = $raw->cancelUrl . '&oneClickCancel=1';
        $data_forpayment['paymentHours'] = '1';
        $this->initialize_payment_api();
        $result = $this->alepayAPI->sendTokenizationPayment($data_forpayment);
        if (is_object($result)) {
            $token = $result->token;
            $checkout_url = $result->checkoutUrl;
            MeprUtils::wp_redirect($checkout_url);
        } else {
            throw new MeprGatewayException($result['errorDescription']);
        }
    }

    public function request_one_click_success($txn_id)
    {
        error_log(__METHOD__);
        $data = isset($_REQUEST['data']) ? $_REQUEST['data'] : null;

        if (isset($data)) {
            $this->initialize_payment_api();
            $result = $this->alepayAPI->decryptCallbackData($data);
            $data = json_decode($result, true);

            return $data;
        }

        return false;
    }

    public function handle_one_click_success($txn_id)
    {

        $result = $this->request_one_click_success($txn_id);

        if (is_array($result)) {

            if ($result['errorCode'] == '000') {

                if (!$result['cancel']) {

                    $transaction_code = $result['data'];

                    // Get transaction info
                    $this->initialize_payment_api();

                    $result = $this->alepayAPI->getTransactionInfo($transaction_code);

                    if (!is_object($result)) {
                        $txn = new MeprTransaction($txn_id);
                        MeprUtils::send_failed_txn_notices($txn);
                        throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
                    }

                    // Active subscription
                    $_REQUEST['data'] = $result;
                    $transaction = $this->record_create_subscription();

                    if (gettype($transaction) == 'array') {

                        $txn = new MeprTransaction($txn_id);

                        $sub = $txn->subscription();
                        $sub->add_meta('payment_method', 'one_click_payment');
                        $this->activate_subscription($txn, $sub);

                        $mepr_options = MeprOptions::fetch();


                        $product = new MeprProduct($txn->product_id);
                        $sanitized_title = sanitize_title($product->post_title);

                        $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                        if ($txn->subscription_id > 0) {
                            $sub = $txn->subscription();
                            $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
                        }

                        // Redirect to thank you page
                        MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
                    } else {
                        // TODO: Transaction created failed
                        return;
                    }
                } else {
                    // User cancel
                    $txn = new MeprTransaction($txn_id);
                    MeprUtils::send_failed_txn_notices($txn);
                    echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
                    echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
                    return;
                }
            } else {
                $txn = new MeprTransaction($txn_id);
                MeprUtils::send_failed_txn_notices($txn);
                echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
                echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
                return;
            }
        } else {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
            echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
            return;
        }
    }

    public function handle_one_click_cancel($txn_id)
    {
        $txn = new MeprTransaction($txn_id);
        MeprUtils::send_failed_txn_notices($txn);
        echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
        echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
        exit;
    }

    public function handle_international_return($txn_id)
    {
        $this->initialize_payment_api();
        $decryptedData = $this->alepayAPI->decryptCallbackData($_REQUEST['data']);

        $decryptedData = json_decode($decryptedData);

        $alepay_transaction_code = null;
        // Activate subscription
        $txn = new MeprTransaction($txn_id);

        $sub = $txn->subscription();

        if ($decryptedData->errorCode == '000' && !$decryptedData->cancel) {

            // Thanh toán không kèm liên kết thẻ
            if (is_string($decryptedData->data)) {
                $alepay_transaction_code = $decryptedData->data;
                $sub->add_meta('payment_method', 'international-none-link');
            }
            // Thanh toán kèm liên kết the
            else {
                $alepay_token = $decryptedData->data->alepayToken;
                $alepay_transaction_code = $decryptedData->data->transactionCode;
                $card_link_code = $decryptedData->data->cardLinkCode;
                $sub->add_meta('payment_method', 'international');

                $sub->token = $alepay_token;
            }
        }

        if (!$alepay_transaction_code) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        // Get transaction info
        $this->initialize_payment_api();

        $transaction_info = $this->alepayAPI->getTransactionInfo($alepay_transaction_code);

        if (!is_object($transaction_info)) {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        // Record transaction
        $_REQUEST['data'] = $transaction_info;
        $transaction = $this->record_create_subscription();

        if (!gettype($transaction) == 'array') {
            throw new MeprGatewayException(__('Error when recording new transaction.', 'memberpress'));
        }


        $this->activate_subscription($txn, $sub);

        $mepr_options = MeprOptions::fetch();

        $product = new MeprProduct($txn->product_id);
        $sanitized_title = sanitize_title($product->post_title);

        $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
        if ($txn->subscription_id > 0) {
            $sub = $txn->subscription();
            $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
        }
        // Redirect to thank you page
        MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
    }

    /**
     * @throws MeprGatewayException
     */
    public function handle_card_link_request($txn_id)
    {
        $sub = $this->record_card_link_request($txn_id);

        if (!is_object($sub)) {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        $this->request_tokenization_payment($sub);
    }

    public function handle_recurring_onetime_domestic($txn_id)
    {
        error_log(__METHOD__);
        // Store transaction
        $transaction = new MeprTransaction($txn_id);
        $transaction->status = MeprTransaction::$complete_str;
        $transaction->store();
        // Redirect to thank you page
        $mepr_options = MeprOptions::fetch();

        $product = new MeprProduct($transaction->product_id);
        $sanitized_title = sanitize_title($product->post_title);

        $query_params = array('membership' => $sanitized_title, 'trans_num' => $transaction->trans_num, 'membership_id' => $product->ID);

        MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
    }

    public function handle_recurring_onetime_international($txn_id)
    {
        $transaction = new MeprTransaction($txn_id);
        $transaction->store();

        // Send email
        MeprUtils::send_transaction_receipt_notices($transaction);
        // Redirect to thank you page
        $mepr_options = MeprOptions::fetch();

        $product = new MeprProduct($transaction->product_id);
        $sanitized_title = sanitize_title($product->post_title);

        $query_params = array('membership' => $sanitized_title, 'trans_num' => $transaction->trans_num, 'membership_id' => $product->ID);

        MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
    }

    public function handle_domestic($token_key, $transaction_code, $txn_id)
    {

        $data['tokenKey'] = $token_key;
        $data['transactionCode'] = $transaction_code;
        $this->initialize_payment_api();

        $result = $this->alepayAPI->getTransactionInfo($transaction_code);

        if (!is_object($result)) {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        } else {
            $_REQUEST['data'] = $result;
            $transaction = $this->record_create_subscription();
            if (gettype($transaction) == 'array') {
                $txn = new MeprTransaction($txn_id);
                $sub = $txn->subscription();
                $this->activate_subscription($txn, $sub);
                $mepr_options = MeprOptions::fetch();
                $product = new MeprProduct($txn->product_id);
                $sanitized_title = sanitize_title($product->post_title);

                $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                if ($txn->subscription_id > 0) {
                    $sub = $txn->subscription();
                    $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
                }
                MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
            }
        }
    }

    public function render_payment_form($amount, $user, $product_id, $txn_id)
    {
        $mepr_options = MeprOptions::fetch();
        $prd = new MeprProduct($product_id);
        $coupon = false;
        $txn = new MeprTransaction($txn_id);

        $is_one_time_product = false;
        if ($prd->period_type == 'lifetime') {
            $is_one_time_product = true;
        }

        //Artifically set the price of the $prd in case a coupon was used
        if ($prd->price != $amount) {
            $coupon = true;
            $prd->price = $amount;
        }

        $invoice = MeprTransactionsHelper::get_invoice($txn);
        echo $invoice;
?>
        <div class="mp_wrapper mp_payment_form_wrapper">
            <div class="mp_wrapper mp_payment_form_wrapper">
                <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
                <form action="" method="post" id="mepr_alepay_payment_form" name="mepr_alepay_payment_form" class="mepr-checkout-form mepr-form mepr-card-form" novalidate>
                    <input type="hidden" name="mepr_process_payment_form" value="Y" />
                    <input type="hidden" name="mepr_transaction_id" value="<?php echo $txn->id; ?>" />
                    <?php MeprHooks::do_action('mepr-paystack-payment-form', $txn); ?>
                    <div class="mepr_spacer">&nbsp;</div>
                    <label class="mepr-buyer-first-name"><?php echo __('First name', 'alepay-gateway') . ' (*)' ?></label>
                    <input type=text name="mepr-buyer-first-name" value="<?php echo $user->first_name ?>">
                    <label class="mepr-buyer-last-name"><?php echo __('Last name', 'alepay-gateway') . ' (*)' ?></label>
                    <input type=text name="mepr-buyer-last-name" value="<?php echo $user->last_name ?>">
                    <label class="mepr-buyer-email"><?php echo __('Email', 'alepay-gateway') . ' (*)' ?></label>
                    <input type="email" name="mepr-buyer-email" value="<?php echo $user->user_email ?>">
                    <label class="mepr-buyer-phone"> <?php echo __('Phone', 'alepay-gateway') . '(*)' ?></label>
                    <?php $phone = get_user_meta($user->ID, 'billing_phone', true); ?>
                    <input type="text" name="mepr-buyer-phone" value="<?php echo $phone ?>">
                    <label class="mepr-buyer-address"><?php echo __('Address', 'alepay-gateway') . ' (*)' ?></label>
                    <?php $address = get_user_meta($user->ID, 'billing_address_1', true); ?>
                    <input type="text" name="mepr-buyer-address" value="<?php echo $address ?>">
                    <label class="mepr-buyer-city"><?php echo __('City', 'alepay-gateway') . ' (*)' ?></label>
                    <?php $city = get_user_meta($user->ID, 'billing_city', true); ?>
                    <input type="text" name="mepr-buyer-city" value="<?php echo $city ?>">

                    <label class="mepr-buyer-state"><?php echo __('State', 'alepay-gateway') . ' (*)' ?></label>
                    <?php $state = get_user_meta($user->ID, 'billing_state', true); ?>
                    <input type="text" name="mepr-buyer-state" value="<?php echo $state ?>">

                    <label class="mepr-buyer-postal-code">Postal Code (*)</label>
                    <?php $pcode = get_user_meta($user->ID, 'billing_post_code', true); ?>
                    <input type="text" name="mepr-buyer-postal-code" value="<?php echo $pcode ?>">
                    <?php $country = get_user_meta($user->ID, 'billing_country', true); ?>
                    <label class="mepr-buyer-country"><?php echo __('Country', 'alepay-gateway') . ' (*)' ?></label>
                    <input type="text" name="mepr-buyer-country" value="<?php echo $country ?>">
                    <label class="mepr-buyer-des"><?php echo __('Description', 'alepay-gateway') . ' (*)' ?></label>
                    <input type="text" name="mepr-buyer-des">
                    <label><?php echo '(*) ' . __('Data fields cannot be left blank', 'alepay-gateway') ?></label>
                    <span><?php echo __('Select payment type', 'alepay-gateway') ?></span>

                    <?php if ($is_one_time_product) : ?>

                        <br />
                        <input type="radio" id="one-time-domestic" name="alepay_payment_type" value="one-time-domestic" checked>
                        <label for="one-time-domestic"><?php echo __('Domestic: ATM, IB, QRCODE', 'alepay-gateway') ?></label>
                        <br />
                        <input type="radio" id="one-time-international" name="alepay_payment_type" value="one-time-international">
                        <label for="one-time-international"><?php echo __('International: VISA, MasterCard', 'alepay-gateway') ?></label>

                    <?php else : ?>
                        <br />
                        <input type="radio" id="domestic" name="alepay_payment_type" value="domestic" checked>
                        <label for="domestic"><?php echo __('Domestic: ATM, IB, QRCODE', 'alepay-gateway') ?></label>
                        <br />
                        <input type="radio" id="one_click_payment" name="alepay_payment_type" value="one_click_payment">
                        <label for="one_click_payment"><?php echo __('One Click Payment', 'aleapy-gateway') ?></label>
                        <br />
                        <input type="radio" id="international" name="alepay_payment_type" value="international">
                        <label for="international"><?php echo __('International: VISA, MasterCard...', 'alepay-gateway') ?></label>
                        <br />
                        <div id="card-link-container" style="display: none">
                            <input type="checkbox" id="is-card-link" name="is-card-link">
                            <label for="is-card-link"><?php echo __('I agree to link my card to the website') ?></label>
                        </div>
                    <?php endif; ?>

                    <input type="submit" class="mepr-submit" value="<?php _e('Pay Now', 'memberpress'); ?>" />

                    <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
                    <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
                </form>
            </div>
        </div>

    <?php

    }

    /** This gets called on the_content and just renders the payment form
     * @throws MeprGatewayException
     */
    public function display_payment_form($amount, $user, $product_id, $txn_id)
    {
        error_log(__METHOD__);

        if (isset($_REQUEST['action'])) {
            $action = $_REQUEST['action'];
            if ($action == 'checkout') {
                $txn_id = $_REQUEST['txn'];
            }
        }

        // Recurring: One time domestic
        $recurring_onetime_domestic = $_REQUEST['onetimeDomesticResult'] ?? null;

        // Recurring: One time international
        $recurring_onetime_international = $_REQUEST['onetimeInternationalResult'] ?? null;

        //one-payment-return success
        $one_click_success = $_REQUEST['oneClickSuccess'] ?? null;

        //one-payment-return cancel
        $one_click_cancel = $_REQUEST['oneClickCancel'] ?? null;

        // internation-payment return
        $international_result = isset($_REQUEST['internationalResult']) ? $_REQUEST['internationalResult'] : null;

        //link card trả về (thành công + thất bại)
        $card_link_request = isset($_REQUEST['cardLinkRequest']) ? $_REQUEST['cardLinkRequest'] : null;

        if (isset($card_link_request)) {
            $this->handle_card_link_request($txn_id);
        }

        if (isset($one_click_success)) {
            $this->handle_one_click_success($txn_id);
        }

        if (isset($one_click_cancel)) {
            $this->handle_one_click_cancel($txn_id);
        }

        if (isset($international_result) and $international_result == '1') {
            $this->handle_international_return($txn_id);
        }

        if (isset($recurring_onetime_domestic) and $recurring_onetime_domestic == '1') {
            $this->handle_recurring_onetime_domestic($txn_id);
        }

        if (isset($recurring_onetime_international) and $recurring_onetime_international == '1') {
            $this->handle_recurring_onetime_international($txn_id);
        }

        //cancel url của thanh toán thường
        $go_back = isset($_REQUEST['domesticResultUserCancel']) ? $_REQUEST['domesticResultUserCancel'] : null;
        $token_key = isset($_REQUEST['tokenKey']) ? $_REQUEST['tokenKey'] : null;
        $transaction_code = isset($_REQUEST['transactionCode']) ? $_REQUEST['transactionCode'] : null;
        $error_code = isset($_REQUEST['errorCode']) ? $_REQUEST['errorCode'] : null;

        //url cancel?
        $cancel = isset($_REQUEST['cancel']) ? $_REQUEST['cancel'] : null;

        if (isset($go_back)) {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
            echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
        } else if ($error_code != '000') {
            $this->render_payment_form($amount, $user, $product_id, $txn_id);
        } else if (!$cancel) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        } else {
            $this->handle_domestic($token_key, $transaction_code, $txn_id);
        }
    }


    /** Validates the payment form before a payment is processed */
    public function validate_payment_form($errors)
    {
        // This is done in the javascript with Alepay
        return $errors;
    }

    /** Displays the form for the given payment gateway on the MemberPress Options page */
    public function display_options_form()
    {
        // nothing in here
    }

    /** Validates the form for the given payment gateway on the MemberPress Options page */
    public function validate_options_form($errors)
    {
        return $errors;
    }

    /** This gets called on wp_enqueue_script and enqueues a set of
     * scripts for use on the front end user account page.
     */
    public function enqueue_user_account_scripts()
    {
        //nothing in here
    }


    public function render_update_form($user, $sub, $mepr_options)
    {
        error_log(__METHOD__);
        $payment_method = $sub->get_meta('payment_method', true);
    ?>
        <div class="mp_wrapper">
            <form action="" method="post" id="mepr-alepay-payment-form" data-sub-id="<?php echo esc_attr($sub->id); ?>">
                <input type="hidden" name="_mepr_nonce" value="<?php echo wp_create_nonce('mepr_process_update_account_form'); ?>" />
                <input type="hidden" name="address_required" value="<?php echo $mepr_options->show_address_fields && $mepr_options->require_address_fields ? 1 : 0 ?>" />
                <input type="hidden" name="alepay_payment_type" value="<?php echo $payment_method ?>" />
                <div class="mepr_update_account_table">

                    <div class="mepr-alepay-errors"></div>
                    <?php MeprView::render('/shared/errors', get_defined_vars()); ?>
                    <div class="mp-form-row">
                        <div class="mp-form-label">

                            <div id="card-element" class="mepr-alepay-card-element">
                                <label class="mepr-buyer-first-name"><?php _e('First name', 'alepay-gateway') . ' (*)' ?></label>
                                <input type=text name="mepr-buyer-first-name" value="<?php echo $user->first_name ?>">
                                <label class="mepr-buyer-last-name"><?php echo __('Last name', 'alepay-gateway') . ' (*)' ?></label>
                                <input type=text name="mepr-buyer-last-name" value="<?php echo $user->last_name ?>">
                                <label class="mepr-buyer-email"><?php echo __('Email', 'alepay-gateway') . ' (*)' ?></label>
                                <input type="email" name="mepr-buyer-email" value="<?php echo $user->user_email ?>">
                                <label class="mepr-buyer-phone"> <?php echo __('Phone', 'alepay-gateway') . '(*)' ?></label>
                                <?php $phone = get_user_meta($user->ID, 'billing_phone', true); ?>
                                <input type="text" name="mepr-buyer-phone" value="<?php echo $phone ?>">
                                <label class="mepr-buyer-address"><?php echo __('Address', 'alepay-gateway') . ' (*)' ?></label>
                                <?php $address = get_user_meta($user->ID, 'billing_address_1', true); ?>
                                <input type="text" name="mepr-buyer-address" value="<?php echo $address ?>">
                                <label class="mepr-buyer-city"><?php echo __('City', 'alepay-gateway') . ' (*)' ?></label>
                                <?php $city = get_user_meta($user->ID, 'billing_city', true); ?>
                                <input type="text" name="mepr-buyer-city" value="<?php echo $city ?>">

                                <label class="mepr-buyer-state"><?php echo __('State', 'alepay-gateway') . ' (*)' ?></label>
                                <?php $state = get_user_meta($user->ID, 'billing_state', true); ?>
                                <input type="text" name="mepr-buyer-state" value="<?php echo $state ?>">

                                <label class="mepr-buyer-postal-code">Postal Code (*)</label>
                                <?php $pcode = get_user_meta($user->ID, 'billing_post_code', true); ?>
                                <input type="text" name="mepr-buyer-postal-code" value="<?php echo $pcode ?>">
                                <?php $country = get_user_meta($user->ID, 'billing_country', true); ?>
                                <label class="mepr-buyer-country"><?php echo __('Country', 'alepay-gateway') . ' (*)' ?></label>
                                <input type="text" name="mepr-buyer-country" value="<?php echo $country ?>">
                                <label class="mepr-buyer-des"><?php echo __('Description', 'alepay-gateway') . ' (*)' ?></label>
                                <input type="text" name="mepr-buyer-des">
                                <label><?php echo '(*) ' . __('Data fields cannot be left blank', 'alepay-gateway') ?></label>
                                <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
                            </div>
                        </div>

                        <div class="mepr_spacer">&nbsp;</div>
                        <input type="submit" class="mepr-submit" value="<?php _ex('Submit', 'ui', 'memberpress'); ?>" />
                        <img src="<?php echo admin_url('images/loading.gif'); ?>" alt="<?php _e('Loading...', 'memberpress'); ?>" style="display: none;" class="mepr-loading-gif" />

                        <noscript>
                            <p class="mepr_nojs"><?php _e('Javascript is disabled in your browser. You will not be able to complete your purchase until you either enable JavaScript in your browser, or switch to a browser that supports it.', 'memberpress'); ?></p>
                        </noscript>
                    </div>
            </form>
        </div>
<?php
    }


    /** Displays the update account form on the subscription account page **/
    public function display_update_account_form($sub_id, $errors = array(), $message = '')
    {
        error_log(__METHOD__);

        if (isset($_REQUEST['action'])) {
            $action = $_REQUEST['action'];
            if ($action == 'checkout') {
                $txn_id = $_REQUEST['txn'];
            }
        }

        // internation-payment return
        $international_result = isset($_REQUEST['internationalResult']) ? $_REQUEST['internationalResult'] : null;

        //link card trả về (thành công + thất bại)
        $card_link_request = isset($_REQUEST['cardLinkRequest']) ? $_REQUEST['cardLinkRequest'] : null;

        if (isset($card_link_request)) {
            $sub = $this->update_card_link_request($sub_id);
            if (is_object($sub)) {
                $url = get_site_url() . 'account/?action=subscriptions';
                MeprUtils::wp_redirect($url);
            }
        }

        if (isset($international_result) && $international_result == '1') {
            $this->handle_international_return($txn_id);
        }

        //cancel url của thanh toán thường
        $domestic_result_cancel = isset($_REQUEST['domesticResultUserCancel']) ? $_REQUEST['domesticResultUserCancel'] : null;
        $token_key = isset($_REQUEST['tokenKey']) ? $_REQUEST['tokenKey'] : null;
        $transaction_code = isset($_REQUEST['transactionCode']) ? $_REQUEST['transactionCode'] : null;
        $error_code = isset($_REQUEST['errorCode']) ? $_REQUEST['errorCode'] : null;

        //url cancel?
        $cancel = isset($_REQUEST['cancel']) ? $_REQUEST['cancel'] : null;

        if (isset($domestic_result_cancel)) {
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            echo '<h1>' . __('Payment was unsuccessful, please check your payment details and try again.', 'memberpress') . '</h1>';
            echo '<a> href = "' . get_site_url() . '" ' . __('Go home') . '</a>';
        } else if ($error_code != '000') {
            $mepr_options = MeprOptions::fetch();
            $sub = new MeprSubscription($sub_id);
            $user = $sub->user();
            $this->render_update_form($user, $sub, $mepr_options);
        } else if (!$cancel) {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        } else {

            $data['tokenKey'] = $token_key;
            $data['transactionCode'] = $transaction_code;
            $this->initialize_payment_api();

            $result = $this->alepayAPI->getTransactionInfo($transaction_code);

            if (!is_object($result)) {
                $txn = new MeprTransaction($txn_id);
                MeprUtils::send_failed_txn_notices($txn);
                throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
            } else {
                $_REQUEST['data'] = $result;
                $transaction = $this->record_create_subscription();
                if (gettype($transaction) == 'array') {
                    $txn = new MeprTransaction($txn_id);
                    $sub = $txn->subscription();
                    $sub->add_meta('payment_method', 'domistic');
                    $this->activate_subscription($txn, $sub);
                    $mepr_options = MeprOptions::fetch();
                    $product = new MeprProduct($txn->product_id);
                    $sanitized_title = sanitize_title($product->post_title);
                    $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                    if ($txn->subscription_id > 0) {
                        $sub = $txn->subscription();
                        $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
                    }
                    MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
                }
            }
        }
    }

    /** Validates the payment form before a payment is processed */
    public function validate_update_account_form($errors = array())
    {
        return $errors;
    }

    /** Used to update the credit card information on a subscription by the given gateway.
     * This method should be used by the class to record a successful cancellation from
     * the gateway. This method should also be used by any IPN requests or Silent Posts.
     */
    public function process_update_account_form($sub_id)
    {
        error_log(__METHOD__);
        $sub = new MeprSubscription($sub_id);
        $user = $sub->user();
        $txn = new MeprTransaction();
        $txn->subscription_id = $sub_id;
        $txn->user_id = $user->ID;
        // $this->process_create_subscription($txn);
        $this->process_update_subscription($sub_id);
    }

    /** Returns boolean ... whether or not we should be sending in test mode or not */
    public function is_test_mode()
    {

        return (isset($this->settings->test_mode) && $this->settings->test_mode);
    }

    public function force_ssl()
    {
        return (isset($this->settings->force_ssl) and ($this->settings->force_ssl == 'on' or $this->settings->force_ssl == true));
    }

    public function initialize_payment_api()
    {
        if (!isset($this->alepayAPI)) {
            error_log(__METHOD__);

            $args = [
                'apiKey' => $this->settings->api_key,
                'encryptKey' => $this->settings->encrypt_key,
                'checksumKey' => $this->settings->checksum_key,
                'base_urls' => $this->settings->base_urls,
                'is_test_mode' => $this->settings->test_mode,
                'callbackUrl' => 'callbackUrl',
            ];
            $this->alepayAPI = new Alepay($args);
        }
    }
}

?>