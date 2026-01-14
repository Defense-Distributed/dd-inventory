<?php
/**
 * Uninstall script
 *
 * Fired when the plugin is uninstalled.
 *
 * @package DD_Inventory
 */

// If uninstall not called from WordPress, exit
if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

/**
 * Clean up plugin data on uninstall
 */
function ddi_uninstall_cleanup() {
    global $wpdb;

    // Delete plugin options
    delete_option('ddi_settings');
    delete_option('ddi_webhook_secret');
    delete_option('ddi_sync_logs');

    // Delete product meta for synced status
    $wpdb->query(
        "DELETE FROM {$wpdb->postmeta}
         WHERE meta_key IN ('_ddi_synced', '_ddi_synced_at')"
    );

    // Delete webhooks created by the plugin
    if (class_exists('WC_Data_Store')) {
        $data_store = WC_Data_Store::load('webhook');
        $webhook_ids = $data_store->search_webhooks(array(
            'search' => 'DD Inventory',
        ));

        foreach ($webhook_ids as $webhook_id) {
            $webhook = wc_get_webhook($webhook_id);
            if ($webhook) {
                $webhook->delete(true);
            }
        }
    }

    // Clear any scheduled events
    wp_clear_scheduled_hook('ddi_register_webhooks');

    // Clear transients
    $wpdb->query(
        "DELETE FROM {$wpdb->options}
         WHERE option_name LIKE '_transient_ddi_%'
         OR option_name LIKE '_transient_timeout_ddi_%'"
    );
}

ddi_uninstall_cleanup();
