<?php
/**
 * Main plugin bootstrap class.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once SDFW_PLUGIN_PATH . 'includes/admin/class-sdfw-admin-page.php';

final class SDFW_Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Plugin
	 */
	public static function instance(): SDFW_Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Boot plugin services.
	 *
	 * @return void
	 */
	public function boot(): void {
		add_action( 'plugins_loaded', array( $this, 'init_admin' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Declare compatibility with WooCommerce HPOS (custom order tables).
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility(): void {
		if ( ! class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
			return;
		}

		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SDFW_PLUGIN_FILE, true );
	}

	/**
	 * Initialize admin modules when WooCommerce is active.
	 *
	 * @return void
	 */
	public function init_admin(): void {
		if ( ! is_admin() ) {
			return;
		}

		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'woocommerce_missing_notice' ) );
			return;
		}

		SDFW_Admin_Page::instance()->register();
	}

	/**
	 * Show notice when WooCommerce is not active.
	 *
	 * @return void
	 */
	public function woocommerce_missing_notice(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p><?php echo esc_html__( 'StockDesk for WooCommerce requires WooCommerce to be installed and active.', 'stockdesk-for-woocommerce' ); ?></p>
		</div>
		<?php
	}
}
