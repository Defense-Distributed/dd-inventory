<?php
/**
 * Plugin Name: DD Inventory
 * Plugin URI: https://github.com/Defense-Distributed/dd-inventory
 * Description: Syncs WooCommerce inventory with an external inventory management system. The inventory tracker pushes products/inventory to WooCommerce, and WooCommerce sends order webhooks back.
 * Version: 1.0.0
 * Author: Defense Distributed
 * Author URI: https://defcad.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dd-inventory
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * WC tested up to: 8.5
 */

defined('ABSPATH') || exit;

// Define plugin constants
define('DDI_VERSION', '1.0.0');
define('DDI_PLUGIN_FILE', __FILE__);
define('DDI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DDI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DDI_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Plugin Update Checker - for automatic updates from GitHub
require_once DDI_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$ddi_update_checker = PucFactory::buildUpdateChecker(
    'https://github.com/Defense-Distributed/dd-inventory',
    __FILE__,
    'dd-inventory'
);

// Set the branch that contains the stable release
$ddi_update_checker->setBranch('main');

// Enable release assets (downloads from GitHub releases)
$ddi_update_checker->getVcsApi()->enableReleaseAssets();

/**
 * Main plugin class
 */
final class DD_Inventory {

    /**
     * Single instance of the class
     *
     * @var DD_Inventory
     */
    private static $instance = null;

    /**
     * Get the single instance
     *
     * @return DD_Inventory
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
        $this->init_hooks();
    }

    /**
     * Initialize hooks
     */
    private function init_hooks() {
        // Check for WooCommerce
        add_action('plugins_loaded', array($this, 'check_woocommerce'));

        // Activation/deactivation hooks
        register_activation_hook(DDI_PLUGIN_FILE, array($this, 'activate'));
        register_deactivation_hook(DDI_PLUGIN_FILE, array($this, 'deactivate'));

        // Initialize plugin after WooCommerce is loaded
        add_action('woocommerce_init', array($this, 'init'));

        // Declare HPOS compatibility
        add_action('before_woocommerce_init', array($this, 'declare_hpos_compatibility'));
    }

    /**
     * Check if WooCommerce is active
     */
    public function check_woocommerce() {
        if (!class_exists('WooCommerce')) {
            add_action('admin_notices', array($this, 'woocommerce_missing_notice'));
            return;
        }
    }

    /**
     * WooCommerce missing notice
     */
    public function woocommerce_missing_notice() {
        ?>
        <div class="notice notice-error">
            <p><?php esc_html_e('DD Inventory requires WooCommerce to be installed and activated.', 'dd-inventory'); ?></p>
        </div>
        <?php
    }

    /**
     * Declare High-Performance Order Storage compatibility
     */
    public function declare_hpos_compatibility() {
        if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
            \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', DDI_PLUGIN_FILE, true);
        }
    }

    /**
     * Initialize the plugin
     */
    public function init() {
        $this->includes();
        $this->init_classes();
    }

    /**
     * Include required files
     */
    private function includes() {
        require_once DDI_PLUGIN_DIR . 'includes/class-ddi-settings.php';
        require_once DDI_PLUGIN_DIR . 'includes/class-ddi-webhooks.php';
        require_once DDI_PLUGIN_DIR . 'includes/class-ddi-product-sync.php';
        require_once DDI_PLUGIN_DIR . 'includes/class-ddi-dashboard.php';
    }

    /**
     * Initialize classes
     */
    private function init_classes() {
        DDI_Settings::instance();
        DDI_Webhooks::instance();
        DDI_Product_Sync::instance();
        DDI_Dashboard::instance();
    }

    /**
     * Plugin activation
     */
    public function activate() {
        // Generate webhook secret if not exists
        if (!get_option('ddi_webhook_secret')) {
            update_option('ddi_webhook_secret', wp_generate_password(32, false));
        }

        // Initialize default settings
        $default_settings = array(
            'webhook_url' => '',
            'lock_stock_management' => 'yes',
            'lock_sku_editing' => 'yes',
            'auto_register_webhooks' => 'yes',
        );

        if (!get_option('ddi_settings')) {
            update_option('ddi_settings', $default_settings);
        }

        // Schedule webhook registration
        if (!wp_next_scheduled('ddi_register_webhooks')) {
            wp_schedule_single_event(time() + 5, 'ddi_register_webhooks');
        }

        // Log activation
        $this->log_sync_event('plugin', 'activated', 'Plugin activated');

        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Plugin deactivation
     */
    public function deactivate() {
        // Clear scheduled events
        wp_clear_scheduled_hook('ddi_register_webhooks');

        // Log deactivation
        $this->log_sync_event('plugin', 'deactivated', 'Plugin deactivated');
    }

    /**
     * Log sync events
     *
     * @param string $resource_type Resource type
     * @param string $event_type Event type
     * @param string $message Log message
     * @param array  $data Additional data
     */
    public function log_sync_event($resource_type, $event_type, $message, $data = array()) {
        $logs = get_option('ddi_sync_logs', array());

        // Keep only last 100 logs
        if (count($logs) >= 100) {
            $logs = array_slice($logs, -99);
        }

        $logs[] = array(
            'timestamp' => current_time('mysql'),
            'resource_type' => $resource_type,
            'event_type' => $event_type,
            'message' => $message,
            'data' => $data,
        );

        update_option('ddi_sync_logs', $logs);
    }

    /**
     * Get plugin option
     *
     * @param string $key Option key
     * @param mixed  $default Default value
     * @return mixed
     */
    public function get_option($key, $default = '') {
        $settings = get_option('ddi_settings', array());
        return isset($settings[$key]) ? $settings[$key] : $default;
    }

    /**
     * Update plugin option
     *
     * @param string $key Option key
     * @param mixed  $value Option value
     */
    public function update_option($key, $value) {
        $settings = get_option('ddi_settings', array());
        $settings[$key] = $value;
        update_option('ddi_settings', $settings);
    }
}

/**
 * Get the main plugin instance
 *
 * @return DD_Inventory
 */
function DDI() {
    return DD_Inventory::instance();
}

// Initialize the plugin
DDI();
