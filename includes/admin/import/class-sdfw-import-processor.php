<?php
/**
 * Import row processor.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import row processor.
 */
final class SDFW_Import_Processor {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Import_Processor|null
	 */
	private static $instance = null;

	/**
	 * Product service.
	 *
	 * @var SDFW_Product_Service
	 */
	private $product_service;

	/**
	 * Import session.
	 *
	 * @var SDFW_Import_Session
	 */
	private $session;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Import_Processor
	 */
	public static function instance(): SDFW_Import_Processor {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->product_service = SDFW_Product_Service::instance();
		$this->session         = new SDFW_Import_Session();
	}

	/**
	 * Process a batch of import rows.
	 *
	 * @param string $session_id  Session ID.
	 * @param array  $batch       Batch of rows.
	 * @param array  $mapping     Field mapping.
	 * @param array  $headers     CSV headers.
	 * @param int    $batch_index Batch index.
	 * @param int    $row_offset  Global row offset for this batch.
	 * @return void
	 */
	public function process_batch( string $session_id, array $batch, array $mapping, array $headers, int $batch_index, int $row_offset = 0 ): void {
		$results = array();

		foreach ( $batch as $row_index => $row ) {
			$row_num   = $row_offset + (int) $row_index + 1;
			$results[] = $this->process_row( $row, $mapping, $headers, $session_id, $row_num );
		}

		$this->session->record_batch_results( $session_id, $results );
	}

	/**
	 * Process a single import row.
	 *
	 * @param array  $row        CSV row.
	 * @param array  $mapping    Field mapping.
	 * @param array  $headers    CSV headers.
	 * @param string $session_id Session ID.
	 * @param int    $row_num    1-based row number within the CSV.
	 * @return array Import result.
	 */
	private function process_row( array $row, array $mapping, array $headers, string $session_id, int $row_num ): array {
		$validator  = SDFW_Import_Validator::instance();
		$parsed     = $validator->parse_row( $mapping, $headers, $row );
		$sku        = $parsed['sku'];
		$field_data = $parsed['row_data'];

		if ( ! empty( $parsed['errors'] ) ) {
			$message = implode( ' ', $parsed['errors'] );
			do_action( 'stockdesk_after_import_row', $sku, $field_data, $session_id, false, $message );
			return array(
				'success' => false,
				'row_num' => $row_num,
				'sku'     => $sku,
				'message' => $message,
			);
		}

		if ( empty( $sku ) ) {
			do_action( 'stockdesk_after_import_row', '', $field_data, $session_id, false, __( 'SKU is required.', 'stockdesk-for-woocommerce' ) );
			return array(
				'success' => false,
				'row_num' => $row_num,
				'sku'     => '',
				'message' => __( 'SKU is required.', 'stockdesk-for-woocommerce' ),
			);
		}

		do_action( 'stockdesk_before_import_row', $sku, $field_data, $session_id );

		$product_id = $this->product_service->find_product_id_by_sku( $sku );

		if ( ! $product_id ) {
			do_action( 'stockdesk_after_import_row', $sku, $field_data, $session_id, false, __( 'Product not found.', 'stockdesk-for-woocommerce' ) );
			return array(
				'success' => false,
				'row_num' => $row_num,
				'sku'     => $sku,
				'message' => __( 'Product not found with this SKU.', 'stockdesk-for-woocommerce' ),
			);
		}

		$product = $this->product_service->get_product( $product_id );

		if ( ! $product ) {
			do_action( 'stockdesk_after_import_row', $sku, $field_data, $session_id, false, __( 'Invalid product.', 'stockdesk-for-woocommerce' ) );
			return array(
				'success' => false,
				'row_num' => $row_num,
				'sku'     => $sku,
				'message' => __( 'Invalid product.', 'stockdesk-for-woocommerce' ),
			);
		}

		$result = $this->product_service->apply_stock_updates( $product, $field_data );

		if ( is_wp_error( $result ) ) {
			$message = __( 'Could not save product changes.', 'stockdesk-for-woocommerce' );
			do_action( 'stockdesk_after_import_row', $sku, $field_data, $session_id, false, $message );
			return array(
				'success' => false,
				'row_num' => $row_num,
				'sku'     => $sku,
				'message' => $message,
			);
		}

		$message = false === $result
			? __( 'No changes applied.', 'stockdesk-for-woocommerce' )
			: __( 'Product updated successfully.', 'stockdesk-for-woocommerce' );

		do_action( 'stockdesk_after_import_row', $sku, $field_data, $session_id, true, $message );

		return array(
			'success' => true,
			'row_num' => $row_num,
			'sku'     => $sku,
			'message' => $message,
		);
	}
}
