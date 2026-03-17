<?php
/**
 * Connection handler - manages token-based connection to inventory system
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

class DDI_Connection {

    private static $instance = null;

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_ddi_connect', array($this, 'ajax_connect'));
        add_action('wp_ajax_ddi_disconnect', array($this, 'ajax_disconnect'));
    }

    /**
     * Check if the plugin is connected
     */
    public function is_connected() {
        $settings = get_option('ddi_settings', array());
        return !empty($settings['webhook_url']) && !empty($settings['connected_at']);
    }

    /**
     * Get connection info for display
     */
    public function get_connection_info() {
        $settings = get_option('ddi_settings', array());
        return array(
            'connected' => $this->is_connected(),
            'webhook_url' => isset($settings['webhook_url']) ? $settings['webhook_url'] : '',
            'connected_at' => isset($settings['connected_at']) ? $settings['connected_at'] : '',
            'store_name' => isset($settings['store_name']) ? $settings['store_name'] : '',
        );
    }

    /**
     * Get the URL to WooCommerce REST API keys page
     */
    public function get_api_keys_url() {
        return admin_url('admin.php?page=wc-settings&tab=advanced&section=keys&create-key=1');
    }

    /**
     * AJAX: Connect using token + manually created API keys
     */
    public function ajax_connect() {
        check_ajax_referer('ddi_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'dd-inventory')));
        }

        $token = isset($_POST['token']) ? sanitize_text_field(wp_unslash($_POST['token'])) : '';
        $consumer_key = isset($_POST['consumer_key']) ? sanitize_text_field(wp_unslash($_POST['consumer_key'])) : '';
        $consumer_secret = isset($_POST['consumer_secret']) ? sanitize_text_field(wp_unslash($_POST['consumer_secret'])) : '';

        if (empty($token)) {
            wp_send_json_error(array('message' => __('Please enter a connection token.', 'dd-inventory')));
        }

        if (strpos($token, 'ddi_') !== 0) {
            wp_send_json_error(array('message' => __('Invalid token format. Token should start with "ddi_".', 'dd-inventory')));
        }

        if (empty($consumer_key) || empty($consumer_secret)) {
            wp_send_json_error(array('message' => __('Please enter your API Consumer Key and Consumer Secret.', 'dd-inventory')));
        }

        if (strpos($consumer_key, 'ck_') !== 0) {
            wp_send_json_error(array('message' => __('Consumer Key should start with "ck_".', 'dd-inventory')));
        }

        if (strpos($consumer_secret, 'cs_') !== 0) {
            wp_send_json_error(array('message' => __('Consumer Secret should start with "cs_".', 'dd-inventory')));
        }

        // Parse the token to extract the app URL
        $parsed = $this->parse_token($token);
        if (!$parsed) {
            wp_send_json_error(array('message' => __('Could not parse token. Please copy the full token from your inventory app.', 'dd-inventory')));
        }

        $app_url = $parsed['url'];

        // Send the token + API keys to the inventory app's connect endpoint
        $connect_url = rtrim($app_url, '/') . '/api/sync/connect';
        $site_url = get_site_url();

        $response = wp_remote_post($connect_url, array(
            'timeout' => 30,
            'headers' => array('Content-Type' => 'application/json'),
            'body' => wp_json_encode(array(
                'token' => $token,
                'consumer_key' => $consumer_key,
                'consumer_secret' => $consumer_secret,
                'site_url' => $site_url,
            )),
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array(
                'message' => __('Could not reach the inventory app: ', 'dd-inventory') . $response->get_error_message(),
            ));
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200 || empty($response_body['success'])) {
            $error_message = isset($response_body['error']) ? $response_body['error'] : __('Connection failed.', 'dd-inventory');
            wp_send_json_error(array('message' => $error_message));
        }

        // Save connection details
        $webhook_url = isset($response_body['webhook_url']) ? $response_body['webhook_url'] : '';
        $webhook_secret = isset($response_body['webhook_secret']) ? $response_body['webhook_secret'] : '';
        $store_name = isset($response_body['store_name']) ? $response_body['store_name'] : '';

        $settings = get_option('ddi_settings', array());
        $settings['webhook_url'] = $webhook_url;
        $settings['connected_at'] = current_time('mysql');
        $settings['store_name'] = $store_name;
        update_option('ddi_settings', $settings);

        // Save webhook secret
        if ($webhook_secret) {
            update_option('ddi_webhook_secret', $webhook_secret);
        }

        // Register webhooks now that we have the URL
        DDI_Webhooks::instance()->register_webhooks();

        DDI()->log_sync_event('connection', 'connected', 'Connected to inventory system');

        wp_send_json_success(array(
            'message' => __('Connected successfully!', 'dd-inventory'),
            'store_name' => $store_name,
        ));
    }

    /**
     * AJAX: Disconnect
     */
    public function ajax_disconnect() {
        check_ajax_referer('ddi_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'dd-inventory')));
        }

        $settings = get_option('ddi_settings', array());

        // Clear connection details
        $settings['webhook_url'] = '';
        $settings['connected_at'] = '';
        $settings['store_name'] = '';
        update_option('ddi_settings', $settings);

        DDI()->log_sync_event('connection', 'disconnected', 'Disconnected from inventory system');

        wp_send_json_success(array('message' => __('Disconnected.', 'dd-inventory')));
    }

    /**
     * Parse token to extract the app URL
     * Token format: ddi_{base64url_payload}_{signature}
     */
    private function parse_token($token) {
        $without_prefix = substr($token, 4); // Remove "ddi_"
        $last_underscore = strrpos($without_prefix, '_');
        if ($last_underscore === false) {
            return false;
        }

        $payload_encoded = substr($without_prefix, 0, $last_underscore);

        // Base64url decode
        $payload_json = base64_decode(strtr($payload_encoded, '-_', '+/'));
        if (!$payload_json) {
            return false;
        }

        $payload = json_decode($payload_json, true);
        if (!$payload || empty($payload['u'])) {
            return false;
        }

        return array(
            'url' => $payload['u'],
            'channel_id' => isset($payload['c']) ? $payload['c'] : '',
        );
    }
}
