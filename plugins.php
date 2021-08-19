<?php

/**
 * Plugin Name:       Alepay Gateway
 * Plugin URI:        https://dalathub.com
 * Description:       Integrated with MemberPress
 * Author:            TinhPhan
 * Author URI:        tinhpt.38@gmail.com
 * Version:           2.0.0
 * Text Domain:       alepay-gateway
 * Domain Path:       assets/languages
 */

require_once __DIR__ . './utils/AleConfiguration.php';
require_once __DIR__ . './gateways/DLHMemeberpressWebhookHandler.php';

/**
 * Add custom AJAX for webhook
 */
$instance = new DLHMemeberpressWebhookHandler();


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
        'Alepay Settings',
        'Alepay Settings',
        'manage_options',
        'alepay-setting',
        'config_render'
    );
}

function config_render()
{
    $encrypt_key = get_option(AleConfiguration::$ENCRYPT_KEY);
    $api_key = get_option(AleConfiguration::$API_KEY);
    $checksum_key = get_option(AleConfiguration::$CHECKSUM_KEY);
    $base_url_v3 = get_option(AleConfiguration::$BASE_URL_V3);
    $base_url_v1 = get_option(AleConfiguration::$BASE_URL_V1);
    $base_url_live = get_option(AleConfiguration::$BASE_URL_LIVE);
    $email = get_option(AleConfiguration::$EMAIL);
    $connected = get_option(AleConfiguration::$CONNECTED);
    $test_mode = get_option(AleConfiguration::$TEST_MODE);

    $connected = $connected == true ? 'checked' : '';
    $test_mode = $test_mode == true ? 'checked' : '';
    ?>

    <h2>Configuration AlePay Gateway</h2>
    <div class="alp-container">
        <form name="alepay-settings" id="alepay-settings" method="post"
              action="<?php echo admin_url('?page=alepay-setting'); ?>">
            <div class="item">
                <label for="alepay_encrypt_key">Encrypt</label>
                <input name="alepay_encrypt_key" type="text" value=<?php echo $encrypt_key ?>>
            </div>
            <div class="item">
                <label for="alepay_api_key">API key</label>
                <input name="alepay_api_key" type="text" value=<?php echo $api_key ?>>
            </div>
            <div class="item">
                <label for="alepay_checksum_key">Checksum key</label>
                <input name="alepay_checksum_key" type="text" value=<?php echo $checksum_key ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_v3">Base URL Sanbox v3</label>
                <input name="alepay_base_url_v3" type="text" value=<?php echo $base_url_v3; ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_v1">Base URL Sanbox v1</label>
                <input name="alepay_base_url_v1" type="text" value=<?php echo $base_url_v1; ?>>
            </div>
            <div class="item">
                <label for="alepay_base_url_live">Base URL LIVE</label>
                <input name="alepay_base_url_live" type="text" value=<?php echo $base_url_live; ?>>
            </div>
            <div class="item">
                <label for="alepay_email">Email</label>
                <input name="alepay_email" type="text" value=<?php echo $email; ?>>
            </div>

            <div class="item-checkbox">
                <label for="alepay_connect_status">Connect Status</label>
                <input name="alepay_connect_status" type="checkbox" <?php echo $connected ?>>
            </div>

            <div class="item-checkbox">
                <label for="is_test_mode">Enable Sanbox</label>
                <input name="is_test_mode" type="checkbox" <?php echo $test_mode ?>>
            </div>
            <div class="item">
                <input class="button button-primary" name="alepay-setting-submit" type="submit" value="Save change">
            </div>
        </form>
    </div>

    <?php

    if (isset($_POST['alepay-setting-submit'])) {
        $encrypt_key = sanitize_text_field($_POST['alepay_encrypt_key']);
        $api_key = $_POST['alepay_api_key'];
        $checksum_key = $_POST['alepay_checksum_key'];
        $url_v3 = $_POST['alepay_base_url_v3'];
        $url_v1 = $_POST['alepay_base_url_v1'];
        $url_live = $_POST['alepay_base_url_live'];
        $email = $_POST['alepay_email'];

        if (isset($_POST['alepay_connect_status'])) {
            $connected = true;
        } else {
            $connected = false;
        }
        if (isset($_POST['is_test_mode'])) {
            $test_mode = true;
        } else {
            $test_mode = false;
        }

        if (empty(get_option('udoo_ale_encrypt_key'))) {
            add_option('udoo_ale_encrypt_key', $encrypt_key);
        } else {
            update_option('udoo_ale_encrypt_key', $encrypt_key);
        }

        if (empty(get_option('udoo_ale_api_key'))) {
            add_option('udoo_ale_api_key', $api_key);
        } else {
            update_option('udoo_ale_api_key', $api_key);
        }

        if (empty(get_option('udoo_ale_checksum_key'))) {
            add_option('udoo_ale_checksum_key', $checksum_key);
        } else {
            update_option('udoo_ale_checksum_key', $checksum_key);
        }

        if (empty(get_option('udoo_ale_base_url_v3'))) {
            add_option('udoo_ale_base_url_v3', $url_v3);
        } else {
            update_option('udoo_ale_base_url_v3', $url_v3);
        }

        if (empty(get_option('udoo_ale_base_url_v1'))) {
            add_option('udoo_ale_base_url_v1', $url_v1);
        } else {
            update_option('udoo_ale_base_url_v1', $url_v1);
        }

        if (empty(get_option('udoo_ale_base_url_live'))) {
            add_option('udoo_ale_base_url_live', $url_live);
        } else {
            update_option('udoo_ale_base_url_live', $url_live);
        }

        if (empty(get_option('udoo_ale_email'))) {
            add_option('udoo_ale_email', $email);
        } else {
            update_option('udoo_ale_email', $email);
        }

        if (empty(get_option('udoo_ale_connected'))) {
            add_option('udoo_ale_connected', $connected);
        } else {
            update_option('udoo_ale_connected', $connected);
        }

        if (empty(get_option('udoo_ale_is_test_mode'))) {
            add_option('udoo_ale_is_test_mode', $test_mode);
        } else {
            update_option('udoo_ale_is_test_mode', $test_mode);
        }
    }
}
