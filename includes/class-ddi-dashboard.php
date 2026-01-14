<?php
/**
 * Dashboard widget class
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

/**
 * Dashboard class
 */
class DDI_Dashboard {

    /**
     * Single instance
     *
     * @var DDI_Dashboard
     */
    private static $instance = null;

    /**
     * Get the single instance
     *
     * @return DDI_Dashboard
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
        add_action('wp_dashboard_setup', array($this, 'add_dashboard_widget'));
        add_action('woocommerce_admin_dashboard_status_widget', array($this, 'add_wc_dashboard_section'));
    }

    /**
     * Add dashboard widget
     */
    public function add_dashboard_widget() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        wp_add_dashboard_widget(
            'ddi_dashboard_widget',
            __('DD Inventory Sync Status', 'dd-inventory'),
            array($this, 'render_dashboard_widget')
        );
    }

    /**
     * Render dashboard widget
     */
    public function render_dashboard_widget() {
        $stats = $this->get_sync_stats();
        $recent_logs = $this->get_recent_logs(5);
        ?>
        <div class="ddi-dashboard-widget">
            <style>
                .ddi-dashboard-widget .ddi-stats-grid {
                    display: grid;
                    grid-template-columns: repeat(2, 1fr);
                    gap: 15px;
                    margin-bottom: 20px;
                }
                .ddi-dashboard-widget .ddi-stat-box {
                    background: #f8f9fa;
                    padding: 15px;
                    border-radius: 4px;
                    text-align: center;
                }
                .ddi-dashboard-widget .ddi-stat-value {
                    font-size: 24px;
                    font-weight: 600;
                    color: #1d2327;
                }
                .ddi-dashboard-widget .ddi-stat-label {
                    font-size: 12px;
                    color: #646970;
                    text-transform: uppercase;
                }
                .ddi-dashboard-widget .ddi-status-indicator {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    margin-bottom: 10px;
                }
                .ddi-dashboard-widget .ddi-status-dot {
                    width: 10px;
                    height: 10px;
                    border-radius: 50%;
                }
                .ddi-dashboard-widget .ddi-status-dot.connected { background: #00a32a; }
                .ddi-dashboard-widget .ddi-status-dot.disconnected { background: #d63638; }
                .ddi-dashboard-widget .ddi-log-entry {
                    padding: 8px 0;
                    border-bottom: 1px solid #eee;
                    font-size: 12px;
                }
                .ddi-dashboard-widget .ddi-log-entry:last-child {
                    border-bottom: none;
                }
                .ddi-dashboard-widget .ddi-log-time {
                    color: #646970;
                }
                .ddi-dashboard-widget .ddi-log-message {
                    color: #1d2327;
                }
            </style>

            <div class="ddi-status-indicator">
                <span class="ddi-status-dot <?php echo $stats['is_connected'] ? 'connected' : 'disconnected'; ?>"></span>
                <span>
                    <?php echo $stats['is_connected']
                        ? esc_html__('Connected', 'dd-inventory')
                        : esc_html__('Not Configured', 'dd-inventory');
                    ?>
                </span>
            </div>

            <div class="ddi-stats-grid">
                <div class="ddi-stat-box">
                    <div class="ddi-stat-value"><?php echo esc_html($stats['synced_products']); ?></div>
                    <div class="ddi-stat-label"><?php esc_html_e('Synced Products', 'dd-inventory'); ?></div>
                </div>
                <div class="ddi-stat-box">
                    <div class="ddi-stat-value"><?php echo esc_html($stats['webhooks_active']); ?>/3</div>
                    <div class="ddi-stat-label"><?php esc_html_e('Active Webhooks', 'dd-inventory'); ?></div>
                </div>
            </div>

            <?php if (!empty($recent_logs)) : ?>
                <h4><?php esc_html_e('Recent Activity', 'dd-inventory'); ?></h4>
                <div class="ddi-recent-logs">
                    <?php foreach ($recent_logs as $log) : ?>
                        <div class="ddi-log-entry">
                            <span class="ddi-log-time">
                                <?php echo esc_html(human_time_diff(strtotime($log['timestamp']), current_time('timestamp'))); ?>
                                <?php esc_html_e('ago', 'dd-inventory'); ?>
                            </span>
                            &mdash;
                            <span class="ddi-log-message"><?php echo esc_html($log['message']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <p style="margin-top: 15px;">
                <a href="<?php echo esc_url(admin_url('admin.php?page=dd-inventory')); ?>" class="button">
                    <?php esc_html_e('View Settings', 'dd-inventory'); ?>
                </a>
            </p>
        </div>
        <?php
    }

    /**
     * Add section to WooCommerce dashboard status widget
     */
    public function add_wc_dashboard_section() {
        $stats = $this->get_sync_stats();
        ?>
        <li class="ddi-sync-status">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dd-inventory')); ?>">
                <?php
                printf(
                    /* translators: %d: number of synced products */
                    esc_html__('%d synced products', 'dd-inventory'),
                    $stats['synced_products']
                );
                ?>
            </a>
        </li>
        <?php
    }

    /**
     * Get sync statistics
     *
     * @return array
     */
    private function get_sync_stats() {
        global $wpdb;

        $settings = get_option('ddi_settings', array());
        $is_connected = !empty($settings['webhook_url']);

        // Count synced products
        $synced_products = $wpdb->get_var(
            "SELECT COUNT(DISTINCT post_id) FROM {$wpdb->postmeta}
             WHERE meta_key = '_ddi_synced' AND meta_value = 'yes'"
        );

        // Count active webhooks
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
            'status' => 'active',
        ));

        // Get last sync time
        $logs = get_option('ddi_sync_logs', array());
        $last_sync = null;

        foreach (array_reverse($logs) as $log) {
            if (in_array($log['resource_type'], array('product', 'order', 'inventory'))) {
                $last_sync = $log['timestamp'];
                break;
            }
        }

        // Count sync errors in last 24 hours
        $error_count = 0;
        $yesterday = strtotime('-24 hours');

        foreach ($logs as $log) {
            if (strtotime($log['timestamp']) > $yesterday &&
                isset($log['event_type']) &&
                strpos($log['event_type'], 'error') !== false) {
                $error_count++;
            }
        }

        return array(
            'is_connected' => $is_connected,
            'synced_products' => (int) $synced_products,
            'webhooks_active' => count($webhook_ids),
            'last_sync' => $last_sync,
            'errors_24h' => $error_count,
        );
    }

    /**
     * Get recent sync logs
     *
     * @param int $count Number of logs to return
     * @return array
     */
    private function get_recent_logs($count = 10) {
        $logs = get_option('ddi_sync_logs', array());
        return array_slice(array_reverse($logs), 0, $count);
    }
}
