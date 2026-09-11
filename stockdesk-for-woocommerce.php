<?php
/**
 * Plugin Name: StockDesk for WooCommerce
 * Plugin URI:  https://wordpress.org/plugins/stockdesk-for-woocommerce/
 * Description: Inventory management workspace for WooCommerce products and variations.
 * Version:     1.0.0
 * Author:      Mahfuzur Rahman
 * Author URI:  https://storebuilders.agency/
 * License:     GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: stockdesk-for-woocommerce
 * Domain Path: /languages
 * Requires Plugins: woocommerce
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SDFW_VERSION', '1.0.0' );
define( 'SDFW_PLUGIN_FILE', __FILE__ );
define( 'SDFW_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SDFW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SDFW_PLUGIN_PATH . 'includes/class-sdfw-plugin.php';

SDFW_Plugin::instance()->boot();
