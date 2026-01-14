<?php
/**
 * Settings page class
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

/**
 * Settings class
 */
class DDI_Settings {

    /**
     * Single instance
     *
     * @var DDI_Settings
     */
    private static $instance = null;

    /**
     * Settings page slug
     *
     * @var string
     */
    private $page_slug = 'dd-inventory';

    /**
     * Get the single instance
     *
     * @return DDI_Settings
     */
    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor
     */
    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ddi_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ddi_register_webhooks', array($this, 'ajax_register_webhooks'));
    }

    /**
     * Add menu page under WooCommerce
     */
    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('DD Inventory', 'dd-inventory'),
            __('DD Inventory', 'dd-inventory'),
            'manage_woocommerce',
            $this->page_slug,
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('ddi_settings_group', 'ddi_settings', array($this, 'sanitize_settings'));

        // Connection Section
        add_settings_section(
            'ddi_connection_section',
            __('Connection Settings', 'dd-inventory'),
            array($this, 'render_connection_section'),
            $this->page_slug
        );

        add_settings_field(
            'webhook_url',
            __('Webhook Endpoint URL', 'dd-inventory'),
            array($this, 'render_webhook_url_field'),
            $this->page_slug,
            'ddi_connection_section'
        );

        // Sync Options Section
        add_settings_section(
            'ddi_sync_section',
            __('Sync Options', 'dd-inventory'),
            array($this, 'render_sync_section'),
            $this->page_slug
        );

        add_settings_field(
            'lock_stock_management',
            __('Lock Stock Management', 'dd-inventory'),
            array($this, 'render_lock_stock_field'),
            $this->page_slug,
            'ddi_sync_section'
        );

        add_settings_field(
            'lock_sku_editing',
            __('Lock SKU Editing', 'dd-inventory'),
            array($this, 'render_lock_sku_field'),
            $this->page_slug,
            'ddi_sync_section'
        );

        add_settings_field(
            'auto_register_webhooks',
            __('Auto-Register Webhooks', 'dd-inventory'),
            array($this, 'render_auto_register_field'),
            $this->page_slug,
            'ddi_sync_section'
        );
    }

    /**
     * Enqueue admin scripts
     *
     * @param string $hook Current admin page hook
     */
    public function enqueue_scripts($hook) {
        if (strpos($hook, $this->page_slug) === false) {
            return;
        }

        wp_enqueue_style(
            'ddi-admin-styles',
            DDI_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            DDI_VERSION
        );

        wp_enqueue_script(
            'ddi-admin-scripts',
            DDI_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            DDI_VERSION,
            true
        );

        wp_localize_script('ddi-admin-scripts', 'ddi_admin', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('ddi_admin_nonce'),
            'strings' => array(
                'testing' => __('Testing connection...', 'dd-inventory'),
                'success' => __('Connection successful!', 'dd-inventory'),
                'error' => __('Connection failed: ', 'dd-inventory'),
                'registering' => __('Registering webhooks...', 'dd-inventory'),
                'registered' => __('Webhooks registered!', 'dd-inventory'),
            ),
        ));
    }

    /**
     * Sanitize settings
     *
     * @param array $input Input values
     * @return array Sanitized values
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        $sanitized['webhook_url'] = isset($input['webhook_url'])
            ? esc_url_raw(trim($input['webhook_url']))
            : '';

        $sanitized['lock_stock_management'] = isset($input['lock_stock_management']) ? 'yes' : 'no';
        $sanitized['lock_sku_editing'] = isset($input['lock_sku_editing']) ? 'yes' : 'no';
        $sanitized['auto_register_webhooks'] = isset($input['auto_register_webhooks']) ? 'yes' : 'no';

        return $sanitized;
    }

    /**
     * Render settings page
     */
    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'dd-inventory'));
        }
        ?>
        <div class="wrap ddi-settings-wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <?php $this->render_status_cards(); ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('ddi_settings_group');
                do_settings_sections($this->page_slug);
                submit_button();
                ?>
            </form>

            <?php $this->render_webhook_info(); ?>
            <?php $this->render_recent_logs(); ?>
        </div>
        <?php
    }

    /**
     * Render status cards
     */
    private function render_status_cards() {
        $settings = get_option('ddi_settings', array());
        $webhook_url = isset($settings['webhook_url']) ? $settings['webhook_url'] : '';
        $webhooks_registered = $this->count_registered_webhooks();
        $synced_products = $this->count_synced_products();
        ?>
        <div class="ddi-status-cards">
            <div class="ddi-status-card">
                <h3><?php esc_html_e('Connection Status', 'dd-inventory'); ?></h3>
                <div class="ddi-status-indicator <?php echo $webhook_url ? 'configured' : 'not-configured'; ?>">
                    <span class="dashicons dashicons-<?php echo $webhook_url ? 'yes-alt' : 'warning'; ?>"></span>
                    <?php echo $webhook_url
                        ? esc_html__('Webhook URL Configured', 'dd-inventory')
                        : esc_html__('Webhook URL Not Set', 'dd-inventory');
                    ?>
                </div>
                <?php if ($webhook_url) : ?>
                    <button type="button" class="button ddi-test-connection">
                        <?php esc_html_e('Test Connection', 'dd-inventory'); ?>
                    </button>
                <?php endif; ?>
            </div>

            <div class="ddi-status-card">
                <h3><?php esc_html_e('Webhooks', 'dd-inventory'); ?></h3>
                <div class="ddi-status-count"><?php echo esc_html($webhooks_registered); ?>/3</div>
                <p><?php esc_html_e('Webhooks Registered', 'dd-inventory'); ?></p>
                <button type="button" class="button ddi-register-webhooks">
                    <?php esc_html_e('Register Webhooks', 'dd-inventory'); ?>
                </button>
            </div>

            <div class="ddi-status-card">
                <h3><?php esc_html_e('Synced Products', 'dd-inventory'); ?></h3>
                <div class="ddi-status-count"><?php echo esc_html($synced_products); ?></div>
                <p><?php esc_html_e('Products with sync metadata', 'dd-inventory'); ?></p>
            </div>
        </div>
        <?php
    }

    /**
     * Render connection section description
     */
    public function render_connection_section() {
        echo '<p>' . esc_html__('Configure the connection to your inventory tracker system.', 'dd-inventory') . '</p>';
    }

    /**
     * Render sync section description
     */
    public function render_sync_section() {
        echo '<p>' . esc_html__('Configure how products are synced between systems.', 'dd-inventory') . '</p>';
    }

    /**
     * Render webhook URL field
     */
    public function render_webhook_url_field() {
        $settings = get_option('ddi_settings', array());
        $value = isset($settings['webhook_url']) ? $settings['webhook_url'] : '';
        ?>
        <input type="url"
               name="ddi_settings[webhook_url]"
               id="ddi_webhook_url"
               value="<?php echo esc_attr($value); ?>"
               class="regular-text"
               placeholder="https://your-app.vercel.app/api/sync/webhook" />
        <p class="description">
            <?php esc_html_e('The URL where WooCommerce will send order webhooks.', 'dd-inventory'); ?>
        </p>
        <?php
    }

    /**
     * Render lock stock management field
     */
    public function render_lock_stock_field() {
        $settings = get_option('ddi_settings', array());
        $checked = isset($settings['lock_stock_management']) && $settings['lock_stock_management'] === 'yes';
        ?>
        <label>
            <input type="checkbox"
                   name="ddi_settings[lock_stock_management]"
                   value="yes"
                   <?php checked($checked); ?> />
            <?php esc_html_e('Prevent manual stock edits for synced products', 'dd-inventory'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('Since the inventory tracker is the source of truth, this prevents accidental stock changes in WooCommerce.', 'dd-inventory'); ?>
        </p>
        <?php
    }

    /**
     * Render lock SKU editing field
     */
    public function render_lock_sku_field() {
        $settings = get_option('ddi_settings', array());
        $checked = isset($settings['lock_sku_editing']) && $settings['lock_sku_editing'] === 'yes';
        ?>
        <label>
            <input type="checkbox"
                   name="ddi_settings[lock_sku_editing]"
                   value="yes"
                   <?php checked($checked); ?> />
            <?php esc_html_e('Prevent SKU changes for synced products', 'dd-inventory'); ?>
        </label>
        <p class="description">
            <?php esc_html_e('SKUs are used to match products between systems. Changing them could break the sync.', 'dd-inventory'); ?>
        </p>
        <?php
    }

    /**
     * Render auto register webhooks field
     */
    public function render_auto_register_field() {
        $settings = get_option('ddi_settings', array());
        $checked = isset($settings['auto_register_webhooks']) && $settings['auto_register_webhooks'] === 'yes';
        ?>
        <label>
            <input type="checkbox"
                   name="ddi_settings[auto_register_webhooks]"
                   value="yes"
                   <?php checked($checked); ?> />
            <?php esc_html_e('Automatically register webhooks on plugin activation', 'dd-inventory'); ?>
        </label>
        <?php
    }

    /**
     * Render webhook info section
     */
    private function render_webhook_info() {
        $site_url = get_site_url();
        ?>
        <div class="ddi-info-section">
            <h2><?php esc_html_e('Webhook Configuration', 'dd-inventory'); ?></h2>

            <table class="form-table ddi-webhook-info">
                <tr>
                    <th scope="row"><?php esc_html_e('Your Site URL', 'dd-inventory'); ?></th>
                    <td>
                        <code id="ddi-site-url"><?php echo esc_html($site_url); ?></code>
                        <button type="button" class="button button-small ddi-copy-btn" data-target="ddi-site-url">
                            <?php esc_html_e('Copy', 'dd-inventory'); ?>
                        </button>
                        <p class="description">
                            <?php esc_html_e('Use this URL in your inventory tracker sales channel configuration.', 'dd-inventory'); ?>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('REST API Credentials', 'dd-inventory'); ?></th>
                    <td>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=wc-settings&tab=advanced&section=keys')); ?>" class="button">
                            <?php esc_html_e('Manage API Keys', 'dd-inventory'); ?>
                        </a>
                        <p class="description">
                            <?php esc_html_e('Create REST API keys for your inventory tracker to push products/inventory to WooCommerce.', 'dd-inventory'); ?>
                        </p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    /**
     * Render recent logs
     */
    private function render_recent_logs() {
        $logs = get_option('ddi_sync_logs', array());
        $logs = array_reverse(array_slice($logs, -20)); // Get last 20, newest first
        ?>
        <div class="ddi-info-section">
            <h2><?php esc_html_e('Recent Sync Activity', 'dd-inventory'); ?></h2>

            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No sync activity recorded yet.', 'dd-inventory'); ?></p>
            <?php else : ?>
                <table class="widefat ddi-logs-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Time', 'dd-inventory'); ?></th>
                            <th><?php esc_html_e('Type', 'dd-inventory'); ?></th>
                            <th><?php esc_html_e('Event', 'dd-inventory'); ?></th>
                            <th><?php esc_html_e('Message', 'dd-inventory'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($logs as $log) : ?>
                            <tr>
                                <td><?php echo esc_html($log['timestamp']); ?></td>
                                <td><code><?php echo esc_html($log['resource_type']); ?></code></td>
                                <td><code><?php echo esc_html($log['event_type']); ?></code></td>
                                <td><?php echo esc_html($log['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Count registered webhooks
     *
     * @return int
     */
    private function count_registered_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhooks = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
            'status' => 'active',
        ));

        return count($webhooks);
    }

    /**
     * Count synced products
     *
     * @return int
     */
    private function count_synced_products() {
        global $wpdb;

        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = '_ddi_synced' AND meta_value = 'yes'"
        );

        return (int) $count;
    }

    /**
     * AJAX: Test connection
     */
    public function ajax_test_connection() {
        check_ajax_referer('ddi_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'dd-inventory')));
        }

        $settings = get_option('ddi_settings', array());
        $webhook_url = isset($settings['webhook_url']) ? $settings['webhook_url'] : '';

        if (empty($webhook_url)) {
            wp_send_json_error(array('message' => __('Webhook URL not configured.', 'dd-inventory')));
        }

        // Build request body
        $request_body = wp_json_encode(array(
            'ping' => true,
            'source' => get_site_url(),
            'timestamp' => current_time('c'),
        ));

        $headers = array(
            'Content-Type' => 'application/json',
            'X-WC-Webhook-Source' => get_site_url() . '/',
            'X-WC-Webhook-Topic' => 'ping',
        );

        // Log outgoing request for debugging
        error_log('=== DD Inventory Test Connection ===');
        error_log('URL: ' . $webhook_url);
        error_log('Method: POST');
        error_log('Headers: ' . print_r($headers, true));
        error_log('Body: ' . $request_body);

        // Try wp_remote_post first
        $request_args = array(
            'timeout' => 30,
            'headers' => $headers,
            'body' => $request_body,
            'sslverify' => true,
        );

        $response = wp_remote_post($webhook_url, $request_args);

        if (is_wp_error($response)) {
            error_log('WP Error: ' . $response->get_error_message());
            // Try raw curl as fallback
            $curl_result = $this->test_connection_curl($webhook_url, $request_body, $headers);
            if ($curl_result['success']) {
                DDI()->log_sync_event('connection', 'test_success', 'Connection test successful (via curl fallback)');
                wp_send_json_success(array('message' => __('Connection successful!', 'dd-inventory')));
            } else {
                DDI()->log_sync_event('connection', 'test_failed', $curl_result['message']);
                wp_send_json_error(array('message' => $curl_result['message']));
            }
            return;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        // Log response for debugging
        error_log('WP Response Code: ' . $response_code);
        error_log('WP Response Headers: ' . print_r($response_headers, true));
        error_log('WP Response Body: ' . $response_body);

        // If wp_remote_post failed with 500, try raw curl as fallback
        if ($response_code >= 500) {
            error_log('wp_remote_post returned 500, trying raw curl fallback...');
            $curl_result = $this->test_connection_curl($webhook_url, $request_body, $headers);
            if ($curl_result['success']) {
                DDI()->log_sync_event('connection', 'test_success', 'Connection test successful (via curl fallback)');
                wp_send_json_success(array('message' => __('Connection successful!', 'dd-inventory')));
            } else {
                // Return both errors for debugging
                $message = sprintf(
                    __('wp_remote_post: %d - %s | curl: %s', 'dd-inventory'),
                    $response_code,
                    substr($response_body, 0, 100),
                    $curl_result['message']
                );
                DDI()->log_sync_event('connection', 'test_failed', $message, array(
                    'url' => $webhook_url,
                    'wp_response_code' => $response_code,
                    'wp_response_body' => $response_body,
                    'curl_result' => $curl_result,
                ));
                wp_send_json_error(array('message' => $message));
            }
            return;
        }

        error_log('=== End Test Connection ===');

        if ($response_code >= 200 && $response_code < 300) {
            DDI()->log_sync_event('connection', 'test_success', 'Connection test successful');
            wp_send_json_success(array('message' => __('Connection successful!', 'dd-inventory')));
        } else {
            // Include response body in error for debugging
            $message = sprintf(
                __('Server returned status code: %d. Response: %s', 'dd-inventory'),
                $response_code,
                substr($response_body, 0, 200)
            );
            DDI()->log_sync_event('connection', 'test_failed', $message, array(
                'url' => $webhook_url,
                'response_code' => $response_code,
                'response_body' => $response_body,
            ));
            wp_send_json_error(array('message' => $message));
        }
    }

    /**
     * Test connection using raw curl (bypasses WordPress HTTP API)
     *
     * @param string $url      The webhook URL.
     * @param string $body     JSON-encoded request body.
     * @param array  $headers  Request headers.
     * @return array           Result with 'success', 'code', 'body', and 'message' keys.
     */
    private function test_connection_curl($url, $body, $headers) {
        if (!function_exists('curl_init')) {
            return array(
                'success' => false,
                'code' => 0,
                'body' => '',
                'message' => __('curl extension not available', 'dd-inventory'),
            );
        }

        error_log('=== DD Inventory Curl Fallback ===');

        $ch = curl_init($url);

        // Build header array for curl
        $curl_headers = array();
        foreach ($headers as $key => $value) {
            $curl_headers[] = $key . ': ' . $value;
        }

        curl_setopt_array($ch, array(
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $curl_headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_VERBOSE => true,
        ));

        // Capture verbose output for debugging
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);

        $result = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        $curl_errno = curl_errno($ch);

        // Get verbose debug info
        rewind($verbose);
        $verbose_log = stream_get_contents($verbose);
        fclose($verbose);

        curl_close($ch);

        error_log('Curl HTTP code: ' . $httpcode);
        error_log('Curl result: ' . substr($result, 0, 500));
        error_log('Curl error: ' . $curl_error);
        error_log('Curl errno: ' . $curl_errno);
        error_log('Curl verbose: ' . $verbose_log);
        error_log('=== End Curl Fallback ===');

        if ($curl_errno !== 0) {
            return array(
                'success' => false,
                'code' => $httpcode,
                'body' => $result,
                'message' => sprintf(__('Curl error %d: %s', 'dd-inventory'), $curl_errno, $curl_error),
            );
        }

        if ($httpcode >= 200 && $httpcode < 300) {
            return array(
                'success' => true,
                'code' => $httpcode,
                'body' => $result,
                'message' => __('Connection successful', 'dd-inventory'),
            );
        }

        return array(
            'success' => false,
            'code' => $httpcode,
            'body' => $result,
            'message' => sprintf(__('HTTP %d: %s', 'dd-inventory'), $httpcode, substr($result, 0, 200)),
        );
    }

    /**
     * AJAX: Register webhooks
     */
    public function ajax_register_webhooks() {
        check_ajax_referer('ddi_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'dd-inventory')));
        }

        $result = DDI_Webhooks::instance()->register_webhooks();

        if ($result['success']) {
            wp_send_json_success(array('message' => $result['message']));
        } else {
            wp_send_json_error(array('message' => $result['message']));
        }
    }
}
