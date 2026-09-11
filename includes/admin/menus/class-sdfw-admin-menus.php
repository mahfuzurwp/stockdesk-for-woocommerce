<?php
/**
 * Admin menu registration.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles admin menu registration for StockDesk.
 */
final class SDFW_Admin_Menus {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Admin_Menus|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Admin_Menus
	 */
	public static function instance(): SDFW_Admin_Menus {
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
		add_action( 'admin_menu', array( $this, 'register_menus' ), 56 );
		add_action( 'admin_menu', array( $this, 'hide_auto_submenu' ), 999 );
	}

	/**
	 * Register admin menus.
	 *
	 * @return void
	 */
	public function register_menus(): void {
		add_menu_page(
			__( 'StockDesk', 'stockdesk-for-woocommerce' ),
			__( 'StockDesk', 'stockdesk-for-woocommerce' ),
			'manage_woocommerce',
			'stockdesk',
			array( SDFW_Admin_App::instance(), 'render' ),
			$this->get_menu_icon()
		);
	}

	/**
	 * Remove the auto-generated duplicate submenu under StockDesk.
	 *
	 * @return void
	 */
	public function hide_auto_submenu(): void {
		remove_submenu_page( 'stockdesk', 'stockdesk' );
	}

	/**
	 * Get menu icon.
	 *
	 * @return string
	 */
	private function get_menu_icon(): string {
		$path = SDFW_PLUGIN_PATH . 'assets/icon-admin-menu.svg';
		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local plugin asset, not a remote URL; wp_remote_get() does not apply.
			$icon = (string) file_get_contents( $path );
			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Encodes a bundled SVG into a data: URI for add_menu_page(); not obfuscating logic.
			return 'data:image/svg+xml;base64,' . base64_encode( $icon );
		}
		return 'dashicons-products';
	}
}
