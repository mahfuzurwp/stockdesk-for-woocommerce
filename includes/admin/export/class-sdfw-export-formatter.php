<?php
/**
 * Export formatter class.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Export formatter for CSV.
 */
class SDFW_Export_Formatter {

	/**
	 * Required columns (always included).
	 *
	 * @return array<string, string>
	 */
	private function get_required_columns(): array {
		return array(
			'product_id'          => __( 'Product ID', 'stockdesk-for-woocommerce' ),
			'sku'                 => __( 'SKU', 'stockdesk-for-woocommerce' ),
			'product_name'        => __( 'Product Name', 'stockdesk-for-woocommerce' ),
			'manage_stock'        => __( 'Manage Stock', 'stockdesk-for-woocommerce' ),
			'stock_quantity'      => __( 'Stock Quantity', 'stockdesk-for-woocommerce' ),
			'stock_status'        => __( 'Stock Status', 'stockdesk-for-woocommerce' ),
			'backorders'          => __( 'Backorders', 'stockdesk-for-woocommerce' ),
			'low_stock_threshold' => __( 'Low Stock Threshold', 'stockdesk-for-woocommerce' ),
		);
	}

	/**
	 * Optional columns (included when requested).
	 *
	 * @return array<string, string>
	 */
	private function get_optional_columns(): array {
		return array(
			'product_type' => __( 'Product Type', 'stockdesk-for-woocommerce' ),
			'parent_id'    => __( 'Parent ID', 'stockdesk-for-woocommerce' ),
			'parent_name'  => __( 'Parent Name', 'stockdesk-for-woocommerce' ),
			'category'     => __( 'Category', 'stockdesk-for-woocommerce' ),
			'brand'        => __( 'Brand', 'stockdesk-for-woocommerce' ),
			'tags'         => __( 'Tags', 'stockdesk-for-woocommerce' ),
		);
	}

	/**
	 * Get columns based on options.
	 *
	 * @param bool $include_optional Whether to include optional columns.
	 * @return array
	 */
	private function get_columns( bool $include_optional = false ): array {
		$columns = $this->get_required_columns();

		if ( $include_optional ) {
			$columns = array_merge( $columns, $this->get_optional_columns() );
		}

		return $columns;
	}

	/**
	 * Preview table columns for the export UI.
	 *
	 * @param bool $include_optional Whether optional export columns are enabled.
	 * @return array<string, string>
	 */
	public function get_preview_columns( bool $include_optional = false ): array {
		$columns = array(
			'sku'            => __( 'SKU', 'stockdesk-for-woocommerce' ),
			'product_name'   => __( 'Product Name', 'stockdesk-for-woocommerce' ),
			'stock_quantity' => __( 'Stock Quantity', 'stockdesk-for-woocommerce' ),
			'stock_status'   => __( 'Stock Status', 'stockdesk-for-woocommerce' ),
			'manage_stock'   => __( 'Manage Stock', 'stockdesk-for-woocommerce' ),
		);

		if ( $include_optional ) {
			$columns['product_type'] = __( 'Product Type', 'stockdesk-for-woocommerce' );
			$columns['category']     = __( 'Category', 'stockdesk-for-woocommerce' );
		}

		return $columns;
	}

	/**
	 * Format product rows for export preview.
	 *
	 * @param array $products Product data.
	 * @param bool  $include_optional Whether optional export columns are enabled.
	 * @return array<int, array<string, mixed>>
	 */
	public function format_preview_rows( array $products, bool $include_optional = false ): array {
		$columns = $this->get_preview_columns( $include_optional );
		$rows    = array();

		foreach ( $products as $index => $product ) {
			$row = array(
				'row' => $index + 1,
			);

			foreach ( array_keys( $columns ) as $key ) {
				$row[ $key ] = $this->format_csv_value( $product[ $key ] ?? '' );
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Convert products to CSV.
	 *
	 * @param array  $products Product data.
	 * @param bool   $include_optional Whether to include optional columns.
	 * @param string $delimiter CSV delimiter character.
	 * @return string
	 */
	public function to_csv( array $products, bool $include_optional = false, string $delimiter = ',' ): string {
		$output = '';
		$output .= "\xEF\xBB\xBF";

		$columns = $this->get_columns( $include_optional );

		$headers = apply_filters( 'sdfw_export_csv_headers', array_values( $columns ) );
		$output .= $this->csv_line( $headers, $delimiter );

		foreach ( $products as $product ) {
			$row = array();
			foreach ( array_keys( $columns ) as $key ) {
				$value = $product[ $key ] ?? '';
				$row[] = $this->format_csv_value( $value );
			}
			$output .= $this->csv_line( $row, $delimiter );
		}

		return $output;
	}

	/**
	 * Format a single CSV line.
	 *
	 * @param array  $fields   Field values.
	 * @param string $delimiter CSV delimiter character.
	 * @return string
	 */
	private function csv_line( array $fields, string $delimiter = ',' ): string {
		$enclosure = '"';
		$line      = '';

		foreach ( $fields as $field ) {
			if ( strpos( $field, $delimiter ) !== false ||
				 strpos( $field, $enclosure ) !== false ||
				 strpos( $field, "\n" ) !== false ||
				 strpos( $field, "\r" ) !== false ) {
				$field = $enclosure . str_replace( $enclosure, $enclosure . $enclosure, $field ) . $enclosure;
			}
			$line .= $field . $delimiter;
		}

		return rtrim( $line, $delimiter ) . "\n";
	}

	/**
	 * Format CSV value.
	 *
	 * @param mixed $value Value to format.
	 * @return string
	 */
	private function format_csv_value( $value ): string {
		if ( null === $value ) {
			return '';
		}
		return (string) $value;
	}
}
