<?php
/**
 * Import session manager.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Import session manager.
 */
final class SDFW_Import_Session {

	/**
	 * Transient prefix.
	 *
	 * @var string
	 */
	private const TRANSIENT_PREFIX = 'sdfw_import_';

	/**
	 * Transient expiration in seconds (30 minutes).
	 *
	 * @var int
	 */
	private const TRANSIENT_EXPIRATION = 1800;

	/**
	 * Create a session from parsed CSV.
	 *
	 * @param array $parsed Parsed CSV data.
	 * @param array $file   File upload data.
	 * @return array Session data.
	 */
	public function create( array $parsed, array $file ): array {
		$session_id    = wp_generate_uuid4();
		$transient_key = self::TRANSIENT_PREFIX . $session_id;

		$session_data = array(
			'file_name'  => sanitize_file_name( $file['name'] ),
			'headers'    => $parsed['headers'],
			'rows'       => $parsed['rows'],
			'delimiter'  => $parsed['delimiter'],
			'mapping'    => array(),
			'created_at' => time(),
			'progress'   => array(
				'total'     => count( $parsed['rows'] ),
				'processed' => 0,
				'success'   => 0,
				'failed'    => 0,
			),
		);

		set_transient( $transient_key, $session_data, self::TRANSIENT_EXPIRATION );

		return array(
			'session_id'  => $session_id,
			'headers'     => $parsed['headers'],
			'sample_rows' => array_slice( $parsed['rows'], 0, 3 ),
			'delimiter'   => $parsed['delimiter'],
		);
	}

	/**
	 * Get session data.
	 *
	 * @param string $session_id Session ID.
	 * @return array|false Session data or false.
	 */
	public function get( string $session_id ) {
		$transient_key = self::TRANSIENT_PREFIX . $session_id;
		return get_transient( $transient_key );
	}

	/**
	 * Update session mapping.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $mapping    Mapping.
	 * @return bool
	 */
	public function update_mapping( string $session_id, array $mapping ): bool {
		$session_data = $this->get( $session_id );
		if ( false === $session_data ) {
			return false;
		}

		$session_data['mapping'] = $mapping;
		set_transient( self::TRANSIENT_PREFIX . $session_id, $session_data, self::TRANSIENT_EXPIRATION );

		return true;
	}

	/**
	 * Get progress data.
	 *
	 * @param string $session_id Session ID.
	 * @return array
	 */
	public function get_progress( string $session_id ): array {
		$progress_key = 'sdfw_import_progress_' . $session_id;
		return get_option( $progress_key, array() );
	}

	/**
	 * Initialize progress.
	 *
	 * @param string $session_id Session ID.
	 * @param int    $total_rows Total rows.
	 * @return void
	 */
	public function init_progress( string $session_id, int $total_rows ): void {
		$progress_key = 'sdfw_import_progress_' . $session_id;
		update_option(
			$progress_key,
			array(
				'total'       => $total_rows,
				'processed'   => 0,
				'success'     => 0,
				'failed'      => 0,
				'in_progress' => true,
				'started_at'  => time(),
				'logs'        => array(),
			),
			false
		);
	}

	/**
	 * Update progress after batch.
	 *
	 * @param string $session_id Session ID.
	 * @param array  $results    Batch results.
	 * @return void
	 */
	public function record_batch_results( string $session_id, array $results ): void {
		$progress_key = 'sdfw_import_progress_' . $session_id;
		$progress     = $this->get_progress( $session_id );

		if ( empty( $progress ) ) {
			return;
		}

		$logs = $progress['logs'] ?? array();

		foreach ( $results as $row_result ) {
			++$progress['processed'];

			if ( $row_result['success'] ) {
				++$progress['success'];
			} else {
				++$progress['failed'];
			}

			$logs[] = array(
				'row'     => $row_result['row_num'],
				'sku'     => $row_result['sku'],
				'success' => $row_result['success'],
				'message' => $row_result['message'],
			);
		}

		$progress['logs'] = array_slice( $logs, -100 );

		if ( $progress['processed'] >= $progress['total'] ) {
			$progress['in_progress'] = false;
		}

		update_option( $progress_key, $progress, false );
	}
}
