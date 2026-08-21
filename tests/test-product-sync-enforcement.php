<?php
/**
 * Standalone tests for DDI_Product_Sync stock-field enforcement.
 *
 * No PHPUnit / WP test suite required — run with:
 *   php tests/test-product-sync-enforcement.php
 *
 * Stubs the minimal WordPress/WooCommerce surface the class touches, then
 * exercises force_manage_stock() and enforce_synced_fields() directly.
 */

error_reporting(E_ALL & ~E_DEPRECATED);

// ---------------------------------------------------------------------------
// WordPress / WooCommerce stubs
// ---------------------------------------------------------------------------

define('ABSPATH', __DIR__ . '/');

$GLOBALS['ddi_test_meta'] = array();
$GLOBALS['ddi_test_options'] = array(
    'ddi_settings' => array('lock_stock_management' => 'yes', 'lock_sku_editing' => 'yes'),
);
$GLOBALS['ddi_test_saved_products'] = array();
$GLOBALS['ddi_test_logged_events'] = array();
$GLOBALS['ddi_test_is_admin'] = true;

function add_action(...$args) {}
function add_filter(...$args) {}
function get_post_meta($post_id, $key, $single) {
    return $GLOBALS['ddi_test_meta'][$post_id][$key] ?? '';
}
function update_post_meta($post_id, $key, $value) {
    $GLOBALS['ddi_test_meta'][$post_id][$key] = $value;
}
function get_option($name, $default = false) {
    return $GLOBALS['ddi_test_options'][$name] ?? $default;
}
function current_time($type) {
    return $type === 'mysql' ? '2026-08-21 00:00:00' : 0;
}
function is_admin() {
    return $GLOBALS['ddi_test_is_admin'];
}
function wc_get_product($product_id) {
    return $GLOBALS['ddi_test_saved_products'][$product_id] ?? false;
}
function __($text, $domain = null) { return $text; }

class DDI_Test_Logger {
    public function log_sync_event($resource_type, $event_type, $message, $data = array()) {
        $GLOBALS['ddi_test_logged_events'][] = $event_type;
    }
}
function DDI() {
    static $logger = null;
    if ($logger === null) $logger = new DDI_Test_Logger();
    return $logger;
}

/** Minimal WC_Product: props with 'edit'-context getters, type checking. */
class WC_Product {
    private $props;
    public function __construct(array $props = array()) {
        $this->props = array_merge(array(
            'id' => 0,
            'type' => 'simple',
            'name' => 'Test Product',
            'sku' => 'TEST-1',
            'stock_quantity' => null,
            'manage_stock' => false,
            'backorders' => 'no',
            'stock_status' => 'instock',
            'regular_price' => '',
            'sale_price' => '',
        ), $props);
    }
    public function get_id() { return $this->props['id']; }
    public function get_name() { return $this->props['name']; }
    public function get_sku($context = 'view') { return $this->props['sku']; }
    public function is_type($type) {
        return is_array($type) ? in_array($this->props['type'], $type, true) : $this->props['type'] === $type;
    }
    public function get_stock_quantity($context = 'view') { return $this->props['stock_quantity']; }
    public function set_stock_quantity($v) { $this->props['stock_quantity'] = $v; }
    public function get_manage_stock($context = 'view') { return $this->props['manage_stock']; }
    public function set_manage_stock($v) { $this->props['manage_stock'] = $v; }
    public function get_backorders($context = 'view') { return $this->props['backorders']; }
    public function set_backorders($v) { $this->props['backorders'] = $v; }
    public function get_stock_status($context = 'view') { return $this->props['stock_status']; }
    public function set_stock_status($v) { $this->props['stock_status'] = $v; }
    public function get_regular_price($context = 'view') { return $this->props['regular_price']; }
    public function set_regular_price($v) { $this->props['regular_price'] = $v; }
    public function get_sale_price($context = 'view') { return $this->props['sale_price']; }
    public function set_sale_price($v) { $this->props['sale_price'] = $v; }
}

require __DIR__ . '/../includes/class-ddi-product-sync.php';

// ---------------------------------------------------------------------------
// Tiny assertion runner
// ---------------------------------------------------------------------------

$failures = 0;
$tests = 0;
function check($label, $actual, $expected) {
    global $failures, $tests;
    $tests++;
    if ($actual === $expected) {
        echo "  ok  $label\n";
    } else {
        $failures++;
        echo "FAIL  $label — expected " . var_export($expected, true) . ", got " . var_export($actual, true) . "\n";
    }
}

function mark_synced($id) {
    $GLOBALS['ddi_test_meta'][$id]['_ddi_synced'] = 'yes';
}

function reset_state() {
    $GLOBALS['ddi_test_meta'] = array();
    $GLOBALS['ddi_test_saved_products'] = array();
    $GLOBALS['ddi_test_logged_events'] = array();
    $GLOBALS['ddi_test_is_admin'] = true;
}

$sync = DDI_Product_Sync::instance();

// ---------------------------------------------------------------------------
// force_manage_stock
// ---------------------------------------------------------------------------

echo "force_manage_stock:\n";

reset_state();
mark_synced(10);
$simple = new WC_Product(array('id' => 10, 'type' => 'simple'));
check('forces manage_stock on for a synced simple product in admin',
    $sync->force_manage_stock(false, $simple), true);

$bundle = new WC_Product(array('id' => 10, 'type' => 'bundle'));
check('does NOT force manage_stock for a synced bundle',
    $sync->force_manage_stock(false, $bundle), false);

$woosb = new WC_Product(array('id' => 10, 'type' => 'woosb'));
check('does NOT force manage_stock for a woosb bundle',
    $sync->force_manage_stock(false, $woosb), false);

$GLOBALS['ddi_test_is_admin'] = false;
check('does NOT rewrite manage_stock on the storefront',
    $sync->force_manage_stock(false, $simple), false);
$GLOBALS['ddi_test_is_admin'] = true;

$unsynced = new WC_Product(array('id' => 11, 'type' => 'simple'));
check('leaves unsynced products alone',
    $sync->force_manage_stock(false, $unsynced), false);

// ---------------------------------------------------------------------------
// enforce_synced_fields — the wp-admin override vectors seen in production
// ---------------------------------------------------------------------------

echo "enforce_synced_fields:\n";

// Vector 1: user unchecks "manage stock" and hand-sets "on backorder".
reset_state();
mark_synced(20);
$GLOBALS['ddi_test_saved_products'][20] = new WC_Product(array(
    'id' => 20, 'manage_stock' => true, 'stock_quantity' => 0,
    'backorders' => 'no', 'stock_status' => 'outofstock',
));
$incoming = new WC_Product(array(
    'id' => 20, 'manage_stock' => false, 'stock_quantity' => 0,
    'backorders' => 'yes', 'stock_status' => 'onbackorder',
));
$sync->enforce_synced_fields($incoming);
check('manage_stock reverted to on', $incoming->get_manage_stock('edit'), true);
check('backorders reverted to no', $incoming->get_backorders('edit'), 'no');
check('revert events logged',
    in_array('manage_stock_reverted', $GLOBALS['ddi_test_logged_events'], true)
    && in_array('backorders_reverted', $GLOBALS['ddi_test_logged_events'], true), true);

// Vector 2: user edits the stock number directly.
reset_state();
mark_synced(21);
$GLOBALS['ddi_test_saved_products'][21] = new WC_Product(array(
    'id' => 21, 'manage_stock' => true, 'stock_quantity' => 3,
));
$incoming = new WC_Product(array('id' => 21, 'manage_stock' => true, 'stock_quantity' => 500));
$sync->enforce_synced_fields($incoming);
check('stock quantity reverted', $incoming->get_stock_quantity('edit'), 3);

// Vector 3: unmanaged product, user flips stock status to instock.
reset_state();
mark_synced(22);
$GLOBALS['ddi_test_saved_products'][22] = new WC_Product(array(
    'id' => 22, 'manage_stock' => false, 'stock_status' => 'outofstock',
));
$incoming = new WC_Product(array(
    'id' => 22, 'manage_stock' => false, 'stock_status' => 'instock',
));
$sync->enforce_synced_fields($incoming);
check('stock status reverted for unmanaged product',
    $incoming->get_stock_status('edit'), 'outofstock');

// Managed product: stock status is derived by Woo, must not be reverted.
reset_state();
mark_synced(23);
$GLOBALS['ddi_test_saved_products'][23] = new WC_Product(array(
    'id' => 23, 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'instock',
));
$incoming = new WC_Product(array(
    'id' => 23, 'manage_stock' => true, 'stock_quantity' => 5, 'stock_status' => 'outofstock',
));
$sync->enforce_synced_fields($incoming);
check('stock status untouched for managed product',
    $incoming->get_stock_status('edit'), 'outofstock');

// Lock setting off: stock fields free, price still enforced.
reset_state();
mark_synced(24);
$GLOBALS['ddi_test_options']['ddi_settings'] = array('lock_stock_management' => 'no');
$GLOBALS['ddi_test_saved_products'][24] = new WC_Product(array(
    'id' => 24, 'manage_stock' => true, 'stock_quantity' => 1, 'regular_price' => '10',
));
$incoming = new WC_Product(array(
    'id' => 24, 'manage_stock' => false, 'stock_quantity' => 99, 'regular_price' => '5',
));
$sync->enforce_synced_fields($incoming);
check('stock free when lock setting is off', $incoming->get_stock_quantity('edit'), 99);
check('manage_stock free when lock setting is off', $incoming->get_manage_stock('edit'), false);
check('price still enforced', $incoming->get_regular_price('edit'), '10');
$GLOBALS['ddi_test_options']['ddi_settings'] = array('lock_stock_management' => 'yes');

// Unsynced products are never touched.
reset_state();
$GLOBALS['ddi_test_saved_products'][25] = new WC_Product(array(
    'id' => 25, 'manage_stock' => true, 'stock_quantity' => 1,
));
$incoming = new WC_Product(array('id' => 25, 'manage_stock' => false, 'stock_quantity' => 99));
$sync->enforce_synced_fields($incoming);
check('unsynced product untouched', $incoming->get_stock_quantity('edit'), 99);

// ---------------------------------------------------------------------------

echo "\n$tests tests, $failures failure(s)\n";
exit($failures === 0 ? 0 : 1);
