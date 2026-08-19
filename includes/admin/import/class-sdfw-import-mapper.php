<?php
/**
 * Import field mapper.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Import_Mapper {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Import_Mapper|null
	 */
	private static $instance = null;

	/**
	 * Field aliases.
	 *
	 * @var array<string, array<int, string>>
	 */
	private const FIELD_ALIASES = array(
		'sku'                 => array( 'sku', 'product sku', 'item sku' ),
		'manage_stock'        => array( 'manage stock', 'manage inventory', 'manage_stock' ),
		'stock_quantity'      => array( 'stock', 'stock quantity', 'stock qty', 'quantity', 'qty', 'inventory', 'stock_quantity' ),
		'stock_status'        => array( 'stock status', 'inventory status', 'stock_status' ),
		'backorders'          => array( 'backorders', 'backorder', 'allow backorders' ),
		'low_stock_threshold' => array( 'low stock threshold', 'low stock', 'notify below', 'low_stock_threshold' ),
	);

	/**
	 * Allowed fields.
	 *
	 * @var array<string, string>
	 */
	private $allowed_fields;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->allowed_fields = apply_filters( 'stockdesk_allowed_import_fields', array(
			'sku'                 => __( 'SKU (Match Key)', 'stockdesk-for-woocommerce' ),
			'manage_stock'        => __( 'Manage Stock', 'stockdesk-for-woocommerce' ),
			'stock_quantity'      => __( 'Stock Quantity', 'stockdesk-for-woocommerce' ),
			'stock_status'        => __( 'Stock Status', 'stockdesk-for-woocommerce' ),
			'backorders'          => __( 'Backorders', 'stockdesk-for-woocommerce' ),
			'low_stock_threshold' => __( 'Low Stock Threshold', 'stockdesk-for-woocommerce' ),
		) );
	}

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Import_Mapper
	 */
	public static function instance(): SDFW_Import_Mapper {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Get allowed fields.
	 *
	 * @return array<string, string>
	 */
	public function get_allowed_fields(): array {
		return $this->allowed_fields;
	}

	/**
	 * Normalize a CSV header or alias.
	 *
	 * @param string $value Header or alias.
	 * @return string
	 */
	private function normalize_value( string $value ): string {
		$normalized = strtolower( trim( $value ) );
		$normalized = preg_replace( '/[\s_-]+/', ' ', $normalized );
		$normalized = preg_replace( '/[^\w\s]/', '', $normalized );

		return trim( preg_replace( '/\s+/', ' ', $normalized ) );
	}

	/**
	 * Score a normalized header against a normalized alias.
	 *
	 * @param string $header_normalized Normalized header.
	 * @param string $alias_normalized  Normalized alias.
	 * @return int
	 */
	private function get_match_score( string $header_normalized, string $alias_normalized ): int {
		if ( '' === $header_normalized || '' === $alias_normalized ) {
			return 0;
		}

		if ( $header_normalized === $alias_normalized ) {
			return 300 + strlen( $alias_normalized );
		}

		if ( str_contains( $header_normalized, $alias_normalized ) ) {
			return 100 + strlen( $alias_normalized );
		}

		if ( str_contains( $alias_normalized, $header_normalized ) ) {
			return 50 + strlen( $header_normalized );
		}

		return 0;
	}

	/**
	 * Score a header against a field's aliases.
	 *
	 * @param string $header CSV header.
	 * @param string $field  Import field key.
	 * @return int
	 */
	private function score_header_match( string $header, string $field ): int {
		$header_normalized = $this->normalize_value( $header );
		$aliases           = self::FIELD_ALIASES[ $field ] ?? array();
		$best_score        = 0;

		foreach ( $aliases as $alias ) {
			$score = $this->get_match_score( $header_normalized, $this->normalize_value( $alias ) );
			if ( $score > $best_score ) {
				$best_score = $score;
			}
		}

		return $best_score;
	}

	/**
	 * Detect mapping from headers using field aliases.
	 *
	 * @param array<string> $headers CSV headers.
	 * @return array<string, string> Mapping (field => header).
	 */
	public function detect_mapping( array $headers ): array {
		$mapping = array();
		$used    = array();

		foreach ( array_keys( $this->allowed_fields ) as $field ) {
			$best_header = '';
			$best_score  = 0;

			foreach ( $headers as $header ) {
				if ( in_array( $header, $used, true ) ) {
					continue;
				}

				$score = $this->score_header_match( $header, $field );
				if ( $score > $best_score ) {
					$best_score  = $score;
					$best_header = $header;
				}
			}

			if ( $best_header ) {
				$mapping[ $field ] = $best_header;
				$used[]            = $best_header;
			}
		}

		return $mapping;
	}
}
