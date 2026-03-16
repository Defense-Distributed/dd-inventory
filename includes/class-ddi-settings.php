<?php
/**
 * Settings page class
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

class DDI_Settings {

    private static $instance = null;
    private $page_slug = 'dd-inventory';

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_menu_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('wp_ajax_ddi_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_ddi_register_webhooks', array($this, 'ajax_register_webhooks'));
    }

    public function add_menu_page() {
        add_submenu_page(
            'woocommerce',
            __('Inventory Sync', 'dd-inventory'),
            __('Inventory Sync', 'dd-inventory'),
            'manage_woocommerce',
            $this->page_slug,
            array($this, 'render_settings_page')
        );
    }

    public function register_settings() {
        register_setting('ddi_settings_group', 'ddi_settings', array($this, 'sanitize_settings'));

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
            'is_connected' => DDI_Connection::instance()->is_connected(),
            'strings' => array(
                'testing' => __('Testing...', 'dd-inventory'),
                'connecting' => __('Connecting...', 'dd-inventory'),
                'disconnecting' => __('Disconnecting...', 'dd-inventory'),
                'success' => __('Connection successful!', 'dd-inventory'),
                'error' => __('Error: ', 'dd-inventory'),
                'registering' => __('Registering webhooks...', 'dd-inventory'),
                'registered' => __('Webhooks registered!', 'dd-inventory'),
                'confirm_disconnect' => __('Disconnect from inventory system? The WooCommerce API key created for this connection will be deleted.', 'dd-inventory'),
            ),
        ));
    }

    public function sanitize_settings($input) {
        $sanitized = array();

        // Preserve connection fields that aren't in the form
        $existing = get_option('ddi_settings', array());
        foreach (array('webhook_url', 'connected_at', 'store_name', 'api_key_id') as $key) {
            if (isset($existing[$key])) {
                $sanitized[$key] = $existing[$key];
            }
        }

        $sanitized['lock_stock_management'] = isset($input['lock_stock_management']) ? 'yes' : 'no';
        $sanitized['lock_sku_editing'] = isset($input['lock_sku_editing']) ? 'yes' : 'no';
        $sanitized['auto_register_webhooks'] = isset($input['auto_register_webhooks']) ? 'yes' : 'no';

        return $sanitized;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'dd-inventory'));
        }

        $connection = DDI_Connection::instance();
        $is_connected = $connection->is_connected();
        $info = $connection->get_connection_info();
        ?>
        <div class="wrap ddi-settings-wrap">
            <h1><?php esc_html_e('Inventory Sync', 'dd-inventory'); ?></h1>

            <?php $this->render_connection_card($is_connected, $info); ?>

            <?php if ($is_connected) : ?>
                <?php $this->render_status_cards(); ?>

                <form method="post" action="options.php">
                    <?php
                    settings_fields('ddi_settings_group');
                    do_settings_sections($this->page_slug);
                    submit_button();
                    ?>
                </form>

                <?php $this->render_recent_logs(); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_connection_card($is_connected, $info) {
        ?>
        <div class="ddi-connection-card">
            <?php if ($is_connected) : ?>
                <div class="ddi-connection-status connected">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <div>
                        <strong><?php esc_html_e('Connected', 'dd-inventory'); ?></strong>
                        <?php if (!empty($info['store_name'])) : ?>
                            <span class="ddi-connection-detail"><?php echo esc_html($info['store_name']); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($info['connected_at'])) : ?>
                            <span class="ddi-connection-detail">
                                <?php
                                printf(
                                    esc_html__('Since %s', 'dd-inventory'),
                                    esc_html(date_i18n(get_option('date_format'), strtotime($info['connected_at'])))
                                );
                                ?>
                            </span>
                        <?php endif; ?>
                    </div>
                    <div class="ddi-connection-actions">
                        <button type="button" class="button ddi-test-connection">
                            <?php esc_html_e('Test', 'dd-inventory'); ?>
                        </button>
                        <button type="button" class="button ddi-disconnect" id="ddi-disconnect-btn">
                            <?php esc_html_e('Disconnect', 'dd-inventory'); ?>
                        </button>
                    </div>
                </div>
            <?php else : ?>
                <div class="ddi-connection-setup">
                    <h2><?php esc_html_e('Connect to Inventory System', 'dd-inventory'); ?></h2>
                    <p class="description">
                        <?php esc_html_e('Paste the connection token from your inventory app to connect this store.', 'dd-inventory'); ?>
                    </p>
                    <div class="ddi-token-input-group">
                        <input type="text"
                               id="ddi-connection-token"
                               class="large-text"
                               placeholder="ddi_..."
                               autocomplete="off" />
                        <button type="button" class="button button-primary" id="ddi-connect-btn">
                            <?php esc_html_e('Connect', 'dd-inventory'); ?>
                        </button>
                    </div>
                    <div id="ddi-connect-status" class="ddi-connect-status" style="display:none;"></div>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_status_cards() {
        $webhooks_registered = $this->count_registered_webhooks();
        $synced_products = $this->count_synced_products();
        ?>
        <div class="ddi-status-cards">
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

    public function render_sync_section() {
        echo '<p>' . esc_html__('Configure how products are synced between systems.', 'dd-inventory') . '</p>';
    }

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
            <?php esc_html_e('Since the inventory system is the source of truth, this prevents accidental stock changes in WooCommerce.', 'dd-inventory'); ?>
        </p>
        <?php
    }

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

    private function render_recent_logs() {
        $logs = get_option('ddi_sync_logs', array());
        $logs = array_reverse(array_slice($logs, -20));
        ?>
        <div class="ddi-info-section">
            <h2><?php esc_html_e('Recent Activity', 'dd-inventory'); ?></h2>

            <?php if (empty($logs)) : ?>
                <p><?php esc_html_e('No activity recorded yet.', 'dd-inventory'); ?></p>
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

    private function count_registered_webhooks() {
        $data_store = WC_Data_Store::load('webhook');
        $webhooks = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
            'status' => 'active',
        ));
        return count($webhooks);
    }

    private function count_synced_products() {
        global $wpdb;
        $count = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = '_ddi_synced' AND meta_value = 'yes'"
        );
        return (int) $count;
    }

    public function ajax_test_connection() {
        check_ajax_referer('ddi_admin_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'dd-inventory')));
        }

        $settings = get_option('ddi_settings', array());
        $webhook_url = isset($settings['webhook_url']) ? $settings['webhook_url'] : '';

        if (empty($webhook_url)) {
            wp_send_json_error(array('message' => __('Not connected.', 'dd-inventory')));
        }

        $response = wp_remote_post($webhook_url, array(
            'timeout' => 30,
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-WC-Webhook-Source' => get_site_url() . '/',
                'X-WC-Webhook-Topic' => 'ping',
            ),
            'body' => wp_json_encode(array(
                'ping' => true,
                'source' => get_site_url(),
                'timestamp' => current_time('c'),
            )),
            'sslverify' => true,
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()));
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            DDI()->log_sync_event('connection', 'test_success', 'Connection test successful');
            wp_send_json_success(array('message' => __('Connection successful!', 'dd-inventory')));
        } else {
            $body = wp_remote_retrieve_body($response);
            wp_send_json_error(array('message' => sprintf(__('HTTP %d: %s', 'dd-inventory'), $code, substr($body, 0, 200))));
        }
    }

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
