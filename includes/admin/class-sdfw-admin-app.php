<?php
/**
 * Unified StockDesk React admin app host.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders and enqueues the single-page StockDesk admin app.
 */
final class SDFW_Admin_App {

	/**
	 * Support forum URL.
	 */
	private const SUPPORT_URL = 'https://wordpress.org/support/plugin/stockdesk-for-woocommerce/';

	/**
	 * Review URL.
	 */
	private const REVIEW_URL = 'https://wordpress.org/support/plugin/stockdesk-for-woocommerce/reviews/#new-post';

	/**
	 * Allowed in-app paths.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWED_PATHS = array(
		'/inventory',
		'/import',
		'/export',
	);

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Admin_App|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Admin_App
	 */
	public static function instance(): SDFW_Admin_App {
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
		add_action( 'load-toplevel_page_stockdesk', array( $this, 'prepare_notice_suppression' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_body_class', array( $this, 'body_class' ) );
	}

	/**
	 * Strip third-party admin notices from the StockDesk screen.
	 *
	 * @return void
	 */
	public function prepare_notice_suppression(): void {
		add_action( 'admin_notices', array( $this, 'suppress_admin_notices' ), -9999 );
		add_action( 'all_admin_notices', array( $this, 'suppress_all_admin_notices' ), -9999 );
	}

	/**
	 * Remove callbacks registered on admin_notices.
	 *
	 * @return void
	 */
	public function suppress_admin_notices(): void {
		remove_all_actions( 'admin_notices' );
	}

	/**
	 * Remove callbacks registered on all_admin_notices.
	 *
	 * @return void
	 */
	public function suppress_all_admin_notices(): void {
		remove_all_actions( 'all_admin_notices' );
	}

	/**
	 * Add body class on the StockDesk screen.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public function body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_stockdesk' === $screen->id ) {
			$classes .= ' sdfw-admin-app';
		}

		return $classes;
	}

	/**
	 * Enqueue the unified admin app assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'toplevel_page_stockdesk' !== $hook ) {
			return;
		}

		$asset_file = SDFW_PLUGIN_PATH . 'build/admin.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'sdfw-admin-app',
			SDFW_PLUGIN_URL . 'build/admin.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		$css_path    = SDFW_PLUGIN_PATH . 'build/style-admin.css';
		$css_version = $asset['version'];
		if ( file_exists( $css_path ) ) {
			$css_version .= '.' . (string) filemtime( $css_path );
			wp_enqueue_style(
				'sdfw-admin-app',
				SDFW_PLUGIN_URL . 'build/style-admin.css',
				array( 'wp-components' ),
				$css_version
			);
		}

		wp_localize_script(
			'sdfw-admin-app',
			'sdfwAdmin',
			$this->get_client_data()
		);
	}

	/**
	 * Render the app mount point.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stockdesk-for-woocommerce' ) );
		}
		?>
		<div class="wrap sdfw-wrap sdfw-app-wrap">
			<div id="sdfw-app-root"></div>
		</div>
		<?php
	}

	/**
	 * Build localized client bootstrap data.
	 *
	 * @return array<string, mixed>
	 */
	private function get_client_data(): array {
		return array(
			'rootUrl'     => admin_url( 'admin.php?page=stockdesk' ),
			'initialPath' => $this->get_initial_path(),
			'logoUrl'     => SDFW_PLUGIN_URL . 'assets/logo.svg',
			'version'     => SDFW_VERSION,
			'supportUrl'  => self::SUPPORT_URL,
			'reviewUrl'   => self::REVIEW_URL,
			'inventory'   => array(
				'ajaxUrl'                 => admin_url( 'admin-ajax.php' ),
				'nonce'                   => wp_create_nonce( 'sdfw_inventory_nonce' ),
				'perPage'                 => 10,
				'globalLowStockThreshold' => SDFW_Low_Stock_Resolver::instance()->get_global_threshold(),
				'i18n'                    => array(
					'requestFailed' => __( 'Request failed.', 'stockdesk-for-woocommerce' ),
				),
			),
			'import'      => SDFW_Import::instance()->get_client_data(),
			'export'      => SDFW_Export::instance()->get_client_data(),
		);
	}

	/**
	 * Resolve initial SPA path from the request.
	 *
	 * @return string
	 */
	private function get_initial_path(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only SPA route, not a state-changing action; value is validated against an allow-list below.
		$path = isset( $_GET['path'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['path'] ) ) : '/inventory';
		if ( ! str_starts_with( $path, '/' ) ) {
			$path = '/' . $path;
		}

		if ( ! in_array( $path, self::ALLOWED_PATHS, true ) ) {
			return '/inventory';
		}

		return $path;
	}
}
