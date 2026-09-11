<?php
/**
 * Low stock threshold resolver.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Low stock threshold resolver.
 */
final class SDFW_Low_Stock_Resolver {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Low_Stock_Resolver|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Low_Stock_Resolver
	 */
	public static function instance(): SDFW_Low_Stock_Resolver {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get the global low stock threshold from WooCommerce settings.
	 *
	 * @return int
	 */
	public function get_global_threshold(): int {
		return max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
	}

	/**
	 * Resolve the effective low stock threshold for a product.
	 *
	 * @param int|string|null $product_threshold Product-specific threshold (may be empty string).
	 * @return int Effective threshold.
	 */
	public function resolve_threshold( $product_threshold ): int {
		$trimmed = trim( (string) $product_threshold );

		if ( '' === $trimmed ) {
			return $this->get_global_threshold();
		}

		return max( 0, (int) $trimmed );
	}
}
