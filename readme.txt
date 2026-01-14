=== DD Inventory ===
Contributors: yourorganization
Tags: woocommerce, inventory, sync, webhook, stock management
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
WC requires at least: 5.0
WC tested up to: 8.5
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Syncs WooCommerce inventory with an external inventory management system via webhooks.

== Description ==

DD Inventory connects your WooCommerce store to an external inventory management system. The inventory tracker is the **source of truth** - it pushes products and inventory to WooCommerce, and WooCommerce sends order webhooks back.

**Key Features:**

* Automatic webhook registration for order events (created, updated, deleted)
* Secure webhook signature verification
* SKU-based product matching
* Lock stock management for synced products (prevents accidental changes)
* Lock SKU editing for synced products
* Dashboard widget showing sync status
* Detailed sync activity logs
* Bulk actions to mark products as synced/unsynced
* REST API endpoints for managing sync status

**How It Works:**

1. Your inventory tracker pushes products to WooCommerce via the REST API
2. WooCommerce sends order webhooks back to your inventory tracker
3. The inventory tracker adjusts stock based on order status

**Order Status → Inventory Action:**

* `processing`, `on-hold`, `completed` → Decrement inventory
* `cancelled`, `refunded`, `failed` → Restore inventory
* `pending` → No inventory change

== Installation ==

1. Upload the plugin files to `/wp-content/plugins/dd-inventory/`
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Go to WooCommerce → DD Inventory to configure the plugin
4. Enter your webhook endpoint URL
5. Copy the webhook secret to your inventory tracker configuration
6. Click "Register Webhooks" to set up order webhooks

== Configuration ==

**In WooCommerce:**

1. Navigate to WooCommerce → Settings → Advanced → REST API
2. Create API keys (Read/Write) for your inventory tracker
3. Navigate to WooCommerce → DD Inventory
4. Enter the webhook URL from your inventory tracker
5. Copy the webhook secret

**In Your Inventory Tracker:**

1. Add the WooCommerce site URL
2. Enter the REST API consumer key and secret
3. Enter the webhook secret for signature verification

== Frequently Asked Questions ==

= Why are stock fields locked for some products? =

Products marked as "synced" have their stock managed by your external inventory tracker. The lock prevents accidental changes that would be overwritten by the next sync.

= How do I unmark a product as synced? =

Edit the product, find the "DD Inventory Sync" meta box in the sidebar, and uncheck "Mark as synced product". You can also use bulk actions from the product list.

= What webhook topics are registered? =

The plugin registers webhooks for: order.created, order.updated, and order.deleted.

= How is webhook security handled? =

Webhooks are signed using HMAC-SHA256 with the webhook secret. Your inventory tracker should verify the signature using the `X-WC-Webhook-Signature` header.

== Changelog ==

= 1.0.0 =
* Initial release
* Webhook auto-registration
* Settings page with connection status
* SKU and stock field locking
* Dashboard widget
* Sync activity logs
* REST API endpoints for sync status management

== Upgrade Notice ==

= 1.0.0 =
Initial release.
