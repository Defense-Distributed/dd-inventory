<?php
/**
 * Product sync class - handles SKU protection and stock locking
 *
 * @package DD_Inventory
 */

defined('ABSPATH') || exit;

/**
 * Product Sync class
 */
class DDI_Product_Sync {

    /**
     * Single instance
     *
     * @var DDI_Product_Sync
     */
    private static $instance = null;

    /**
     * Get the single instance
     *
     * @return DDI_Product_Sync
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
        // Mark products as synced when created via REST API
        add_action('woocommerce_rest_insert_product_object', array($this, 'mark_product_synced'), 10, 3);

        // Lock SKU editing for synced products
        add_filter('woocommerce_product_get_sku', array($this, 'maybe_lock_sku'), 10, 2);
        add_action('woocommerce_product_options_sku', array($this, 'add_sku_lock_notice'));
        add_filter('woocommerce_admin_disabled_sku_field', array($this, 'disable_sku_field'));

        // Lock stock management for synced products
        add_action('woocommerce_product_options_stock_fields', array($this, 'add_stock_lock_notice'));
        add_filter('woocommerce_product_get_manage_stock', array($this, 'force_manage_stock'), 10, 2);
        add_action('admin_footer', array($this, 'add_stock_lock_script'));

        // Add custom meta box for sync status
        add_action('add_meta_boxes', array($this, 'add_sync_meta_box'));

        // Log stock changes for synced products
        add_action('woocommerce_product_set_stock', array($this, 'maybe_prevent_stock_change'), 10, 1);

        // Add bulk action to mark products as synced/unsynced
        add_filter('bulk_actions-edit-product', array($this, 'add_bulk_actions'));
        add_filter('handle_bulk_actions-edit-product', array($this, 'handle_bulk_actions'), 10, 3);
        add_action('admin_notices', array($this, 'bulk_action_notices'));

        // Add synced column to product list
        add_filter('manage_product_posts_columns', array($this, 'add_synced_column'));
        add_action('manage_product_posts_custom_column', array($this, 'render_synced_column'), 10, 2);

        // REST API: Mark product as synced via custom endpoint
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    /**
     * Mark product as synced when created via REST API
     *
     * @param WC_Product $product Product object
     * @param WP_REST_Request $request Request object
     * @param bool $creating Whether creating a new product
     */
    public function mark_product_synced($product, $request, $creating) {
        // Check for custom header or meta to mark as synced
        $headers = $request->get_headers();

        // Check for our custom header
        if (isset($headers['x_dd_inventory_sync']) || isset($headers['X-DD-Inventory-Sync'])) {
            $this->set_synced_status($product->get_id(), true);
            DDI()->log_sync_event('product', 'marked_synced', sprintf(
                'Product "%s" (ID: %d, SKU: %s) marked as synced via REST API',
                $product->get_name(),
                $product->get_id(),
                $product->get_sku()
            ));
        }

        // Also check if sync meta was passed
        $meta_data = $request->get_param('meta_data');
        if ($meta_data && is_array($meta_data)) {
            foreach ($meta_data as $meta) {
                if (isset($meta['key']) && $meta['key'] === '_ddi_synced' && $meta['value'] === 'yes') {
                    $this->set_synced_status($product->get_id(), true);
                    break;
                }
            }
        }
    }

    /**
     * Set synced status for a product
     *
     * @param int $product_id Product ID
     * @param bool $synced Whether product is synced
     */
    public function set_synced_status($product_id, $synced = true) {
        update_post_meta($product_id, '_ddi_synced', $synced ? 'yes' : 'no');
        update_post_meta($product_id, '_ddi_synced_at', current_time('mysql'));
    }

    /**
     * Check if product is synced
     *
     * @param int $product_id Product ID
     * @return bool
     */
    public function is_product_synced($product_id) {
        return get_post_meta($product_id, '_ddi_synced', true) === 'yes';
    }

    /**
     * Maybe lock SKU (prevents modification via filters)
     *
     * @param string $sku Product SKU
     * @param WC_Product $product Product object
     * @return string
     */
    public function maybe_lock_sku($sku, $product) {
        // This filter doesn't prevent changes, just used for tracking
        return $sku;
    }

    /**
     * Add SKU lock notice in product edit screen
     */
    public function add_sku_lock_notice() {
        global $post;

        if (!$post || !$this->is_product_synced($post->ID)) {
            return;
        }

        $settings = get_option('ddi_settings', array());
        $lock_sku = isset($settings['lock_sku_editing']) && $settings['lock_sku_editing'] === 'yes';

        if ($lock_sku) {
            ?>
            <p class="form-field ddi-sku-notice">
                <span class="dashicons dashicons-lock" style="color: #d63638;"></span>
                <em><?php esc_html_e('SKU is locked because this product is synced from DD Inventory. Changing the SKU will break the sync.', 'dd-inventory'); ?></em>
            </p>
            <?php
        }
    }

    /**
     * Disable SKU field for synced products
     *
     * @param bool $disabled Whether field is disabled
     * @return bool
     */
    public function disable_sku_field($disabled) {
        global $post;

        if (!$post || !$this->is_product_synced($post->ID)) {
            return $disabled;
        }

        $settings = get_option('ddi_settings', array());
        $lock_sku = isset($settings['lock_sku_editing']) && $settings['lock_sku_editing'] === 'yes';

        return $lock_sku ? true : $disabled;
    }

    /**
     * Add stock lock notice in product edit screen
     */
    public function add_stock_lock_notice() {
        global $post;

        if (!$post || !$this->is_product_synced($post->ID)) {
            return;
        }

        $settings = get_option('ddi_settings', array());
        $lock_stock = isset($settings['lock_stock_management']) && $settings['lock_stock_management'] === 'yes';

        if ($lock_stock) {
            ?>
            <p class="form-field ddi-stock-notice" style="padding: 10px; background: #fff3cd; border-left: 4px solid #ffc107;">
                <span class="dashicons dashicons-info" style="color: #856404;"></span>
                <strong><?php esc_html_e('Stock Managed by DD Inventory', 'dd-inventory'); ?></strong><br>
                <em><?php esc_html_e('Stock quantity is controlled by your external inventory system. Changes made here may be overwritten.', 'dd-inventory'); ?></em>
            </p>
            <?php
        }
    }

    /**
     * Force manage_stock to true for synced products
     *
     * @param bool $manage_stock Current value
     * @param WC_Product $product Product object
     * @return bool
     */
    public function force_manage_stock($manage_stock, $product) {
        if ($this->is_product_synced($product->get_id())) {
            return true; // Always manage stock for synced products
        }
        return $manage_stock;
    }

    /**
     * Add script to disable stock fields for synced products
     */
    public function add_stock_lock_script() {
        global $post, $pagenow;

        if ($pagenow !== 'post.php' || get_post_type() !== 'product') {
            return;
        }

        if (!$post || !$this->is_product_synced($post->ID)) {
            return;
        }

        $settings = get_option('ddi_settings', array());
        $lock_stock = isset($settings['lock_stock_management']) && $settings['lock_stock_management'] === 'yes';

        if (!$lock_stock) {
            return;
        }
        ?>
        <script type="text/javascript">
        jQuery(function($) {
            // Disable stock quantity field
            $('#_stock').prop('readonly', true).css('background-color', '#f0f0f0');

            // Add visual indicator
            $('#_stock').closest('.form-field').append(
                '<span class="dashicons dashicons-lock" style="color: #d63638; margin-left: 5px;" title="<?php esc_attr_e('Managed by DD Inventory', 'dd-inventory'); ?>"></span>'
            );

            // Disable manage stock checkbox
            $('#_manage_stock').prop('disabled', true);

            // Warn on form submission if stock was changed
            var originalStock = $('#_stock').val();
            $('form#post').on('submit', function() {
                if ($('#_stock').val() !== originalStock) {
                    if (!confirm('<?php echo esc_js(__('Warning: This product\'s stock is managed by DD Inventory. Your changes may be overwritten. Continue?', 'dd-inventory')); ?>')) {
                        return false;
                    }
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Log stock changes for synced products
     *
     * @param WC_Product $product Product object
     */
    public function maybe_prevent_stock_change($product) {
        // Don't log changes from REST API (that's how sync works)
        if (defined('REST_REQUEST') && REST_REQUEST) {
            return;
        }

        // Log stock changes for synced products
        if ($this->is_product_synced($product->get_id())) {
            DDI()->log_sync_event('product', 'stock_changed', sprintf(
                'Stock for "%s" (SKU: %s) set to %d via admin',
                $product->get_name(),
                $product->get_sku(),
                $product->get_stock_quantity()
            ));
        }
    }

    /**
     * Add sync status meta box
     */
    public function add_sync_meta_box() {
        add_meta_box(
            'ddi_sync_status',
            __('DD Inventory Sync', 'dd-inventory'),
            array($this, 'render_sync_meta_box'),
            'product',
            'side',
            'default'
        );
    }

    /**
     * Render sync status meta box
     *
     * @param WP_Post $post Post object
     */
    public function render_sync_meta_box($post) {
        $is_synced = $this->is_product_synced($post->ID);
        $synced_at = get_post_meta($post->ID, '_ddi_synced_at', true);
        ?>
        <div class="ddi-sync-status-box">
            <p>
                <strong><?php esc_html_e('Status:', 'dd-inventory'); ?></strong>
                <?php if ($is_synced) : ?>
                    <span class="ddi-badge ddi-badge-synced">
                        <span class="dashicons dashicons-yes-alt"></span>
                        <?php esc_html_e('Synced', 'dd-inventory'); ?>
                    </span>
                <?php else : ?>
                    <span class="ddi-badge ddi-badge-not-synced">
                        <span class="dashicons dashicons-minus"></span>
                        <?php esc_html_e('Not Synced', 'dd-inventory'); ?>
                    </span>
                <?php endif; ?>
            </p>

            <?php if ($is_synced && $synced_at) : ?>
                <p>
                    <strong><?php esc_html_e('Last Synced:', 'dd-inventory'); ?></strong><br>
                    <?php echo esc_html($synced_at); ?>
                </p>
            <?php endif; ?>

            <p>
                <label>
                    <input type="checkbox"
                           name="_ddi_synced"
                           value="yes"
                           <?php checked($is_synced); ?> />
                    <?php esc_html_e('Mark as synced product', 'dd-inventory'); ?>
                </label>
            </p>
            <p class="description">
                <?php esc_html_e('Synced products have SKU and stock fields locked.', 'dd-inventory'); ?>
            </p>
        </div>

        <style>
            .ddi-badge {
                display: inline-flex;
                align-items: center;
                gap: 4px;
                padding: 2px 8px;
                border-radius: 3px;
                font-size: 12px;
            }
            .ddi-badge-synced {
                background: #d4edda;
                color: #155724;
            }
            .ddi-badge-not-synced {
                background: #f8f9fa;
                color: #6c757d;
            }
        </style>
        <?php
        wp_nonce_field('ddi_sync_status_nonce', 'ddi_sync_nonce');
    }

    /**
     * Save sync status meta
     *
     * @param int $post_id Post ID
     */
    public function save_sync_meta($post_id) {
        if (!isset($_POST['ddi_sync_nonce']) || !wp_verify_nonce($_POST['ddi_sync_nonce'], 'ddi_sync_status_nonce')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_product', $post_id)) {
            return;
        }

        $synced = isset($_POST['_ddi_synced']) && $_POST['_ddi_synced'] === 'yes';
        $this->set_synced_status($post_id, $synced);
    }

    /**
     * Add bulk actions
     *
     * @param array $bulk_actions Current bulk actions
     * @return array
     */
    public function add_bulk_actions($bulk_actions) {
        $bulk_actions['ddi_mark_synced'] = __('Mark as Synced (DD Inventory)', 'dd-inventory');
        $bulk_actions['ddi_mark_unsynced'] = __('Mark as Not Synced (DD Inventory)', 'dd-inventory');
        return $bulk_actions;
    }

    /**
     * Handle bulk actions
     *
     * @param string $redirect_to Redirect URL
     * @param string $action Action name
     * @param array $post_ids Post IDs
     * @return string
     */
    public function handle_bulk_actions($redirect_to, $action, $post_ids) {
        if ($action === 'ddi_mark_synced') {
            foreach ($post_ids as $post_id) {
                $this->set_synced_status($post_id, true);
            }
            $redirect_to = add_query_arg('ddi_marked_synced', count($post_ids), $redirect_to);
        } elseif ($action === 'ddi_mark_unsynced') {
            foreach ($post_ids as $post_id) {
                $this->set_synced_status($post_id, false);
            }
            $redirect_to = add_query_arg('ddi_marked_unsynced', count($post_ids), $redirect_to);
        }
        return $redirect_to;
    }

    /**
     * Show bulk action notices
     */
    public function bulk_action_notices() {
        if (!empty($_REQUEST['ddi_marked_synced'])) {
            $count = intval($_REQUEST['ddi_marked_synced']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>' .
                esc_html(_n('%d product marked as synced.', '%d products marked as synced.', $count, 'dd-inventory')) .
                '</p></div>',
                $count
            );
        }

        if (!empty($_REQUEST['ddi_marked_unsynced'])) {
            $count = intval($_REQUEST['ddi_marked_unsynced']);
            printf(
                '<div class="notice notice-success is-dismissible"><p>' .
                esc_html(_n('%d product marked as not synced.', '%d products marked as not synced.', $count, 'dd-inventory')) .
                '</p></div>',
                $count
            );
        }
    }

    /**
     * Add synced column to product list
     *
     * @param array $columns Current columns
     * @return array
     */
    public function add_synced_column($columns) {
        $new_columns = array();

        foreach ($columns as $key => $value) {
            $new_columns[$key] = $value;

            // Add after SKU column
            if ($key === 'sku') {
                $new_columns['ddi_synced'] = '<span class="dashicons dashicons-update" title="' .
                    esc_attr__('DD Inventory Sync', 'dd-inventory') . '"></span>';
            }
        }

        return $new_columns;
    }

    /**
     * Render synced column
     *
     * @param string $column Column name
     * @param int $post_id Post ID
     */
    public function render_synced_column($column, $post_id) {
        if ($column !== 'ddi_synced') {
            return;
        }

        if ($this->is_product_synced($post_id)) {
            echo '<span class="dashicons dashicons-yes-alt" style="color: #46b450;" title="' .
                esc_attr__('Synced with DD Inventory', 'dd-inventory') . '"></span>';
        } else {
            echo '<span class="dashicons dashicons-minus" style="color: #999;" title="' .
                esc_attr__('Not synced', 'dd-inventory') . '"></span>';
        }
    }

    /**
     * Register REST API routes
     */
    public function register_rest_routes() {
        register_rest_route('dd-inventory/v1', '/products/(?P<id>\d+)/sync-status', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_set_sync_status'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'id' => array(
                    'validate_callback' => function($param) {
                        return is_numeric($param);
                    },
                ),
                'synced' => array(
                    'required' => true,
                    'type' => 'boolean',
                ),
            ),
        ));

        register_rest_route('dd-inventory/v1', '/products/sync-status/batch', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_batch_set_sync_status'),
            'permission_callback' => array($this, 'rest_permission_check'),
            'args' => array(
                'skus' => array(
                    'required' => true,
                    'type' => 'array',
                ),
                'synced' => array(
                    'required' => true,
                    'type' => 'boolean',
                ),
            ),
        ));
    }

    /**
     * REST API permission check
     *
     * @return bool
     */
    public function rest_permission_check() {
        return current_user_can('edit_products');
    }

    /**
     * REST: Set sync status for a product
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function rest_set_sync_status($request) {
        $product_id = $request->get_param('id');
        $synced = $request->get_param('synced');

        $product = wc_get_product($product_id);
        if (!$product) {
            return new WP_REST_Response(array(
                'success' => false,
                'message' => __('Product not found', 'dd-inventory'),
            ), 404);
        }

        $this->set_synced_status($product_id, $synced);

        return new WP_REST_Response(array(
            'success' => true,
            'product_id' => $product_id,
            'synced' => $synced,
        ));
    }

    /**
     * REST: Batch set sync status by SKU
     *
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response
     */
    public function rest_batch_set_sync_status($request) {
        $skus = $request->get_param('skus');
        $synced = $request->get_param('synced');

        $results = array();
        foreach ($skus as $sku) {
            $product_id = wc_get_product_id_by_sku($sku);
            if ($product_id) {
                $this->set_synced_status($product_id, $synced);
                $results[] = array(
                    'sku' => $sku,
                    'product_id' => $product_id,
                    'status' => 'updated',
                );
            } else {
                $results[] = array(
                    'sku' => $sku,
                    'product_id' => null,
                    'status' => 'not_found',
                );
            }
        }

        return new WP_REST_Response(array(
            'success' => true,
            'results' => $results,
        ));
    }
}

// Hook to save sync meta
add_action('save_post_product', array(DDI_Product_Sync::class, 'instance'));
add_action('woocommerce_process_product_meta', function($post_id) {
    DDI_Product_Sync::instance()->save_sync_meta($post_id);
});
