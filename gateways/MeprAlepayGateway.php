<?php
ob_start();
error_reporting(0);

require_once __DIR__ . '../../lib/Alepay.php';
require_once __DIR__ . '../../utils/AleConfiguration.php';

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
        $this->name = __("Alepay", 'memberpress');
        $this->icon = plugins_url('/alepay-gateway') . '/images/alepay.png';
        $this->desc = __('Thanh toán bằng cổng thanh toán Alepay', 'memberpress');
        $this->key = __('Alepay', 'memberpress');
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
            'cancel' => 'cancel_handler',
            'return' => 'return_handler'
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

        $encrypt_key = get_option(AleConfiguration::$ENCRYPT_KEY);
        $api_key = get_option(AleConfiguration::$API_KEY);
        $checksum_key = get_option(AleConfiguration::$CHECKSUM_KEY);
        $base_url_v3 = get_option(AleConfiguration::$BASE_URL_V3);
        $base_url_v1 = get_option(AleConfiguration::$BASE_URL_V1);
        $base_url_live = get_option(AleConfiguration::$BASE_URL_LIVE);
        $email = get_option(AleConfiguration::$EMAIL);
        $connected = get_option(AleConfiguration::$CONNECTED);
        $test_mode = get_option(AleConfiguration::$TEST_MODE);

        $this->settings = (object)array_merge(
            array(
                'gateway' => 'MeprAlepayGateway',
                'id' => $this->generate_id(),
                'label' => 'AlePay',
                'use_label' => true,
                'use_icon' => true,
                'use_desc' => true,
                'sandbox' => !empty($test_mode),
                'force_ssl' => false,
                'debug' => false,
                'test_mode' => !empty($test_mode),
                // 'alepay_checkout_enabled' => $model->get_checkout_enabled(),
                'churn_buster_enabled' => false,
                'churn_buster_uuid' => '',
                'connect_status' => !empty($connected),
                // 'service_account_id' => $model->get_service_account_id(),
                // 'service_account_name' => $model->get_service_account_name(),
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
        error_log('process_payment_form');
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
        if (isset($txn) and $txn instanceof MeprTransaction) {
            $usr = $txn->user();
            $prd = $txn->product();
        } else {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        $mepr_options = MeprOptions::fetch();

        $amount = MeprUtils::format_float(($txn->total), 0);

        // create the charge on Alepay's servers - this will charge the user's card
        $args = MeprHooks::apply_filters('mepr_alepay_payment_args', array(
            'amount' => $amount,
            'currency' => $mepr_options->currency_code,
            'description' => sprintf(__('%s (transaction: %s)', 'memberpress'), $prd->post_title, $txn->id),
            'metadata' => array(
                'platform' => 'MemberPress Connect acct_1FIIDhKEEWtO8ZWC',
                'transaction_id' => $txn->id,
                'site_url' => esc_url(get_site_url()),
                'ip_address' => $_SERVER['REMOTE_ADDR']
            )
        ), $txn);

        $this->email_status('Alepay Charge Happening Now ... ' . MeprUtils::object_to_string($args), $this->settings->debug);

        // $charge = (object)$this->send_stripe_request( 'charges', $args, 'post' );//
        $charge = new stdClass();
        $this->email_status('Alepay Charge: ' . MeprUtils::object_to_string($charge), $this->settings->debug);

        $txn->trans_num = $charge->id;
        $txn->store();

        $this->email_status('Alepay Charge Happening Now ... 2', $this->settings->debug);

        $_REQUEST['data'] = $charge;

        return $this->record_payment();
    }

    /** Used to record a successful recurring payment by the given gateway. It
     * should have the ability to record a successful payment or a failure. It is
     * this method that should be used when receiving an IPN from PayPal or a
     * Silent Post from Authorize.net.
     */
    public function record_subscription_payment()
    {
        error_log('record_subscription_payment');
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
        error_log('record_subscription_free_invoice_payment');

        return false;
    }


    /** Used to record a declined payment. */
    public function record_payment_failure()
    {
        error_log('record_payment_failure');

        // if (isset($_REQUEST['data'])) {
        //     $charge = (object)$_REQUEST['data'];
        //     $txn_res = MeprTransaction::get_one_by_trans_num($charge->id);

        //     if (is_object($txn_res) and isset($txn_res->id)) {
        //         $txn = new MeprTransaction($txn_res->id);
        //         $txn->status = MeprTransaction::$failed_str;
        //         $txn->store();
        //     } else {
        //         // Fetch expanded charge data from Alepay
        //         $args = [
        //             'expand' => [
        //                 'invoice'
        //             ]
        //         ];

        //         // $charge = (object) $this->send_stripe_request("charges/{$charge->id}", $args, 'get');
        //         $charge = new stdClass();

        //         $sub = isset($charge->invoice['subscription']) ? MeprSubscription::get_one_by_subscr_id($charge->invoice['subscription']) : null;

        //         if (!($sub instanceof MeprSubscription)) {
        //             // Look for an old cus_xxx subscription
        //             $sub = isset($charge->customer) ? MeprSubscription::get_one_by_subscr_id($charge->customer) : null;
        //         }

        //         if ($sub instanceof MeprSubscription) {
        //             $first_txn = $sub->first_txn();

        //             if ($first_txn == false || !($first_txn instanceof MeprTransaction)) {
        //                 $coupon_id = $sub->coupon_id;
        //             } else {
        //                 $coupon_id = $first_txn->coupon_id;
        //             }

        //             $txn = new MeprTransaction();
        //             $txn->user_id = $sub->user_id;
        //             $txn->product_id = $sub->product_id;
        //             $txn->coupon_id = $coupon_id;
        //             $txn->txn_type = MeprTransaction::$payment_str;
        //             $txn->status = MeprTransaction::$failed_str;
        //             $txn->subscription_id = $sub->id;
        //             $txn->trans_num = $charge->id;
        //             $txn->gateway = $this->id;

        //             // if(self::is_zero_decimal_currency()) {
        //             //   $txn->set_gross((float)$charge->amount);
        //             // }
        //             // else {
        //             //   $txn->set_gross((float)$charge->amount / 100);
        //             // }
        //             $txn->set_gross((float)$charge->amount / 100);
        //             $txn->store();

        //             //If first payment fails, Alepay will not set up the subscription, so we need to mark it as cancelled in MP
        //             if ($sub->txn_count == 0 && !($sub->trial && $sub->trial_amount == 0.00)) {
        //                 $sub->status = MeprSubscription::$cancelled_str;
        //             }

        //             $sub->gateway = $this->id;
        //             $sub->expire_txns(); //Expire associated transactions for the old subscription
        //             $sub->store();
        //         } else {
        //             return false; // Nothing we can do here ... so we outta here
        //         }
        //     }

        //     MeprUtils::send_failed_txn_notices($txn);

        // return $txn;
        // }

        return false;
    }

    /** Used to record a successful payment by the given gateway. It should have
     * the ability to record a successful payment or a failure. It is this method
     * that should be used when receiving an IPN from PayPal or a Silent Post
     * from Authorize.net.
     */
    public function record_payment($charge = null)
    {

        // error_log('record_payment');
        // $this->email_status("Starting record_payment: " . MeprUtils::object_to_string($_REQUEST), $this->settings->debug);

        // if (empty($charge)) {
        //     $charge = isset($_REQUEST['data']) ? (object)$_REQUEST['data'] : [];
        // } else {
        //     $charge = (object)$charge;
        // }

        // if (!empty($charge)) {
        //     $this->email_status("record_payment: \n" . MeprUtils::object_to_string($charge, true) . "\n", $this->settings->debug);
        //     $obj = MeprTransaction::get_one_by_trans_num($charge->id);

        //     if (is_object($obj) and isset($obj->id)) {
        //         $txn = new MeprTransaction();
        //         $txn->load_data($obj);
        //         $usr = $txn->user();

        //         // Just short circuit if the txn has already completed
        //         if ($txn->status == MeprTransaction::$complete_str)
        //             return;

        //         $txn->status = MeprTransaction::$complete_str;
        //         // This will only work before maybe_cancel_old_sub is run
        //         $upgrade = $txn->is_upgrade();
        //         $downgrade = $txn->is_downgrade();

        //         $event_txn = $txn->maybe_cancel_old_sub();
        //         $txn->store();

        //         $this->email_status("Standard Transaction\n" . MeprUtils::object_to_string($txn->rec, true) . "\n", $this->settings->debug);

        //         $prd = $txn->product();

        //         if ($prd->period_type == 'lifetime') {
        //             if ($upgrade) {
        //                 $this->upgraded_sub($txn, $event_txn);
        //             } else if ($downgrade) {
        //                 $this->downgraded_sub($txn, $event_txn);
        //             } else {
        //                 $this->new_sub($txn);
        //             }

        //             MeprUtils::send_signup_notices($txn);
        //         }

        //         MeprUtils::send_transaction_receipt_notices($txn);
        //         MeprUtils::send_cc_expiration_notices($txn);
        //     }
        // }

        error_log('Recort_payment');
        return false;
    }

    /** This method should be used by the class to record a successful refund from
     * the gateway. This method should also be used by any IPN requests or Silent Posts.
     */
    public function process_refund(MeprTransaction $txn)
    {
        error_log(__METHOD__);
        // $args = MeprHooks::apply_filters('mepr_alepay_refund_args', array(), $txn);
        // // $refund = (object)$this->send_stripe_request( "charges/{$txn->trans_num}/refund", $args );
        // $refund = new stdClass();
        // $this->email_status("Alepay Refund: " . MeprUtils::object_to_string($refund), $this->settings->debug);
        // $_REQUEST['data'] = $refund;
        return $this->record_refund();
    }

    /** This method should be used by the class to record a successful refund from
     * the gateway. This method should also be used by any IPN requests or Silent Posts.
     */
    public function record_refund()
    {
        error_log(__METHOD__);
        // if (isset($_REQUEST['data'])) {
        //     $charge = (object)$_REQUEST['data'];
        //     $obj = MeprTransaction::get_one_by_trans_num($charge->id);

        //     if (!is_null($obj) && (int)$obj->id > 0) {
        //         $txn = new MeprTransaction($obj->id);

        //         // Seriously ... if txn was already refunded what are we doing here?
        //         if ($txn->status == MeprTransaction::$refunded_str) {
        //             return $txn->id;
        //         }

        //         $txn->status = MeprTransaction::$refunded_str;
        //         $txn->store();

        //         MeprUtils::send_refunded_txn_notices($txn);

        //         return $txn->id;
        //     }
        // }

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

        // If trial amount is zero then we've got to make sure the confirmation txn lasts through the trial
        if ($sub->trial && $sub->trial_amount <= 0.00) {
            $expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($sub->trial_days), 'Y-m-d 23:59:59');
        } elseif (!$mepr_options->disable_grace_init_days && $mepr_options->grace_init_days > 0) {
            $expires_at = MeprUtils::ts_to_mysql_date(time() + MeprUtils::days($mepr_options->grace_init_days), 'Y-m-d 23:59:59');
        } else {
            $expires_at = $txn->created_at; // Expire immediately
        }

        $txn->trans_num = $sub->subscr_id;
        $txn->status = MeprTransaction::$confirmed_str;
        $txn->txn_type = MeprTransaction::$subscription_confirmation_str;
        $txn->expires_at = $expires_at;
        $txn->set_subtotal(0.0);
        error_log('Mepre Transaction Store ' . print_r($txn, true));
        $txn->store();
        MeprUtils::send_transaction_receipt_notices($txn);
    }


    /** Used to send subscription data to a given payment gateway. In gateways
     * which redirect before this step is necessary this method should just be
     * left blank.
     */
    public function process_create_subscription($txn)
    {

        error_log(__METHOD__);

        if (isset($txn) and $txn instanceof MeprTransaction) {
            $usr = $txn->user();
            $prd = $txn->product();
        } else {
            error_log('error not meprtrasaction');
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }

        $mepr_options = MeprOptions::fetch();
        $sub = $txn->subscription();
        $this->initialize_payment_api();
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

        $tmp_txn = new MeprTransaction();
        $tmp_txn->product_id = $prd->ID;
        $tmp_txn->user_id = $usr->ID;
        if($sub->trial){
            $tmp_txn->set_subtotal($sub->trial_amount);
        }else{
            $tmp_txn->set_subtotal($sub->total);
        }
    
        $amount = $sub->trial ? $sub->trial_amount : $sub->total;
        $des = isset($_REQUEST['mepr-buyer-des']) ? $_REQUEST['mepr-buyer-des'] : 'The order create by Buddy Press';
        $buyer_name = trim($_REQUEST['mepr-buyer-last-name']) . ' ' . trim($_REQUEST['mepr-buyer-first-name']);
        $data['cancelUrl'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]" . '&returnUrl=1';
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

        $data['returnUrl'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        $payment_type = $_REQUEST['alepay_payment_type'];
        set_transient( 'raw_data', json_encode($data), 60*60*12);

        if ($payment_type == 'international') {
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

            if ($isCardLink == 'on') {
                $data['isCardLink'] = true;
            } else {
                $data['isCardLink'] = false;
                unset($data['merchantSideUserId']);
                unset($data['buyerPostalCode']);
                unset($data['buyerState']);
            }

            $result = $this->alepayAPI->sendRequestOrderInternational($data);
            error_log(print_r($result, true));
            if (!is_object($result)) {
                $errorObject = json_encode($result);
                throw new MeprGatewayException(__($result->errorDescription, 'memberpress'));
            } else {
                echo 'sendOrderToAlepayDomestic success';
                error_log(print_r($data, true));

                // $decryptedData = $this->alepayAPI->decryptCallbackData($result->data);

                $checkout_url = $result->checkoutUrl;
                $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);
                MeprUtils::wp_redirect($checkout_url);
            }
        } else if ($payment_type == 'domestic') {

            $result = $this->alepayAPI->sendOrderToAlepayDomesticATM($data);
            if ($result->code != '000') {
                throw new MeprGatewayException(__($result->message, 'memberpress'));
            } else {
                echo 'sendOrderToAlepayDomestic success';
                $checkout_url = $result->checkoutUrl;
                $this->email_status("process_create_subscription: \n" . MeprUtils::object_to_string($txn) . "\n", $this->settings->debug);
                MeprUtils::wp_redirect($checkout_url);
            }
        } else {
            $this->cardLinkReqeust($txn);
        }
    }


    public function cardLinkReqeust($txn)
    {

        if (isset($txn) and $txn instanceof MeprTransaction) {
            $usr = $txn->user();
            $prd = $txn->product();
        } else {
            error_log('error not meprtrasaction');
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }
        $sub = $txn->subscription();
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
        $callback = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";
        $data['callback'] = $callback . '&cardLinkRequest=1';
        error_log('before data');
        error_log(print_r($data));
        
        $result = $this->alepayAPI->sendCardLinkRequest($data);
        if (isset($result->url)) {
            MeprUtils::wp_redirect($result->url);
        } else {
            throw new MeprGatewayException(__('Payment was unsuccessful, please check your payment details and try again.', 'memberpress'));
        }
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
            error_log(print_r($customer, true));
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
                error_log('in here' . print_r($txn, true));
                return array('subscription' => $sub, 'transaction' => $txn);
            }
        }
        error_log('in here return false');
        return false;
    }

    public function process_update_subscription($sub_id)
    {
        // This is handled via Ajax
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
        error_log($sub->status);

        if ($sub->status == MeprSubscription::$suspended_str) {
            error_log('This subscription has already been paused.');
            throw new MeprGatewayException(__('This subscription has already been paused.', 'memberpress'));
        }

        if (!MeprUtils::is_mepr_admin() && $sub->in_free_trial()) {
            error_log('Sorry, subscriptions cannot be paused during a free trial.');
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
        error_log(__METHOD__);
        if (isset($_REQUEST['data'])) {
            $sub = (object)$_REQUEST['data'];
            error_log('sub is instance of MeprSubscription');
            // Seriously ... if sub was already cancelled what are we doing here?
            if ($sub->status == MeprSubscription::$suspended_str) {
                error_log('return sub here');
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

        error_log('before if is expired');
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
                error_log('store 1');
                $sub->store();
            }
        } else {
            $sub->trial = true;
            $sub->trial_days = MeprUtils::tsdays(strtotime($sub->expires_at) - time());
            $sub->trial_amount = 0.00;
            error_log('store 2');
            $sub->store();
        }

        $customer = $sub->user();
        $prd = $sub->product();

        // $customer_id = $usr->get_stripe_customer_id($this->get_meta_gateway_id());
        error_log('get customer ' . print_r($customer, true));
        error_log(print_r($sub, true));

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

        error_log('before store and send email');
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
        error_log(__METHOD__);
        if (isset($_REQUEST['data'], $_REQUEST['sub'])) {
            $customer = (object)$_REQUEST['data'];

            $sub = $_REQUEST['sub'];

            error_log('sub' . print_r($sub, true));
            error_log('customer' . print_r($customer, true));
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
        error_log('return false');
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
        error_log(print_r($sub, true));
        if ($sub->status == MeprSubscription::$cancelled_str || $sub->status == MeprSubscription::$suspended_str) {
            throw new MeprGatewayException(__('This subscription has already been cancelled.', 'memberpress'));
        }
        $args = MeprHooks::apply_filters('mepr_alepay_cancel_subscription_args', [], $sub);
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
            // $sub = MeprSubscription::get_one_by_subscr_id($subscription->id);
            // error_log('set status to cancelled_str' . print_r($sub,true));
            $subscription->status = MeprSubscription::$cancelled_str;
            error_log('view status' . $subscription->status);
            $subscription->store();
            error_log('send_cancelled_sub_notices');
            MeprUtils::send_cancelled_sub_notices($subscription);
        }
        error_log('return false');
        return false;
    }

    /** This gets called on the 'init' hook when the signup form is processed ...
     * this is in place so that payment solutions like paypal can redirect
     * before any content is rendered.
     */
    public function process_signup_form($txn)
    {
        if ($txn->amount <= 0.00) {
            MeprTransaction::create_free_transaction($txn);
            return;
        }
        error_log(__METHOD__);
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

    public function record_card_link_request($txn_id)
    {
        $this->initialize_payment_api();
        error_log(__METHOD__);
        $data = isset($_REQUEST['data']) ? $_REQUEST['data'] : null;
        if (isset($data)) {
            error_log(print_r($data, true));
            $result = $this->alepayAPI->decryptCallbackData($data);
            $tokenization_payment = json_decode($result, true);
            if ($tokenization_payment['errorCode'] == '000') {
                if ($tokenization_payment['cancel'] == true) {
                    //Người dùng huỷ liên hết thẻ
                    error_log('nguowif dunfg huy lien ket the');
                } else {
                    error_log('Nguoi dung lien ket thanh cong '.print_r($tokenization_payment,true));
                    //Người dùng liên kết thẻ thành công
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
                    $cc_last4 = substr($card_number,strlen($card_number)-4,4);
                    $txn = new MeprTransaction($txn_id);
                    $sub = $txn->subscription();
                    $sub->cc_last4 = $cc_last4;
                    $sub->cc_exp_month = $card_expire_month;
                    $sub->cc_exp_year = $card_expire_year;
                    $sub->created_at = gmdate('c');
                    if(empty($sub->get_meta('udoo_alepay_token'))){
                        error_log('meta empty');
                        $sub->add_meta('udoo_alepay_token', $token);
                    }else{
                        $sub->update_meta('udoo_alepay_token', $token);
                    }
                    $sub->update_meta('udooo_alepay_customer_name', $card_holder_name);
                    $sub->update_meta('udooo_alepay_customer_email', $email);
                    $sub->update_meta('udooo_alepay_payment_method', $payment_method);
                    $sub->update_meta('udooo_alepay_bank_code', $bank_code);
                    $sub->store();
                    return $sub;
                }
            } else {
                throw new MeprGatewayException(__('Thanh toán không thành công vui lòng thử lại', 'memberpress'));
            }
        }
        return false;
    }

    public function request_tokenization_payment($sub){
        error_log(__METHOD__);
        $raw = json_decode(get_transient('raw_data'));
        $data_forpayment['customerToken'] = $sub->get_meta('udoo_alepay_token',true);
        $data_forpayment['orderCode'] = $raw->orderCode;
        $data_forpayment['amount'] = $raw->amount;
        $data_forpayment['currency'] = $raw->currency;
        $data_forpayment['orderDescription'] = $raw->orderDescription;
        $data_forpayment['returnUrl'] = $raw->returnUrl . '&onclick-success=1';
        $data_forpayment['cancelUrl'] = $raw->cancelUrl;
        $data_forpayment['paymentHours'] = '1';
        $this->initialize_payment_api();
        error_log('before sent'. print_r($data_forpayment,true));
        $result = $this->alepayAPI->sendTokenizationPayment($data_forpayment);
        if(is_object($result)){
            error_log( 'result' . print_r($result, true));
            $token = $result->token;
            $checkout_url = $result->checkoutUrl;
            error_log( 'checkout url' .$result->checkoutUrl);
            MeprUtils::wp_redirect($checkout_url);
        }else{
            error_log('MeprGatewayException');
            throw new MeprGatewayException($result['errorDescription']);
        }
        
    }


    /** This gets called on the_content and just renders the payment form
     */

    public function display_payment_form($amount, $user, $product_id, $txn_id)
    {
        error_log(__METHOD__);
       // error_log('Hello world');
        //on-payment-return success
        $onclick_success = isset($_REQUEST['oneClickSuccess']) ? isset($_REQUEST['oneClickSuccess']): null;
        //on-payment-return cancel

        //link card trả về (thành công + thất bại)
        $card_link_request = isset($_REQUEST['cardLinkRequest']) ? $_REQUEST['cardLinkRequest'] : null;



        $international_result = isset($_REQUEST['internationalResult']) ? $_REQUEST['internationalResult'] : null;
        if(isset($international_result)){
            $this->initialize_payment_api();
            $decryptedData = $this->alepayAPI->decryptCallbackData($_REQUEST['data']);

            // Active subscription

            // Redirect thank you page
        }

        //cancle url của thanh toán thường
        $go_back = isset($_REQUEST['returnUrl']) ? $_REQUEST['returnUrl'] : null;
        $token_key = isset($_REQUEST['tokenKey']) ? $_REQUEST['tokenKey'] : null;
        $transaction_code = isset($_REQUEST['transactionCode']) ? $_REQUEST['transactionCode'] : null;
        $error_code = isset($_REQUEST['errorCode']) ? $_REQUEST['errorCode'] : null;
        //url cancle? 
        $cancel = isset($_REQUEST['cancel']) ? $_REQUEST['cancel'] : null;

        if (isset($card_link_request)) {
            $sub = $this->record_card_link_request($txn_id);
            if(is_object($sub)){
                $this->request_tokenization_payment($sub);
            }else{
                error_log('else');
            }
        }

        if (isset($card_link_request)) {
            $sub = $this->record_card_link_request($txn_id);
            if(is_object($sub)){
                $this->request_tokenization_payment($sub);
            }else{
                error_log('else');
            }
        }

        if (isset($go_back)) {
            error_log("user cancel");
            $txn = new MeprTransaction($txn_id);
            MeprUtils::send_failed_txn_notices($txn);
            echo '<h1>Bạn đã huỷ giao dịch này</h1>';
            echo '<a href="<?php echo get_site_url(); ?>">Quay về trang chủ</a>';
        } else if ($error_code != '000') {
            $mepr_options = MeprOptions::fetch();
            $prd = new MeprProduct($product_id);
            $coupon = false;
            $txn = new MeprTransaction($txn_id);
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
                        <label class="mepr-buyer-first-name">Tên và tên đệm (*)</label>
                        <input type=text name="mepr-buyer-first-name" value="<?php echo $user->first_name  ?>">
                        <label class="mepr-buyer-last-name">Họ (*)</label>
                        <input type=text name="mepr-buyer-last-name" value="<?php echo $user->last_name ?>">
                        <label class="mepr-buyer-email">Email (*)</label>
                        <input type="email" name="mepr-buyer-email" value="<?php echo $user->user_email ?>">
                        <label class="mepr-buyer-phone">Số điện thoại (*)</label>
                        <?php $phone = get_user_meta($user->ID, 'billing_phone', true); ?>
                        <input type="text" name="mepr-buyer-phone" value="<?php echo $phone ?>">
                        <label class="mepr-buyer-address">Địa chỉ (*)</label>
                        <?php $address = get_user_meta($user->ID, 'billing_address_1', true); ?>
                        <input type="text" name="mepr-buyer-address" value="<?php echo $address ?>">
                        <label class="mepr-buyer-city">Thành phố (*)</label>
                        <?php $city = get_user_meta($user->ID, 'billing_city', true); ?>
                        <input type="text" name="mepr-buyer-city" value="<?php echo $city ?>">

                        <label class="mepr-buyer-state">Tỉnh (*)</label>
                        <?php $state = get_user_meta($user->ID, 'billing_state', true); ?>
                        <input type="text" name="mepr-buyer-state" value="<?php echo $state ?>">

                        <label class="mepr-buyer-postal-code">Postal Code (*)</label>
                        <?php $pcode = get_user_meta($user->ID, 'billing_post_code', true); ?>
                        <input type="text" name="mepr-buyer-postal-code" value="<?php echo $pcode ?>">
                        <?php $country = get_user_meta($user->ID, 'billing_country', true); ?>
                        <label class="mepr-buyer-country">Quốc gia (*)</label>
                        <input type="text" name="mepr-buyer-country" value="<?php echo $country ?>">
                        <label class="mepr-buyer-des">Mô tả</label>
                        <input type="text" name="mepr-buyer-des">
                        <label>(*) Các trường dữ liệu không được để trống</label>
                        <span><?php echo __('Select payment type', '') ?></span>
                        <br />
                        <input type="radio" id="domestic" name="alepay_payment_type" value="domestic" checked>
                        <label for="domestic"><?php echo __('Thanh toán thông qua ATM, IB, QRCODE', '') ?></label><br>
                        <br />
                        <input type="radio" id="international" name="alepay_payment_type" value="international">
                        <label for="international"><?php echo __('Thanh toán quốc tế', '') ?></label><br>
                        <br />
                        <input type="radio" id="one_click_payment" name="alepay_payment_type" value="one_click_payment">
                        <label for="international"><?php echo __('Thanh toán nhanh 1-Click', '') ?></label><br>
                        <br />
                        <div id="card-link-container" style="display: none">
                            <input type="checkbox" id="is-card-link" name="is-card-link">
                            <label for="is-card-link"><?php echo __('Tôi đồng ý liên kết thẻ') ?></label>
                        </div>
                        <input type="submit" class="mepr-submit" value="<?php _e('Pay Now', 'memberpress'); ?>" />
                        <img src="<?php echo admin_url('images/loading.gif'); ?>" style="display: none;" class="mepr-loading-gif" />
                        <?php MeprView::render('/shared/has_errors', get_defined_vars()); ?>
                    </form>
                </div>
            </div>

        <?php
        } else if (!$cancel) {
            throw new MeprGatewayException(__('Thanh toán không thành công vui lòng thử lại', 'memberpress'));
        } else {
            error_log('before get transaction transaction code ' . $transaction_code);
            $data['tokenKey'] = $token_key;
            $data['transactionCode'] = $transaction_code;
            $this->initialize_payment_api();
            //TODO: check lại hàm get transaction info
            $result = $this->alepayAPI->getTransactionInfo($transaction_code);
            if ($result->code != '000') {
                $txn = new MeprTransaction($txn_id);
                MeprUtils::send_failed_txn_notices($txn);
                throw new MeprGatewayException(__('Thanh toán không thành công vui lòng thử lại', 'memberpress'));
            } else {
                $_REQUEST['data'] = $result;
                $transaction = $this->record_create_subscription();
                if (gettype($transaction) == 'array') {
                    $txn = new MeprTransaction($txn_id);
                    $sub = $txn->subscription();
                    $this->activate_subscription($txn, $sub);
                    error_log('dispay update form');
                    $mepr_options = MeprOptions::fetch();
                    error_log('dispay update form');
                    $product = new MeprProduct($txn->product_id);
                    $sanitized_title = sanitize_title($product->post_title);

                    $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                    if ($txn->subscription_id > 0) {
                        $sub = $txn->subscription();
                        $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
                    }
                    error_log('sent query ' . print_r($query_params, true));
                    MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
                }
            }
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
        // $sub = (isset($_GET['action']) && $_GET['action'] == 'update' && isset($_GET['sub'])) ? new MeprSubscription((int)$_GET['sub']) : false;
        // if($sub !== false && $sub->gateway == $this->id) {
        //   wp_enqueue_script('Alepay-js', 'https://js.Alepay.com/v3/', array(), MEPR_VERSION . time());
        //   wp_enqueue_script('Alepay-account-create-token', MEPR_GATEWAYS_URL . '/Alepay/account_create_token.js', array('Alepay-js'), MEPR_VERSION . time());
        //   wp_localize_script('Alepay-account-create-token', 'MeprStripeAccountForm', array(
        //     // 'style' => $this->get_element_style(),
        //     'public_key' => $this->settings->public_key,
        //     'ajax_url' => admin_url('admin-ajax.php'),
        //     'ajax_error' => __('Ajax error', 'memberpress'),
        //     'invalid_response_error' => __('The response from the server was invalid', 'memberpress')
        //   ));
        // }
    }

    /** Displays the update account form on the subscription account page **/
    public function display_update_account_form($sub_id, $errors = array(), $message = '')
    {
        error_log(__METHOD__);
        error_log(__METHOD__);
        $go_back = isset($_REQUEST['returnUrl']) ? $_REQUEST['returnUrl'] : null;
        $token_key = isset($_REQUEST['tokenKey']) ? $_REQUEST['tokenKey'] : null;
        $transaction_code = isset($_REQUEST['transactionCode']) ? $_REQUEST['transactionCode'] : null;
        $error_code = isset($_REQUEST['errorCode']) ? $_REQUEST['errorCode'] : null;
        $cancel = isset($_REQUEST['cancel']) ? $_REQUEST['cancel'] : null;
        if (isset($go_back)) {
            // error_log("user cancel");
            // $txn = new MeprTransaction($txn_id);
            // MeprUtils::send_failed_txn_notices($txn);
            echo '<h1>Bạn đã huỷ giao dịch này</h1>';
            echo '<a href="<?php echo get_site_url(); ?>">Quay về trang chủ</a>';
        } else if ($error_code != '000') {
            $mepr_options = MeprOptions::fetch();
            $sub = new MeprSubscription($sub_id);
            $user = $sub->user();
            if (MeprUtils::is_post_request() && empty($errors) && !empty($_POST['mepr_alepay_update_is_payment'])) {
                $message = __('Update successful, please allow some time for the payment to process. Your account will reflect the updated payment soon.', 'memberpress');
            }
        ?>
            <div class="mp_wrapper">
                <form action="" method="post" id="mepr-alepay-payment-form" data-sub-id="<?php echo esc_attr($sub->id); ?>">
                    <input type="hidden" name="_mepr_nonce" value="<?php echo wp_create_nonce('mepr_process_update_account_form'); ?>" />
                    <input type="hidden" name="address_required" value="<?php echo $mepr_options->show_address_fields && $mepr_options->require_address_fields ? 1 : 0 ?>" />

                    <?php
                    if ($user instanceof MeprUser) {
                        MeprView::render("/checkout/MeprStripeGateway/payment_gateway_fields", get_defined_vars());
                    }
                    ?>

                    <div class="mepr_update_account_table">
                        <div><strong><?php _e('Update your Credit Card information below', 'memberpress'); ?></strong></div>
                        <br />
                        <div class="mepr-alepay-errors"></div>
                        <?php MeprView::render('/shared/errors', get_defined_vars()); ?>

                        <div class="mp-form-row">
                            <div class="mp-form-label">
                                <label for="mepr_alepay_card_name"><?php _e('Name on the card:*', 'memberpress'); ?></label>
                                <span class="cc-error"><?php _ex('Name on the card is required.', 'ui', 'memberpress'); ?></span>
                            </div>
                            <input type="text" name="card-name" id="mepr_alepay_card_name" class="mepr-form-input alepay-card-name" required value="<?php echo esc_attr($user->get_full_name()); ?>" />
                        </div>

                        <div class="mp-form-row">
                            <div class="mp-form-label">
                                <label><?php _ex('Credit Card', 'ui', 'memberpress'); ?></label>
                                <span id="card-errors" role="alert" class="mepr-alepay-card-errors"></span>
                            </div>
                            <div id="card-element" class="mepr-alepay-card-element">
                                <!-- a alepay Element will be inserted here. -->

                                <input type="hidden" name="mepr_process_payment_form" value="Y" />
                                <div class="mepr_spacer">&nbsp;</div>
                                <label class="mepr-buyer-first-name">Tên và tên đệm (*)</label>
                                <input type=text name="mepr-buyer-first-name" required value="<?php echo $user->first_name  ?>">
                                <label class="mepr-buyer-last-name">Họ (*)</label>
                                <input type=text name="mepr-buyer-last-name" required value="<?php echo $user->last_name ?>">
                                <label class="mepr-buyer-email">Email (*)</label>
                                <input type="email" name="mepr-buyer-email" value="<?php echo $user->user_email ?>">
                                <label class="mepr-buyer-phone">Số điện thoại (*)</label>
                                <?php $phone = get_user_meta($user->ID, 'billing_phone', true); ?>
                                <input type="text" name="mepr-buyer-phone" required value="<?php echo $phone ?>">
                                <label class="mepr-buyer-address">Địa chỉ (*)</label>
                                <?php $address = get_user_meta($user->ID, 'billing_address_1', true); ?>
                                <input type="text" name="mepr-buyer-address" required value="<?php echo $address ?>">
                                <label class="mepr-buyer-city">Thành phố (*)</label>
                                <?php $city = get_user_meta($user->ID, 'billing_city', true); ?>
                                <input type="text" name="mepr-buyer-city" required value="<?php echo $city ?>">
                                <?php $country = get_user_meta($user->ID, 'billing_country', true); ?>
                                <label class="mepr-buyer-country">Quốc gia (*)</label>
                                <input type="text" name="mepr-buyer-country" required value="<?php echo $country ?>">
                                <label class="mepr-buyer-des">Mô tả</label>
                                <input type="text" name="mepr-buyer-des">
                                <label>(*) Các trường dữ liệu không được để trống</label>
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
        } else if (!$cancel) {
            throw new MeprGatewayException(__('Thanh toán không thành công vui lòng thử lại', 'memberpress'));
        } else {
            error_log('before get transaction transaction code ' . $transaction_code);
            $data['tokenKey'] = $token_key;
            $data['transactionCode'] = $transaction_code;
            $this->initialize_payment_api();
            $result = $this->alepayAPI->getTransactionInfo($transaction_code);
            if ($result->code != '000') {
                $sub = new MeprSubscription($sub_id);
                $txn = new MeprTransaction($sub->trans_num);
                MeprUtils::send_failed_txn_notices($txn);
                throw new MeprGatewayException(__('Thanh toán không thành công vui lòng thử lại', 'memberpress'));
            } else {
                $_REQUEST['data'] = $result;
                $transaction = $this->record_create_subscription();
                error_log('loggggggg 0' . print_r($transaction, true));
                if (gettype($transaction) == 'array') {
                    error_log('loggggggg 1 trans id ' . $transaction['transaction']->id);
                    $sub = new MeprSubscription($sub_id);
                    $txn = new MeprTransaction($transaction['transaction']->id);
                    $sub = $txn->subscription();
                    $this->activate_subscription($txn, $sub);
                    $mepr_options = MeprOptions::fetch();
                    error_log('dispay update form');
                    $product = new MeprProduct($txn->product_id);
                    $sanitized_title = sanitize_title($product->post_title);
                    $query_params = array('membership' => $sanitized_title, 'trans_num' => $txn->trans_num, 'membership_id' => $product->ID);
                    if ($txn->subscription_id > 0) {
                        $sub = $txn->subscription();
                        $query_params = array_merge($query_params, array('subscr_id' => $sub->subscr_id));
                    }
                    error_log('sent query ' . print_r($query_params, true));
                    MeprUtils::wp_redirect($mepr_options->thankyou_page_url(build_query($query_params)));
                } else {
                    error_log('loggggggg');
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
        error_log(print_r($user, true));
        $txn = new MeprTransaction();
        $txn->subscription_id = $sub_id;
        $txn->user_id = $user->ID;
        error_log(print_r($txn, true));
        $this->process_create_subscription($txn);
        //$this->process_update_subscription($sub_id);
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

    /** Get the renewal base date for a given subscription. This is the date MemberPress will use to calculate expiration dates.
     * Of course this method is meant to be overridden when a gateway requires it.
     */
    public function get_renewal_base_date(MeprSubscription $sub)
    {
        global $wpdb;
        $mepr_db = MeprDb::fetch();

        $q = $wpdb->prepare(
            "
        SELECT e.created_at
          FROM {$mepr_db->events} AS e
         WHERE e.event='subscription-resumed'
           AND e.evt_id_type='subscriptions'
           AND e.evt_id=%d
         ORDER BY e.created_at DESC
         LIMIT 1
      ",
            $sub->id
        );

        $renewal_base_date = $wpdb->get_var($q);
        if (!empty($renewal_base_date)) {
            return $renewal_base_date;
        }

        return $sub->created_at;
    }


    public function initialize_payment_api()
    {
        if (!isset($this->alepayAPI)) {

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