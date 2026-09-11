=== StockDesk for WooCommerce ===
Contributors: mahfuzurwp
Tags: woocommerce, inventory, stock, products, admin
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
Requires Plugins: woocommerce

Inventory management workspace for WooCommerce products and variations.

== Description ==

StockDesk for WooCommerce adds a focused inventory screen under WooCommerce Products where store managers can:

* Filter products and variations by category, type, stock status, and brand taxonomy (when available).
* Search by product title and SKU.
* Update stock-related fields per row.
* Apply bulk updates to selected products.
* Paginate results with per-user screen options for items per page.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory.
2. Activate the plugin through the "Plugins" menu in WordPress.
3. Ensure WooCommerce is installed and active.
4. Go to **Products > StockDesk**.

== Frequently Asked Questions ==

= Does this plugin require WooCommerce? =

Yes. WooCommerce must be installed and active.

= Which products are supported? =

The StockDesk screen supports WooCommerce products and product variations. You can filter and edit inventory-related fields for both.

= Who can access StockDesk? =

Only users with the `manage_woocommerce` capability can access and update inventory from StockDesk.

= Are my products modified on plugin uninstall? =

No product inventory data is deleted. On uninstall, the plugin only removes its own per-user screen option (`sdfw_inventory_per_page`).

== Screenshots ==
1. Admin screen

== Changelog ==

= 1.0.0 =
* Initial stable release.
