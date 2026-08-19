<?php
/**
 * Shared admin-ajax JSON error responses.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Ajax_Response {

	/**
	 * Send invalid nonce error response.
	 *
	 * @return void
	 */
	public static function send_invalid_nonce(): void {
		self::send_error(
			__( 'Security check failed.', 'stockdesk-for-woocommerce' ),
			'invalid_nonce'
		);
	}

	/**
	 * Send permission denied error response.
	 *
	 * @return void
	 */
	public static function send_forbidden(): void {
		self::send_error(
			__( 'Permission denied.', 'stockdesk-for-woocommerce' ),
			'forbidden'
		);
	}

	/**
	 * Send JSON error response.
	 *
	 * @param string               $message User-facing message.
	 * @param string               $code    Machine-readable error code.
	 * @param array<string, mixed> $extra   Additional payload keys.
	 * @return void
	 */
	public static function send_error( string $message, string $code = '', array $extra = array() ): void {
		$payload = array_merge(
			array(
				'message' => $message,
			),
			$extra
		);

		if ( '' !== $code ) {
			$payload['code'] = $code;
		}

		wp_send_json_error( $payload );
	}
}
