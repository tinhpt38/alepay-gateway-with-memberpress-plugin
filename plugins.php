<?php

/**
 * Plugin Name:       Alepay Gateway
 * Plugin URI:        https://udoo.ooo
 * Description:       Integrated with MemberPress
 * Author:            Udoo
 * Author URI:        https://udoo.ooo
 * Version:           2.0.4
 * Text Domain:       alepay-gateway
 * Domain Path:       /languages
 */

require_once __DIR__ . '/utils/AleConfiguration.php';
require_once __DIR__ . '/gateways/AlepayWebhookHandler.php';
require_once __DIR__ . '/utils/WPConfigTransformer.php';

require_once __DIR__ . '/UdooSecuri.php';

/**
 * Add custom AJAX for webhook
 */
$instance = new AlepayWebhookHandler();

function alepay_plugin_load_textdomain()
{
    load_plugin_textdomain('alepay-gateway', false, dirname(plugin_basename(__FILE__)) . '/languages/');
}


add_action('init', 'alepay_plugin_load_textdomain');


add_filter('mepr-gateway-paths', 'ale_add_mepr_gateway_paths');

function ale_add_mepr_gateway_paths($tabs)
{
    $gateway = WP_PLUGIN_DIR . '/alepay-gateway/gateways/';
    array_push($tabs, $gateway);
    return $tabs;
}

add_action('admin_enqueue_scripts', 'ale_admin_enqueue_scripts');

add_action('wp_enqueue_scripts', 'ale_enqueue_scripts');


function ale_enqueue_scripts()
{

    wp_register_script('alepay-js-native', plugins_url('/config.js', __FILE__), array('jquery'), null, true);
    wp_localize_script('alepay-js-native', 'ajax_object', array('ajax_url' => admin_url('admin-ajax.php')));
    wp_enqueue_script('alepay-js-native');
    wp_register_style('alepay-gateway-client-css', plugins_url('/styles.css', __FILE__));
    wp_enqueue_style('alepay-gateway-client-css');
}

function ale_admin_enqueue_scripts()
{

    wp_register_style('alepay-gateway-native-css', plugins_url('/styles.css', __FILE__));
    wp_enqueue_style('alepay-gateway-native-css');
}


add_action('admin_menu', 'ale_config_menu');


function ale_config_menu()
{
    add_menu_page(
        __('Alepay Settings', 'alepay-gateway'),
        __('Alepay Settings', 'alepay-gateway'),
        'manage_options',
        'alepay-setting',
        'config_render'
    );
}



function udoo_get_settings()
{

    $securi = UdooSecuri::get_key();
    $encrypt_key = UdooSecuri::get_option(AleConfiguration::$ENCRYPT_KEY,$securi);
    $api_key = UdooSecuri::get_option(AleConfiguration::$API_KEY, $securi);
    $checksum_key = UdooSecuri::get_option(AleConfiguration::$CHECKSUM_KEY,$securi);

    $base_url_v3 = get_option(AleConfiguration::$BASE_URL_V3,'');
    $base_url_v1 = get_option(AleConfiguration::$BASE_URL_V1,'');
    $base_url_live = get_option(AleConfiguration::$BASE_URL_LIVE,'');
    $email = get_option(AleConfiguration::$EMAIL,'');
    $connected = get_option(AleConfiguration::$CONNECTED,'');
    $test_mode = get_option(AleConfiguration::$TEST_MODE,'');
    $namespace = get_option(AleConfiguration::$NAME_SPACE,'');
    $chekout_message = get_option(AleConfiguration::$CHECKOUT_MESSAGE,'');
    $payment_hours = get_option(AleConfiguration::$PAYMENT_HOURS,'');
    $connected = $connected == 'yes' ? 'checked' : '';
    $test_mode = $test_mode == 'yes' ? 'checked' : '';
    return array(
        'securi' => $securi,
        'encrypt' => $encrypt_key,
        'api' => $api_key,
        'url_live' => $base_url_live,
        'url_v3' => $base_url_v3,
        'url_v1' => $base_url_v1,
        'checksum' => $checksum_key,
        'email' => $email,
        'connected' => $connected,
        'test_mode' => $test_mode,
        'namespace' => $namespace,
        'checkout_message' => $chekout_message,
        'payment_hours' => $payment_hours,
    );
}


function config_render()
{
    error_log(__METHOD__);

    $settings = udoo_get_settings();
    error_log(print_r($settings,true));

?>

    <h2><?php echo __('Configuration AlePay Gateway', 'alepay-gateway') ?></h2>
    <div class="alp-container">
        <form name="alepay-settings" id="alepay-settings" method="post" action="<?php echo admin_url('?page=alepay-setting'); ?>">
            <h3>General</h3>
            <div class="item">
                <label for="alepay_encrypt_key"><strong>Encrypt Key</strong></label>
                <input id="alepay_encrypt_key" name="alepay_encrypt_key" type="text" value=<?php echo $settings['encrypt'] ?>>
            </div>
            <div class="item">
                <label for="alepay_api_key"><strong>API Key</strong></label>
                <input id="alepay_api_key" name="alepay_api_key" type="text" value=<?php echo $settings['api'] ?>>
            </div>
            <div class="item">
                <label for="alepay_checksum_key"><strong>Checksum Key</strong></label>
                <input id="alepay_checksum_key" name="alepay_checksum_key" type="text" value=<?php echo $settings['checksum'] ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_v3"><strong>Base URL Sanbox v3</strong></label>
                <input id="alepay_base_url_v3" name="alepay_base_url_v3" type="text" value=<?php echo $settings['url_v3']; ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_v1"><strong>Base URL Sanbox v1</strong></label>
                <input id="alepay_base_url_v1" name="alepay_base_url_v1" type="text" value=<?php echo $settings['url_v1']; ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_live"><strong>Base URL LIVE</strong></label>
                <input id="alepay_base_url_live" name="alepay_base_url_live" type="text" value=<?php echo $settings['url_live']; ?>>
            </div>
            <div class="item">
                <label for="alepay_email"><strong>Email</strong></label>
                <input id="alepay_email" name="alepay_email" type="text" value=<?php echo $settings['email']; ?>>
            </div>

            <div class="item">
                <label for="alepay_name_space"><strong>Namespace</strong></label>
                <input id="alepay_name_space" name="alepay_name_space" type="text" value=<?php echo $settings['namespace']; ?>>
            </div>

            <div class="item">
                <label for="alepay_payment_hours"><strong>PAYMENT HOURS</strong></label>
                <input id="alepay_payment_hours" name="alepay_payment_hours" type="number" value=<?php echo $settings['payment_hours']; ?>>
            </div>

            <div class="item-checkbox">
                <label for="alepay_connect_status"><strong>Connect Status</strong></label>
                <input id="alepay_connect_status" name="alepay_connect_status" type="checkbox" <?php echo $settings['connected'] ?>>
            </div>

            <div class="item-checkbox">
                <label for="is_test_mode"><strong>Test Mode</strong></label>
                <input id="is_test_mode" name="is_test_mode" type="checkbox" <?php echo $settings['test_mode']; ?>>
            </div>
            <hr />
            <h3>Message</h3>
            <div class="item">
                <label for="checkout_message">Email comfirm checkout</label>
                <?php
                if (empty($settings['checkout_message'])) {
                    $chekout_message = 'Một giao dịch từ $site cần bạn xác nhận. Mã giao giao dịch là $sub_id. Bạn vui lòng truy cập đường dẫn sau để xác nhận giao dịch $url.';
                } else {
                    $checkout_message = $settings['checkout_message'];
                }
                ?>
                <textarea id="checkout_message" name="checkout_message" rows="4" cols="50"><?php echo $checkout_message; ?></textarea>
            </div>
            <h4>Fileds is require: $sub_id, $url</h4>
            <h3>Security</h3>
            <div class="item">
                <label for="alepay_securi"><strong>Encrypt Database Key</strong></label>
                <input id="alepay_securi" name="alepay_securi" type="text" value=<?php echo $settings['securi']; ?>>
            </div>

            <div class="item">
                <button class="button button-primary" name="alepay-setting-submit" type="submit">Save change</button>
            </div>
        </form>
    </div>

<?php

    if (isset($_POST['alepay-setting-submit'])) {
        $encrypt_key = $_POST['alepay_encrypt_key'];
        $api_key = $_POST['alepay_api_key'];
        $checksum_key = $_POST['alepay_checksum_key'];

        $url_v3 = $_POST['alepay_base_url_v3'];
        $url_v1 = $_POST['alepay_base_url_v1'];
        $url_live = $_POST['alepay_base_url_live'];
        $email = $_POST['alepay_email'];
        $namespace = $_POST['alepay_name_space'];
        $payment_hours = $_POST['alepay_payment_hours'];

        $securi = $_POST['alepay_securi'];

        $checkout_message = $_POST['checkout_message'];

        if (isset($_POST['alepay_connect_status'])) {
            $connected = 'yes';
        } else {
            $connected = 'no';
        }
        if (isset($_POST['is_test_mode'])) {
            $test_mode = 'yes';
        } else {
            $test_mode = 'no';
        }
        
        //region -- save options

        if (!get_option(AleConfiguration::$CHECKOUT_MESSAGE)) {
            add_option(AleConfiguration::$CHECKOUT_MESSAGE, $checkout_message);
        } else {
            update_option(AleConfiguration::$CHECKOUT_MESSAGE, $checkout_message);
        }


        if (!get_option(AleConfiguration::$NAME_SPACE)) {
            add_option(AleConfiguration::$NAME_SPACE, $namespace);
        } else {
            update_option(AleConfiguration::$NAME_SPACE, $namespace);
        }

        if (!get_option(AleConfiguration::$BASE_URL_V3)) {
            add_option(AleConfiguration::$BASE_URL_V3, $url_v3);
        } else {
            update_option(AleConfiguration::$BASE_URL_V3, $url_v3);
        }

        if (!get_option(AleConfiguration::$BASE_URL_V1)) {
            add_option(AleConfiguration::$BASE_URL_V1, $url_v1);
        } else {
            update_option(AleConfiguration::$BASE_URL_V1, $url_v1);
        }

        if (!get_option(AleConfiguration::$BASE_URL_LIVE)) {
            add_option(AleConfiguration::$BASE_URL_LIVE, $url_live);
        } else {
            update_option(AleConfiguration::$BASE_URL_LIVE, $url_live);
        }

        if (!get_option(AleConfiguration::$EMAIL)) {
            add_option(AleConfiguration::$EMAIL, $email);
        } else {
            update_option(AleConfiguration::$EMAIL, $email);
        }


        if (!get_option(AleConfiguration::$CONNECTED)) {
            add_option(AleConfiguration::$CONNECTED, $connected);
        } else {
            update_option(AleConfiguration::$CONNECTED, $connected);
        }

        if (!get_option(AleConfiguration::$TEST_MODE)) {
            add_option(AleConfiguration::$TEST_MODE, $test_mode);
        } else {
            update_option(AleConfiguration::$TEST_MODE, $test_mode);
        }

        if (!get_option(AleConfiguration::$PAYMENT_HOURS)) {
            add_option(AleConfiguration::$PAYMENT_HOURS, $payment_hours);
        } else {
            update_option(AleConfiguration::$PAYMENT_HOURS, $payment_hours);
        }

        if (!get_option(AleConfiguration::$SECURI)) {
            add_option(AleConfiguration::$SECURI, $securi);
        } else {
            update_option(AleConfiguration::$SECURI, $securi);
        }

        if(!UdooSecuri::get_option(AleConfiguration::$API_KEY,$securi)){
            UdooSecuri::add_option(AleConfiguration::$API_KEY, $api_key,$securi);
        }else{
            UdooSecuri::update_option(AleConfiguration::$API_KEY, $api_key,$securi);
        }

        if(!UdooSecuri::get_option(AleConfiguration::$ENCRYPT_KEY,$securi)){
            UdooSecuri::add_option(AleConfiguration::$ENCRYPT_KEY, $encrypt_key,$securi);
        }else{
            UdooSecuri::update_option(AleConfiguration::$ENCRYPT_KEY, $encrypt_key,$securi);
        }

        if(!UdooSecuri::get_option(AleConfiguration::$CHECKSUM_KEY, $securi)){
            UdooSecuri::add_option(AleConfiguration::$CHECKSUM_KEY, $checksum_key,$securi);
        }else{
            UdooSecuri::update_option(AleConfiguration::$CHECKSUM_KEY, $checksum_key, $securi);
        }

        //endregion -- save options
    }
}


function fs_get_wp_config_path()
{
    $base = dirname(__FILE__);
    $path = false;

    if (@file_exists(dirname(dirname($base)) . "/wp-config.php")) {
        $path = dirname(dirname($base)) . "/wp-config.php";
    } else
    if (@file_exists(dirname(dirname(dirname($base))) . "/wp-config.php")) {
        $path = dirname(dirname(dirname($base))) . "/wp-config.php";
    } else
        $path = false;

    if ($path != false) {
        $path = str_replace("\\", "/", $path);
    }
    return $path;
}
