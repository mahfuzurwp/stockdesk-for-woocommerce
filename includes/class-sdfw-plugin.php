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

// Register WordPress's built-in autoloader to load classes only when needed, eliminating manual requires
spl_autoload_register(function (string $class): void {
    // Only process classes belonging to this plugin to avoid conflicts
    if (!str_starts_with($class, 'SDFW_')) {
        return;
    }

    // Map class names to their file paths matching our plugin's naming convention
    $class_map = [
        'SDFW_Container'                         => 'includes/class-sdfw-container.php',
        'SDFW_Brand_Taxonomy_Resolver'           => 'includes/services/class-sdfw-brand-taxonomy-resolver.php',
        'SDFW_Stock_Options'                     => 'includes/services/class-sdfw-stock-options.php',
        'SDFW_Low_Stock_Resolver'                => 'includes/services/class-sdfw-low-stock-resolver.php',
        'SDFW_Product_Service'                   => 'includes/services/class-sdfw-product-service.php',
        'SDFW_Product_Repository'                => 'includes/repositories/interface-sdfw-product-repository.php',
        'SDFW_WPDB_Product_Repository'           => 'includes/repositories/class-sdfw-wpdb-product-repository.php',
        'SDFW_Inventory_Request'                 => 'includes/dto/class-sdfw-inventory-request.php',
        'SDFW_Admin_App'                         => 'includes/admin/class-sdfw-admin-app.php',
        'SDFW_Admin_Menus'                       => 'includes/admin/menus/class-sdfw-admin-menus.php',
        'SDFW_Inventory_Handler'                 => 'includes/admin/inventory/class-sdfw-inventory-handler.php',
        'SDFW_Export'                            => 'includes/admin/export/class-sdfw-export.php',
        'SDFW_Export_Formatter'                  => 'includes/admin/export/class-sdfw-export-formatter.php',
        'SDFW_Export_Query'                      => 'includes/admin/export/class-sdfw-export-query.php',
        'SDFW_Import'                            => 'includes/admin/import/class-sdfw-import.php',
        'SDFW_Import_Csv_Parser'                 => 'includes/admin/import/class-sdfw-import-csv-parser.php',
        'SDFW_Import_Mapper'                     => 'includes/admin/import/class-sdfw-import-mapper.php',
        'SDFW_Import_Validator'                  => 'includes/admin/import/class-sdfw-import-validator.php',
        'SDFW_Import_Processor'                  => 'includes/admin/import/class-sdfw-import-processor.php',
        'SDFW_Import_Session'                    => 'includes/admin/import/class-sdfw-import-session.php',
        'SDFW_Ajax_Response'                     => 'includes/admin/class-sdfw-ajax-response.php',
    ];

    // Load the file only if the class exists in our map and the file is readable
    if (isset($class_map[$class])) {
        $file_path = SDFW_PLUGIN_PATH . $class_map[$class];
        if (is_readable($file_path)) {
            require_once $file_path;
        }
    }
});

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
		add_action( 'plugins_loaded', array( $this, 'init_container' ) );
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
	}

	/**
	 * Initialize service container bindings.
	 *
	 * @return void
	 */
	public function init_container(): void {
		$container = SDFW_Container::instance();

		$container->bind( SDFW_Brand_Taxonomy_Resolver::class, fn() => SDFW_Brand_Taxonomy_Resolver::instance() );
		$container->bind( SDFW_Stock_Options::class, fn() => SDFW_Stock_Options::instance() );
		$container->bind( SDFW_Low_Stock_Resolver::class, fn() => SDFW_Low_Stock_Resolver::instance() );
		$container->bind( SDFW_Product_Service::class, fn() => SDFW_Product_Service::instance() );
		$container->bind( SDFW_Product_Repository::class, function () {
			global $wpdb;
			return new SDFW_WPDB_Product_Repository(
				$wpdb,
				SDFW_Brand_Taxonomy_Resolver::instance()
			);
		} );

		add_action( 'init', array( $this, 'init_admin' ) );
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

		SDFW_Admin_Menus::instance()->register();
		SDFW_Admin_App::instance()->register();
		SDFW_Inventory_Handler::instance()->register();
		SDFW_Export::instance()->register();
		SDFW_Import::instance()->register();
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
