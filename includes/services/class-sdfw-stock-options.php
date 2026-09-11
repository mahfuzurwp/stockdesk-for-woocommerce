<?php
/**
 * Stock and product option definitions.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stock and product option definitions.
 */
final class SDFW_Stock_Options {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Stock_Options|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Stock_Options
	 */
	public static function instance(): SDFW_Stock_Options {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get stock status options.
	 *
	 * @return array<string, string>
	 */
	public function get_stock_status_options(): array {
		return array(
			'all'         => __( 'All statuses', 'stockdesk-for-woocommerce' ),
			'instock'     => __( 'In stock', 'stockdesk-for-woocommerce' ),
			'outofstock'  => __( 'Out of stock', 'stockdesk-for-woocommerce' ),
			'onbackorder' => __( 'On backorder', 'stockdesk-for-woocommerce' ),
			'low_stock'   => __( 'Low stock', 'stockdesk-for-woocommerce' ),
		);
	}

	/**
	 * Get product type options.
	 *
	 * @return array<string, string>
	 */
	public function get_product_type_options(): array {
		return array(
			'all'       => __( 'All types', 'stockdesk-for-woocommerce' ),
			'simple'    => __( 'Simple', 'stockdesk-for-woocommerce' ),
			'variable'  => __( 'Variable', 'stockdesk-for-woocommerce' ),
			'grouped'   => __( 'Grouped', 'stockdesk-for-woocommerce' ),
			'external'  => __( 'External', 'stockdesk-for-woocommerce' ),
			'variation' => __( 'Variation', 'stockdesk-for-woocommerce' ),
		);
	}
}
