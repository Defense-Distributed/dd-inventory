=== DD Inventory ===
Contributors: defcad
Tags: woocommerce, inventory, sync
Requires at least: 5.8
Tested up to: 6.4
Requires PHP: 7.4
Stable tag: 1.0.3
License: GPLv2 or later

Connects WooCommerce to your DD Inventory system for automatic stock sync.

== Description ==

DD Inventory syncs your WooCommerce store with your inventory management system. When orders come in, inventory is automatically updated. Products pushed from the inventory system have their SKU and stock locked to prevent accidental changes.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/`
2. Activate the plugin
3. Go to WooCommerce → DD Inventory
4. Enter your inventory system's webhook URL
5. Copy the Webhook Secret to your inventory system's sales channel settings
6. Click "Register Webhooks"

== Changelog ==

= 1.0.3 =
* Added webhook secret display with copy button for secure verification

= 1.0.2 =
* Initial release
