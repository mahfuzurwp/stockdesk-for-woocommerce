<?php
/**
 * WooCommerce product service.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Product_Service {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Product_Service|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Product_Service
	 */
	public static function instance(): SDFW_Product_Service {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get WooCommerce product by ID.
	 *
	 * @param int $product_id Product ID.
	 * @return WC_Product|null
	 */
	public function get_product( int $product_id ): ?WC_Product {
		return wc_get_product( $product_id );
	}

	/**
	 * Find product ID by SKU.
	 *
	 * @param string $sku SKU.
	 * @return int|null
	 */
	public function find_product_id_by_sku( string $sku ): ?int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- No core API resolves a product by SKU directly; the lookup must reflect live data, so it is intentionally not cached.
		$product_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);

		return $product_id ? (int) $product_id : null;
	}

	/**
	 * Persist a WooCommerce product.
	 *
	 * @param WC_Product $product Product.
	 * @return true|WP_Error
	 */
	public function save_product( WC_Product $product ) {
		$result = $product->save();

		if ( is_wp_error( $result ) ) {
			$this->log_save_failure( $product->get_id(), $result );
			return $result;
		}

		if ( false === $result ) {
			$error = new WP_Error(
				'save_failed',
				__( 'Could not save product changes.', 'stockdesk-for-woocommerce' )
			);
			$this->log_save_failure( $product->get_id(), $error );
			return $error;
		}

		return true;
	}

	/**
	 * Apply stock updates to a product.
	 *
	 * @param WC_Product $product    Product.
	 * @param array      $field_data Field data.
	 * @return true|false|WP_Error True if saved, false if no changes, WP_Error on save failure.
	 */
	public function apply_stock_updates( WC_Product $product, array $field_data ) {
		$updated = false;

		if ( isset( $field_data['manage_stock'] ) && '' !== trim( (string) $field_data['manage_stock'] ) ) {
			$normalized = strtolower( $field_data['manage_stock'] );
			$value      = in_array( $normalized, array( 'yes', 'true', '1' ), true );
			$product->set_manage_stock( $value );
			$updated = true;
		}

		if ( isset( $field_data['stock_quantity'] ) && is_numeric( $field_data['stock_quantity'] ) ) {
			$product->set_stock_quantity( (float) $field_data['stock_quantity'] );
			$updated = true;
		}

		if ( isset( $field_data['stock_status'] ) ) {
			$normalized = strtolower( $field_data['stock_status'] );
			if ( in_array( $normalized, array( 'instock', 'outofstock', 'onbackorder' ), true ) ) {
				$product->set_stock_status( $normalized );
				$updated = true;
			}
		}

		if ( isset( $field_data['backorders'] ) ) {
			$normalized = strtolower( $field_data['backorders'] );
			if ( in_array( $normalized, array( 'yes', 'no', 'notify' ), true ) ) {
				$product->set_backorders( $normalized );
				$updated = true;
			}
		}

		if ( isset( $field_data['low_stock_threshold'] ) && is_numeric( $field_data['low_stock_threshold'] ) ) {
			$product->set_low_stock_amount( (float) $field_data['low_stock_threshold'] );
			$updated = true;
		}

		if ( ! $updated ) {
			return false;
		}

		return $this->save_product( $product );
	}

	/**
	 * Log product save failures.
	 *
	 * @param int           $product_id Product ID.
	 * @param WP_Error|bool $error      Error object or false.
	 * @return void
	 */
	private function log_save_failure( int $product_id, $error ): void {
		if ( ! function_exists( 'wc_get_logger' ) ) {
			return;
		}

		$message = is_wp_error( $error ) ? $error->get_error_message() : 'Unknown save failure.';
		$code    = is_wp_error( $error ) ? $error->get_error_code() : 'save_failed';

		wc_get_logger()->error(
			sprintf( 'Product save failed for ID %1$d: %2$s', $product_id, $message ),
			array(
				'source'     => 'stockdesk-for-woocommerce',
				'product_id' => $product_id,
				'code'       => $code,
			)
		);
	}
}
