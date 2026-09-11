<?php
/**
 * Import validator.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import validator.
 */
final class SDFW_Import_Validator {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Import_Validator|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Import_Validator
	 */
	public static function instance(): SDFW_Import_Validator {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Validate mapping against allowed fields.
	 *
	 * @param array<string, string> $mapping Raw mapping (field => header).
	 * @return array<string, string> Sanitized mapping.
	 */
	public function sanitize_mapping( array $mapping ): array {
		$allowed_keys = array_keys( SDFW_Import_Mapper::instance()->get_allowed_fields() );
		$sanitized    = array();

		foreach ( $mapping as $field => $header ) {
			if ( ! in_array( $field, $allowed_keys, true ) ) {
				continue;
			}

			$sanitized[ $field ] = sanitize_text_field( (string) $header );
		}

		return $sanitized;
	}

	/**
	 * Validate mapping and return errors.
	 *
	 * @param array<string, string> $sanitized_mapping Sanitized mapping.
	 * @return array<string> Errors.
	 */
	public function validate_mapping( array $sanitized_mapping ): array {
		$errors         = array();
		$allowed_fields = SDFW_Import_Mapper::instance()->get_allowed_fields();

		if ( empty( $sanitized_mapping['sku'] ) ) {
			$errors[] = __( 'SKU column must be mapped.', 'stockdesk-for-woocommerce' );
		}

		$header_counts = array_count_values( array_filter( $sanitized_mapping ) );
		foreach ( $header_counts as $header => $count ) {
			if ( $count > 1 ) {
				$fields       = array_keys(
					array_filter(
						$sanitized_mapping,
						static function ( string $mapped_header ) use ( $header ): bool {
							return $mapped_header === $header;
						}
					)
				);
				$field_labels = array_map(
					static function ( string $field_key ) use ( $allowed_fields ): string {
						return $allowed_fields[ $field_key ] ?? $field_key;
					},
					$fields
				);
				$errors[]     = sprintf(
					/* translators: 1: CSV header, 2: field labels */
					__( 'CSV column "%1$s" is assigned to multiple fields: %2$s.', 'stockdesk-for-woocommerce' ),
					$header,
					implode( ', ', $field_labels )
				);
			}
		}

		return $errors;
	}

	/**
	 * Parse and validate a CSV row against mapping.
	 *
	 * @param array<string, string> $mapping Field mapping.
	 * @param array<int, string>    $headers CSV headers.
	 * @param array<int, string>    $row     CSV row values.
	 * @return array{sku: string, row_data: array<string, string>, errors: array<int, string>, warnings: array<int, string>}
	 */
	public function parse_row( array $mapping, array $headers, array $row ): array {
		$row_data     = array();
		$sku          = '';
		$row_errors   = array();
		$row_warnings = array();

		foreach ( $mapping as $field => $header ) {
			$header_index = array_search( $header, $headers, true );

			if ( empty( $header ) || false === $header_index || ! isset( $row[ $header_index ] ) ) {
				continue;
			}

			$value = trim( (string) $row[ $header_index ] );

			if ( 'sku' === $field ) {
				$sku = $value;
				if ( '' === $sku ) {
					$row_errors[] = __( 'SKU is required.', 'stockdesk-for-woocommerce' );
				}
				continue;
			}

			if ( 'stock_quantity' === $field ) {
				if ( '' !== $value && ! is_numeric( $value ) ) {
					$row_errors[] = __( 'Stock quantity must be numeric.', 'stockdesk-for-woocommerce' );
				}
				$row_data[ $field ] = $value;
				continue;
			}

			if ( 'low_stock_threshold' === $field ) {
				if ( '' !== $value && ! is_numeric( $value ) ) {
					$row_errors[] = __( 'Low stock threshold must be numeric.', 'stockdesk-for-woocommerce' );
				}
				$row_data[ $field ] = $value;
				continue;
			}

			if ( 'manage_stock' === $field ) {
				$normalized = strtolower( $value );
				if ( ! in_array( $normalized, array( 'yes', 'no', 'true', 'false', '1', '0' ), true ) && '' !== $value ) {
					$row_warnings[] = __( 'Manage stock should be yes/no.', 'stockdesk-for-woocommerce' );
				}
				$row_data[ $field ] = $value;
				continue;
			}

			if ( 'stock_status' === $field ) {
				$normalized = strtolower( $value );
				if ( ! in_array( $normalized, array( 'instock', 'outofstock', 'onbackorder' ), true ) && '' !== $value ) {
					$row_warnings[] = __( 'Stock status should be instock/outofstock/onbackorder.', 'stockdesk-for-woocommerce' );
				}
				$row_data[ $field ] = $value;
				continue;
			}

			if ( 'backorders' === $field ) {
				$normalized = strtolower( $value );
				if ( ! in_array( $normalized, array( 'yes', 'no', 'notify' ), true ) && '' !== $value ) {
					$row_warnings[] = __( 'Backorders should be yes/no/notify.', 'stockdesk-for-woocommerce' );
				}
				$row_data[ $field ] = $value;
				continue;
			}

			$row_data[ $field ] = $value;
		}

		return array(
			'sku'      => $sku,
			'row_data' => $row_data,
			'errors'   => $row_errors,
			'warnings' => $row_warnings,
		);
	}

	/**
	 * Map PHP upload error codes to user-facing messages.
	 *
	 * @param int $error_code Upload error code.
	 * @return string
	 */
	public function get_upload_error_message( int $error_code ): string {
		switch ( $error_code ) {
			case UPLOAD_ERR_INI_SIZE:
			case UPLOAD_ERR_FORM_SIZE:
				return __( 'The uploaded file exceeds the maximum allowed size.', 'stockdesk-for-woocommerce' );
			case UPLOAD_ERR_PARTIAL:
				return __( 'The file was only partially uploaded. Please try again.', 'stockdesk-for-woocommerce' );
			case UPLOAD_ERR_NO_FILE:
				return __( 'No file uploaded.', 'stockdesk-for-woocommerce' );
			case UPLOAD_ERR_NO_TMP_DIR:
			case UPLOAD_ERR_CANT_WRITE:
			case UPLOAD_ERR_EXTENSION:
				return __( 'The file could not be uploaded due to a server error.', 'stockdesk-for-woocommerce' );
			default:
				return __( 'File upload failed. Please try again.', 'stockdesk-for-woocommerce' );
		}
	}
}
