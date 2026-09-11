<?php
/**
 * Import handler class.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import handler class.
 */
final class SDFW_Import {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Import|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Import
	 */
	public static function instance(): SDFW_Import {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_stockdesk_detect_mapping', array( $this, 'ajax_detect_mapping' ) );
		add_action( 'wp_ajax_stockdesk_validate_mapping', array( $this, 'ajax_validate_mapping' ) );
		add_action( 'wp_ajax_stockdesk_run_dry_run', array( $this, 'ajax_run_dry_run' ) );
		add_action( 'wp_ajax_stockdesk_start_import', array( $this, 'ajax_start_import' ) );
		add_action( 'wp_ajax_stockdesk_import_progress', array( $this, 'ajax_import_progress' ) );
		add_action( 'stockdesk_import_row', array( $this, 'process_import_row' ), 10, 6 );
	}

	/**
	 * Client bootstrap data for the SPA.
	 *
	 * @return array<string, mixed>
	 */
	public function get_client_data(): array {
		return array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sdfw_import_nonce' ),
		);
	}

	/**
	 * Verify AJAX nonce.
	 *
	 * @return void
	 */
	private function verify_nonce(): void {
		if ( ! check_ajax_referer( 'sdfw_import_nonce', 'nonce', false ) ) {
			SDFW_Ajax_Response::send_invalid_nonce();
		}
	}

	/**
	 * Verify capability for import actions.
	 *
	 * @return void
	 */
	private function verify_capability(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			SDFW_Ajax_Response::send_forbidden();
		}
	}

	/**
	 * AJAX handler: Detect mapping from uploaded CSV.
	 *
	 * @return void
	 */
	public function ajax_detect_mapping(): void {
		$this->verify_nonce();
		$this->verify_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified above via verify_nonce()/verify_capability().
		if ( ! isset( $_FILES['csv_file'] ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'No file uploaded.', 'stockdesk-for-woocommerce' ),
				'no_file'
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; file upload data is validated (type/size/upload error) before use, not written directly.
		$file      = $_FILES['csv_file'];
		$validator = SDFW_Import_Validator::instance();

		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			SDFW_Ajax_Response::send_error(
				$validator->get_upload_error_message( (int) $file['error'] ),
				'upload_failed'
			);
		}

		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid upload. Please try again.', 'stockdesk-for-woocommerce' ),
				'invalid_upload'
			);
		}

		$max_size = wp_max_upload_size();
		if ( $max_size > 0 && (int) ( $file['size'] ?? 0 ) > $max_size ) {
			SDFW_Ajax_Response::send_error(
				__( 'The uploaded file exceeds the maximum allowed size.', 'stockdesk-for-woocommerce' ),
				'file_too_large'
			);
		}

		$file_type = wp_check_filetype( $file['name'], array( 'csv' => 'text/csv' ) );
		if ( ! $file_type['ext'] ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid file type. Please upload a CSV file.', 'stockdesk-for-woocommerce' ),
				'invalid_file_type'
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reads the local upload's temp file, not a remote URL; wp_remote_get() does not apply.
		$content = file_get_contents( $file['tmp_name'] );
		if ( false === $content ) {
			SDFW_Ajax_Response::send_error(
				__( 'Failed to read file.', 'stockdesk-for-woocommerce' ),
				'read_failed'
			);
		}

		$parser  = new SDFW_Import_Csv_Parser();
		$mapper  = new SDFW_Import_Mapper();
		$session = new SDFW_Import_Session();

		$parsed = $parser->parse( $content );
		if ( is_wp_error( $parsed ) ) {
			SDFW_Ajax_Response::send_error(
				$parsed->get_error_message(),
				$parsed->get_error_code()
			);
		}

		$mapping      = $mapper->detect_mapping( $parsed['headers'] );
		$session_data = $session->create( $parsed, $file );

		wp_send_json_success(
			array(
				'session_id'   => $session_data['session_id'],
				'headers'      => $session_data['headers'],
				'sample_rows'  => $session_data['sample_rows'],
				'detected_map' => $mapping,
				'delimiter'    => $session_data['delimiter'],
			)
		);
	}

	/**
	 * AJAX handler: Validate mapping and return validation results.
	 *
	 * @return void
	 */
	public function ajax_validate_mapping(): void {
		$this->verify_nonce();
		$this->verify_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified above via verify_nonce()/verify_capability().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Verified above; decoded and sanitized field-by-field via SDFW_Import_Validator::sanitize_mapping() below.
		$raw_mapping = isset( $_POST['mapping'] ) ? wp_unslash( $_POST['mapping'] ) : '';
		$mapping     = is_string( $raw_mapping ) ? json_decode( $raw_mapping, true ) : (array) $raw_mapping;

		if ( ! is_array( $mapping ) ) {
			$mapping = array();
		}

		if ( empty( $session_id ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid session.', 'stockdesk-for-woocommerce' ),
				'invalid_session'
			);
		}

		$session      = new SDFW_Import_Session();
		$session_data = $session->get( $session_id );

		if ( false === $session_data ) {
			SDFW_Ajax_Response::send_error(
				__( 'Session expired. Please upload the file again.', 'stockdesk-for-woocommerce' ),
				'session_expired'
			);
		}

		$validator = SDFW_Import_Validator::instance();
		$sanitized = $validator->sanitize_mapping( $mapping );
		$errors    = $validator->validate_mapping( $sanitized );

		if ( ! empty( $errors ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Mapping validation failed.', 'stockdesk-for-woocommerce' ),
				'mapping_invalid',
				array( 'errors' => $errors )
			);
		}

		$session->update_mapping( $session_id, $sanitized );

		wp_send_json_success(
			array(
				'mapping' => $sanitized,
				'message' => __( 'Mapping validated successfully.', 'stockdesk-for-woocommerce' ),
			)
		);
	}

	/**
	 * AJAX handler: Run dry run and return preview.
	 *
	 * @return void
	 */
	public function ajax_run_dry_run(): void {
		$this->verify_nonce();
		$this->verify_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified above via verify_nonce()/verify_capability().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid session.', 'stockdesk-for-woocommerce' ),
				'invalid_session'
			);
		}

		$session      = new SDFW_Import_Session();
		$session_data = $session->get( $session_id );

		if ( false === $session_data ) {
			SDFW_Ajax_Response::send_error(
				__( 'Session expired. Please upload the file again.', 'stockdesk-for-woocommerce' ),
				'session_expired'
			);
		}

		$mapping = $session_data['mapping'] ?? array();
		$rows    = $session_data['rows'] ?? array();
		$headers = $session_data['headers'] ?? array();

		$preview     = array();
		$valid_count = 0;
		$error_count = 0;
		$warnings    = array();
		$validator   = SDFW_Import_Validator::instance();

		foreach ( $rows as $index => $row ) {
			$parsed       = $validator->parse_row( $mapping, $headers, $row );
			$sku          = $parsed['sku'];
			$row_data     = $parsed['row_data'];
			$row_errors   = $parsed['errors'];
			$row_warnings = $parsed['warnings'];

			if ( ! empty( $row_errors ) ) {
				++$error_count;
			} else {
				++$valid_count;
			}

			if ( ! empty( $row_warnings ) ) {
				$warnings[] = sprintf(
					/* translators: 1: Row number, 2: Warning message */
					__( 'Row %1$d: %2$s', 'stockdesk-for-woocommerce' ),
					$index + 1,
					implode( ', ', $row_warnings )
				);
			}

			if ( $index < 5 ) {
				$preview[] = array(
					'row'      => $index + 1,
					'sku'      => $sku,
					'data'     => $row_data,
					'errors'   => $row_errors,
					'warnings' => $row_warnings,
				);
			}
		}

		wp_send_json_success(
			array(
				'valid_count' => $valid_count,
				'error_count' => $error_count,
				'preview'     => $preview,
				'warnings'    => $warnings,
				'total_rows'  => count( $rows ),
			)
		);
	}

	/**
	 * AJAX handler: Start import and queue via Action Scheduler.
	 *
	 * @return void
	 */
	public function ajax_start_import(): void {
		$this->verify_nonce();
		$this->verify_capability();

		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Background processing is unavailable. Please ensure WooCommerce is active.', 'stockdesk-for-woocommerce' ),
				'scheduler_unavailable'
			);
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified above via verify_nonce()/verify_capability().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid session.', 'stockdesk-for-woocommerce' ),
				'invalid_session'
			);
		}

		$session      = new SDFW_Import_Session();
		$session_data = $session->get( $session_id );

		if ( false === $session_data ) {
			SDFW_Ajax_Response::send_error(
				__( 'Session expired. Please upload the file again.', 'stockdesk-for-woocommerce' ),
				'session_expired'
			);
		}

		$mapping = $session_data['mapping'] ?? array();
		$rows    = $session_data['rows'] ?? array();

		if ( empty( $mapping ) || empty( $rows ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'No data to import.', 'stockdesk-for-woocommerce' ),
				'no_data'
			);
		}

		$session->init_progress( $session_id, count( $rows ) );

		$batch_size = 20;
		$batches    = array_chunk( $rows, $batch_size );

		foreach ( $batches as $batch_index => $batch ) {
			as_schedule_single_action(
				time() + $batch_index,
				'stockdesk_import_row',
				array(
					'session_id'  => $session_id,
					'batch'       => $batch,
					'mapping'     => $mapping,
					'headers'     => $session_data['headers'],
					'batch_index' => $batch_index,
					'row_offset'  => $batch_index * $batch_size,
				),
				'sdfw-import'
			);
		}

		wp_send_json_success(
			array(
				'session_id' => $session_id,
				'total_rows' => count( $rows ),
				'batches'    => count( $batches ),
				'message'    => __( 'Import queued successfully.', 'stockdesk-for-woocommerce' ),
			)
		);
	}

	/**
	 * AJAX handler: Get import progress.
	 *
	 * @return void
	 */
	public function ajax_import_progress(): void {
		$this->verify_nonce();
		$this->verify_capability();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce and capability verified above via verify_nonce()/verify_capability().
		$session_id = isset( $_POST['session_id'] ) ? sanitize_text_field( wp_unslash( $_POST['session_id'] ) ) : '';

		if ( empty( $session_id ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid session.', 'stockdesk-for-woocommerce' ),
				'invalid_session'
			);
		}

		$session  = new SDFW_Import_Session();
		$progress = $session->get_progress( $session_id );

		if ( empty( $progress ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Import progress not found.', 'stockdesk-for-woocommerce' ),
				'progress_not_found'
			);
		}

		$all_complete = ! $progress['in_progress'] && $progress['processed'] >= $progress['total'];

		wp_send_json_success(
			array(
				'total'        => $progress['total'],
				'processed'    => $progress['processed'],
				'success'      => $progress['success'],
				'failed'       => $progress['failed'],
				'in_progress'  => $progress['in_progress'],
				'percent'      => $progress['total'] > 0 ? round( ( $progress['processed'] / $progress['total'] ) * 100 ) : 0,
				'all_complete' => $all_complete,
				'logs'         => $progress['logs'] ?? array(),
			)
		);
	}

	/**
	 * Process import row batch.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $batch      Batch of rows.
	 * @param array  $mapping    Field mapping.
	 * @param array  $headers    CSV headers.
	 * @param int    $batch_index Batch index.
	 * @param int    $row_offset  Global row offset for this batch.
	 * @return void
	 */
	public function process_import_row( string $session_id, array $batch, array $mapping, array $headers, int $batch_index = 0, int $row_offset = 0 ): void {
		$processor = SDFW_Import_Processor::instance();
		$processor->process_batch( $session_id, $batch, $mapping, $headers, $batch_index, $row_offset );
	}
}
