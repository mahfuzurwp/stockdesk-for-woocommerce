<?php
/**
 * Inventory list and stock management page.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Admin_Page {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Admin_Page|null
	 */
	private static $instance = null;

	/**
	 * Default rows per page.
	 *
	 * @var int
	 */
	private $per_page = 10;

	/**
	 * Maximum rows per page allowed via screen options.
	 *
	 * @var int
	 */
	private $max_per_page = 200;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Admin_Page
	 */
	public static function instance(): SDFW_Admin_Page {
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
		add_action( 'admin_menu', array( $this, 'register_menu' ), 56 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_sdfw_save_row', array( $this, 'handle_row_save' ) );
		add_action( 'admin_post_sdfw_bulk_update', array( $this, 'handle_bulk_update' ) );
		add_action( 'load-product_page_stockdesk-inventory', array( $this, 'register_screen_options' ) );
		add_filter( 'set-screen-option', array( $this, 'save_screen_options' ), 10, 3 );
		add_filter( 'removable_query_args', array( $this, 'register_removable_query_args' ) );
	}

	/**
	 * Register query args that should be removed after first page load.
	 *
	 * @param array $removable_query_args Existing removable query args.
	 * @return array
	 */
	public function register_removable_query_args( array $removable_query_args ): array {
		$removable_query_args[] = 'sdfw_notice';
		$removable_query_args[] = 'sdfw_updated';
		return array_values( array_unique( $removable_query_args ) );
	}

	/**
	 * Register screen options for the inventory page.
	 *
	 * @return void
	 */
	public function register_screen_options(): void {
		add_screen_option(
			'per_page',
			array(
				/* translators: screen option label. */
				'label'   => __( 'Number of items per page:', 'stockdesk-for-woocommerce' ),
				'default' => $this->per_page,
				'option'  => 'sdfw_inventory_per_page',
			)
		);
	}

	/**
	 * Save custom screen options.
	 *
	 * @param mixed  $status Existing status.
	 * @param string $option Option name.
	 * @param mixed  $value  Incoming value.
	 * @return mixed
	 */
	public function save_screen_options( $status, string $option, $value ) {
		if ( 'sdfw_inventory_per_page' !== $option ) {
			return $status;
		}

		$per_page = max( 1, min( $this->max_per_page, absint( (string) $value ) ) );
		return $per_page;
	}

	/**
	 * Register admin menu under WooCommerce.
	 *
	 * @return void
	 */
	public function register_menu(): void {
		add_submenu_page(
			'edit.php?post_type=product',
			__( 'StockDesk Inventory', 'stockdesk-for-woocommerce' ),
			__( 'StockDesk', 'stockdesk-for-woocommerce' ),
			'manage_woocommerce',
			'stockdesk-inventory',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Hook suffix.
	 * @return void
	 */
	public function enqueue_assets( string $hook ): void {
		if ( 'product_page_stockdesk-inventory' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'sdfw-admin',
			SDFW_PLUGIN_URL . 'assets/admin.css',
			array(),
			SDFW_VERSION
		);

		wp_register_script(
			'sdfw-admin',
			SDFW_PLUGIN_URL . 'assets/admin.js',
			array(),
			SDFW_VERSION,
			true
		);

		wp_add_inline_script(
			'sdfw-admin',
			'window.sdfwAdmin = ' . wp_json_encode(
				array(
					'i18n' => array(
						'bulkEditOne'       => __( 'Bulk edit 1 selected product', 'stockdesk-for-woocommerce' ),
						'bulkEditMany'      => __( 'Bulk edit %d selected products', 'stockdesk-for-woocommerce' ),
						'selectAtLeastOne'  => __( 'Select at least one product to use bulk edit.', 'stockdesk-for-woocommerce' ),
					),
				)
			) . ';',
			'before'
		);

		wp_enqueue_script( 'sdfw-admin' );
	}

	/**
	 * Render inventory page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'stockdesk-for-woocommerce' ) );
		}

		$filters    = $this->get_filters();
		$paged      = max( 1, absint( $filters['paged'] ) );
		$per_page   = $this->get_user_per_page();
		$query_data = $this->query_inventory_rows( $filters, $paged, $per_page );
		$base_url   = admin_url( 'edit.php?post_type=product&page=stockdesk-inventory' );
		?>
		<div class="wrap sdfw-wrap">
			<h1><?php esc_html_e( 'StockDesk Inventory', 'stockdesk-for-woocommerce' ); ?></h1>

			<?php $this->render_messages(); ?>

			<div class="tablenav top sdfw-tablenav">
				<div class="alignleft actions bulkactions">
					<label for="sdfw-bulk-action-selector" class="screen-reader-text"><?php esc_html_e( 'Select bulk action', 'stockdesk-for-woocommerce' ); ?></label>
					<select id="sdfw-bulk-action-selector">
						<option value=""><?php esc_html_e( 'Bulk actions', 'stockdesk-for-woocommerce' ); ?></option>
						<option value="edit"><?php esc_html_e( 'Edit', 'stockdesk-for-woocommerce' ); ?></option>
					</select>
					<button type="button" id="sdfw-bulk-action-apply" class="button action"><?php esc_html_e( 'Apply', 'stockdesk-for-woocommerce' ); ?></button>
				</div>
				<form class="alignleft actions" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="product" />
					<input type="hidden" name="page" value="stockdesk-inventory" />
					<input type="hidden" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" />
					<?php
					wp_dropdown_categories(
						array(
							'show_option_all' => __( 'All categories', 'stockdesk-for-woocommerce' ),
							'taxonomy'        => 'product_cat',
							'name'            => 'category',
							'selected'        => (int) $filters['category'],
							'hide_empty'      => false,
							'value_field'     => 'term_id',
						)
					);
					?>
					<select name="product_type">
						<?php foreach ( $this->get_product_type_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['product_type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<select name="stock_status">
						<?php foreach ( $this->get_stock_status_options() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['stock_status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
					<?php $brand_taxonomy = $this->get_brand_taxonomy(); ?>
					<?php if ( $brand_taxonomy ) : ?>
						<?php
						wp_dropdown_categories(
							array(
								'show_option_all' => __( 'All brands', 'stockdesk-for-woocommerce' ),
								'taxonomy'        => $brand_taxonomy,
								'name'            => 'brand',
								'selected'        => (int) $filters['brand'],
								'hide_empty'      => false,
								'value_field'     => 'term_id',
							)
						);
						?>
					<?php endif; ?>
					<button class="button" type="submit"><?php esc_html_e( 'Filter', 'stockdesk-for-woocommerce' ); ?></button>
				</form>
				<form class="search-form" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
					<input type="hidden" name="post_type" value="product" />
					<input type="hidden" name="page" value="stockdesk-inventory" />
					<?php $this->render_hidden_filter_fields( $filters, array( 'search', 'paged' ) ); ?>
					<p class="search-box">
						<label class="screen-reader-text" for="sdfw-search-input"><?php esc_html_e( 'Search products', 'stockdesk-for-woocommerce' ); ?></label>
						<input type="search" id="sdfw-search-input" name="s" value="<?php echo esc_attr( $filters['search'] ); ?>" />
						<button class="button" type="submit"><?php esc_html_e( 'Search products', 'stockdesk-for-woocommerce' ); ?></button>
					</p>
				</form>
				<br class="clear" />
			</div>

			<form id="sdfw-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="sdfw_bulk_update" />
				<?php wp_nonce_field( 'sdfw_bulk_update_action', 'sdfw_nonce' ); ?>
				<input type="hidden" name="filters" value="<?php echo esc_attr( wp_json_encode( $filters ) ); ?>" />
			</form>

			<table class="widefat striped sdfw-table">
				<thead>
					<tr>
						<th class="check-column">
							<input id="sdfw-select-all" type="checkbox" />
						</th>
						<th><?php esc_html_e( 'Product', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Manage stock', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Stock qty', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Low stock threshold', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Stock status', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Allow backorders', 'stockdesk-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'stockdesk-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<tr id="sdfw-bulk-edit-row" class="sdfw-bulk-edit-row" hidden>
						<td class="check-column"></td>
						<td>
							<strong>
								<?php
								printf(
									/* translators: %d selected products count. */
									esc_html__( 'Bulk edit %d selected products', 'stockdesk-for-woocommerce' ),
									0
								);
								?>
							</strong>
						</td>
						<td>
							<select name="sdfw_bulk_manage_stock" class="sdfw-bulk-manage-stock" form="sdfw-bulk-form">
								<option value="keep"><?php esc_html_e( 'No change', 'stockdesk-for-woocommerce' ); ?></option>
								<option value="yes"><?php esc_html_e( 'Set On', 'stockdesk-for-woocommerce' ); ?></option>
								<option value="no"><?php esc_html_e( 'Set Off', 'stockdesk-for-woocommerce' ); ?></option>
							</select>
						</td>
						<td><input name="sdfw_bulk_stock_qty" class="sdfw-bulk-stock-dependent" type="number" step="1" placeholder="<?php esc_attr_e( 'No change', 'stockdesk-for-woocommerce' ); ?>" form="sdfw-bulk-form" /></td>
						<td>
							<select name="sdfw_bulk_low_stock_threshold_mode" class="sdfw-bulk-stock-dependent sdfw-bulk-threshold-mode" form="sdfw-bulk-form">
								<option value="no_change"><?php esc_html_e( 'No change', 'stockdesk-for-woocommerce' ); ?></option>
								<option value="set"><?php esc_html_e( 'Set value', 'stockdesk-for-woocommerce' ); ?></option>
								<option value="clear"><?php esc_html_e( 'Use global settings', 'stockdesk-for-woocommerce' ); ?></option>
							</select>
							<input name="sdfw_bulk_low_stock_threshold" class="sdfw-bulk-stock-dependent sdfw-bulk-threshold-input" type="number" step="1" min="0" placeholder="<?php esc_attr_e( 'Enter value', 'stockdesk-for-woocommerce' ); ?>" form="sdfw-bulk-form" disabled />
						</td>
						<td>
							<select name="sdfw_bulk_stock_status" form="sdfw-bulk-form">
								<option value="keep"><?php esc_html_e( 'No change', 'stockdesk-for-woocommerce' ); ?></option>
								<?php foreach ( wc_get_product_stock_status_options() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<select name="sdfw_bulk_backorders" class="sdfw-bulk-stock-dependent" form="sdfw-bulk-form">
								<option value="keep"><?php esc_html_e( 'No change', 'stockdesk-for-woocommerce' ); ?></option>
								<?php foreach ( wc_get_product_backorder_options() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
						<td>
							<button class="button button-primary sdfw-update-button" type="submit" form="sdfw-bulk-form"><?php esc_html_e( 'Update', 'stockdesk-for-woocommerce' ); ?></button>
							<button class="button sdfw-bulk-cancel" type="button"><?php esc_html_e( 'Cancel', 'stockdesk-for-woocommerce' ); ?></button>
						</td>
					</tr>
					<?php if ( empty( $query_data['items'] ) ) : ?>
						<tr>
							<td colspan="8"><?php esc_html_e( 'No products found for current filter criteria.', 'stockdesk-for-woocommerce' ); ?></td>
						</tr>
					<?php else : ?>
						<?php foreach ( $query_data['items'] as $item ) : ?>
							<?php $this->render_row( $item, $filters ); ?>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<div class="tablenav bottom">
				<?php $this->render_tablenav_pagination( $base_url, $filters, $paged, (int) $query_data['total'], $per_page, 'bottom' ); ?>
				<br class="clear" />
			</div>
		</div>
		<?php
	}

	/**
	 * Render a row.
	 *
	 * @param array $item    Product row data.
	 * @param array $filters Current page filters.
	 * @return void
	 */
	private function render_row( array $item, array $filters ): void {
		$row_product = wc_get_product( (int) $item['id'] );
		if ( ! $row_product ) {
			return;
		}

		$manage_stock     = $row_product->get_manage_stock() ? 'yes' : 'no';
		$stock_qty        = $row_product->get_stock_quantity();
		$stock_status     = $row_product->get_stock_status();
		$backorders       = $row_product->get_backorders();
		$threshold_single = $row_product->get_low_stock_amount();
		$threshold_global = $this->get_global_threshold();
		$title            = $row_product->get_name();
		$form_id          = 'sdfw-row-form-' . (string) $row_product->get_id();
		$is_manage_stock  = 'yes' === $manage_stock;
		?>
		<tr>
			<td class="check-column">
				<input class="sdfw-select-row" type="checkbox" name="sdfw_selected_ids[]" value="<?php echo esc_attr( (string) $row_product->get_id() ); ?>" form="sdfw-bulk-form" />
			</td>
			<td>
				<strong><?php echo esc_html( $title ); ?></strong>
				<div class="description"><?php echo esc_html( sprintf( __( 'Type: %s', 'stockdesk-for-woocommerce' ), $this->resolve_product_type_label( $row_product ) ) ); ?></div>
				<div class="description"><?php echo esc_html( sprintf( __( 'SKU: %s', 'stockdesk-for-woocommerce' ), (string) $row_product->get_sku() ?: '—' ) ); ?></div>
				<?php if ( 'product_variation' === $item['post_type'] && ! empty( $item['parent_name'] ) ) : ?>
					<div class="description"><?php echo esc_html( sprintf( __( 'Variation of: %s', 'stockdesk-for-woocommerce' ), $item['parent_name'] ) ); ?></div>
				<?php endif; ?>
			</td>
			<td>
				<form id="<?php echo esc_attr( $form_id ); ?>" class="sdfw-row-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="sdfw_save_row" />
					<input type="hidden" name="sdfw_row_product_id" value="<?php echo esc_attr( (string) $row_product->get_id() ); ?>" />
					<input type="hidden" name="sdfw_referrer" value="<?php echo esc_attr( wp_json_encode( $filters ) ); ?>" />
					<?php wp_nonce_field( 'sdfw_save_row_action', 'sdfw_nonce' ); ?>
				</form>
				<select name="sdfw_manage_stock" class="sdfw-manage-stock" form="<?php echo esc_attr( $form_id ); ?>">
					<option value="yes" <?php selected( $manage_stock, 'yes' ); ?>><?php esc_html_e( 'On', 'stockdesk-for-woocommerce' ); ?></option>
					<option value="no" <?php selected( $manage_stock, 'no' ); ?>><?php esc_html_e( 'Off', 'stockdesk-for-woocommerce' ); ?></option>
				</select>
			</td>
			<td>
				<input name="sdfw_stock_qty" class="sdfw-stock-dependent" type="number" step="1" value="<?php echo esc_attr( null !== $stock_qty ? (string) $stock_qty : '' ); ?>" form="<?php echo esc_attr( $form_id ); ?>" <?php disabled( ! $is_manage_stock ); ?> />
			</td>
			<td>
				<input name="sdfw_low_stock_threshold" class="sdfw-stock-dependent" type="number" step="1" min="0" value="<?php echo esc_attr( null !== $threshold_single ? (string) $threshold_single : '' ); ?>" placeholder="<?php echo esc_attr( (string) $threshold_global ); ?>" form="<?php echo esc_attr( $form_id ); ?>" <?php disabled( ! $is_manage_stock ); ?> />
			</td>
			<td>
				<select name="sdfw_stock_status" form="<?php echo esc_attr( $form_id ); ?>">
					<?php foreach ( wc_get_product_stock_status_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $stock_status, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<select name="sdfw_backorders" class="sdfw-stock-dependent" form="<?php echo esc_attr( $form_id ); ?>" <?php disabled( ! $is_manage_stock ); ?>>
					<?php foreach ( wc_get_product_backorder_options() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $backorders, $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td>
				<button class="button button-large button-primary sdfw-update-button" type="submit" form="<?php echo esc_attr( $form_id ); ?>"><?php esc_html_e( 'Update', 'stockdesk-for-woocommerce' ); ?></button>
			</td>
		</tr>
		<?php
	}

	/**
	 * Handle single row save.
	 *
	 * @return void
	 */
	public function handle_row_save(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'stockdesk-for-woocommerce' ) );
		}

		check_admin_referer( 'sdfw_save_row_action', 'sdfw_nonce' );

		$product_id = isset( $_POST['sdfw_row_product_id'] ) ? absint( wp_unslash( $_POST['sdfw_row_product_id'] ) ) : 0;
		$redirect   = $this->build_redirect_url_from_post();

		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			wp_safe_redirect( add_query_arg( 'sdfw_notice', 'invalid_product', $redirect ) );
			exit;
		}

		$manage_stock = isset( $_POST['sdfw_manage_stock'] ) && 'yes' === sanitize_text_field( wp_unslash( $_POST['sdfw_manage_stock'] ) );
		$stock_status = isset( $_POST['sdfw_stock_status'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_stock_status'] ) ) : $product->get_stock_status();
		$backorders   = isset( $_POST['sdfw_backorders'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_backorders'] ) ) : $product->get_backorders();
		$stock_qty    = isset( $_POST['sdfw_stock_qty'] ) && '' !== $_POST['sdfw_stock_qty'] ? wc_stock_amount( wp_unslash( $_POST['sdfw_stock_qty'] ) ) : null;
		if ( ! in_array( $stock_status, array_keys( wc_get_product_stock_status_options() ), true ) ) {
			$stock_status = $product->get_stock_status();
		}

		$product->set_manage_stock( $manage_stock );
		$product->set_stock_status( $stock_status );
		if ( in_array( $backorders, array_keys( wc_get_product_backorder_options() ), true ) ) {
			$product->set_backorders( $backorders );
		}

		if ( $manage_stock && null !== $stock_qty ) {
			$product->set_stock_quantity( $stock_qty );
		}

		$threshold = isset( $_POST['sdfw_low_stock_threshold'] ) ? trim( (string) wp_unslash( $_POST['sdfw_low_stock_threshold'] ) ) : '';
		if ( '' === $threshold ) {
			$product->set_low_stock_amount( '' );
		} else {
			$product->set_low_stock_amount( max( 0, wc_stock_amount( $threshold ) ) );
		}

		$product->save();

		wp_safe_redirect( add_query_arg( 'sdfw_notice', 'row_saved', $redirect ) );
		exit;
	}

	/**
	 * Handle bulk update on filtered results.
	 *
	 * @return void
	 */
	public function handle_bulk_update(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized request.', 'stockdesk-for-woocommerce' ) );
		}

		check_admin_referer( 'sdfw_bulk_update_action', 'sdfw_nonce' );

		$filters = array();
		if ( isset( $_POST['filters'] ) ) {
			$filters_raw = json_decode( (string) wp_unslash( $_POST['filters'] ), true );
			if ( is_array( $filters_raw ) ) {
				$filters = $filters_raw;
			}
		}

		$filters = $this->sanitize_filters( $filters );
		$ids = array();
		if ( isset( $_POST['sdfw_selected_ids'] ) && is_array( $_POST['sdfw_selected_ids'] ) ) {
			$ids = array_map(
				'absint',
				wp_unslash( $_POST['sdfw_selected_ids'] )
			);
		}

		$ids = array_values( array_unique( array_filter( $ids ) ) );
		if ( empty( $ids ) ) {
			$redirect_no_selection = add_query_arg(
				array_merge(
					array(
						'post_type'   => 'product',
						'page'        => 'stockdesk-inventory',
						'sdfw_notice' => 'bulk_empty_selection',
					),
					$filters
				),
				admin_url( 'edit.php' )
			);
			wp_safe_redirect( $redirect_no_selection );
			exit;
		}

		$bulk_manage_stock = isset( $_POST['sdfw_bulk_manage_stock'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_bulk_manage_stock'] ) ) : 'keep';
		$bulk_status       = isset( $_POST['sdfw_bulk_stock_status'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_bulk_stock_status'] ) ) : 'keep';
		$bulk_backorders   = isset( $_POST['sdfw_bulk_backorders'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_bulk_backorders'] ) ) : 'keep';
		$bulk_thr_mode     = isset( $_POST['sdfw_bulk_low_stock_threshold_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['sdfw_bulk_low_stock_threshold_mode'] ) ) : 'no_change';
		$bulk_qty_raw      = isset( $_POST['sdfw_bulk_stock_qty'] ) ? trim( (string) wp_unslash( $_POST['sdfw_bulk_stock_qty'] ) ) : '';
		$bulk_thr_raw      = isset( $_POST['sdfw_bulk_low_stock_threshold'] ) ? trim( (string) wp_unslash( $_POST['sdfw_bulk_low_stock_threshold'] ) ) : '';

		$bulk_qty = '' !== $bulk_qty_raw ? wc_stock_amount( $bulk_qty_raw ) : null;
		$bulk_thr = 'set' === $bulk_thr_mode && '' !== $bulk_thr_raw ? max( 0, wc_stock_amount( $bulk_thr_raw ) ) : null;
		$updated  = 0;
		$allow_stock_dependent_updates = 'yes' === $bulk_manage_stock;

		foreach ( $ids as $product_id ) {
			$product = wc_get_product( $product_id );
			if ( ! $product ) {
				continue;
			}

			if ( in_array( $bulk_manage_stock, array( 'yes', 'no' ), true ) ) {
				$product->set_manage_stock( 'yes' === $bulk_manage_stock );
			}

			if ( in_array( $bulk_status, array_keys( wc_get_product_stock_status_options() ), true ) ) {
				$product->set_stock_status( $bulk_status );
			}
			if ( $allow_stock_dependent_updates && in_array( $bulk_backorders, array_keys( wc_get_product_backorder_options() ), true ) ) {
				$product->set_backorders( $bulk_backorders );
			}

			if ( $allow_stock_dependent_updates && null !== $bulk_qty ) {
				$product->set_stock_quantity( $bulk_qty );
			}

			if ( $allow_stock_dependent_updates && 'clear' === $bulk_thr_mode ) {
				$product->set_low_stock_amount( '' );
			} elseif ( $allow_stock_dependent_updates && 'set' === $bulk_thr_mode && null !== $bulk_thr ) {
				$product->set_low_stock_amount( $bulk_thr );
			}

			$product->save();
			++$updated;
		}

		$redirect = add_query_arg(
			array_merge(
				array(
					'post_type'    => 'product',
					'page'         => 'stockdesk-inventory',
					'sdfw_notice'  => 'bulk_saved',
					'sdfw_updated' => $updated,
				),
				$filters
			),
			admin_url( 'edit.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Query products and variations.
	 *
	 * @param array $filters  Filters.
	 * @param int   $paged    Current page.
	 * @param int   $per_page Per page. -1 for all.
	 * @return array<string,mixed>
	 */
	private function query_inventory_rows( array $filters, int $paged, int $per_page ): array {
		global $wpdb;

		$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
		$joins      = array(
			" LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent ",
			" LEFT JOIN {$wpdb->postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku' ",
			" LEFT JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = {$owner_expr} ",
			" LEFT JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'product_cat' ",
			" LEFT JOIN {$wpdb->term_relationships} tr_type ON tr_type.object_id = {$owner_expr} ",
			" LEFT JOIN {$wpdb->term_taxonomy} tt_type ON tt_type.term_taxonomy_id = tr_type.term_taxonomy_id AND tt_type.taxonomy = 'product_type' ",
			" LEFT JOIN {$wpdb->terms} t_type ON t_type.term_id = tt_type.term_id ",
		);
		$where      = array(
			" p.post_type IN ('product', 'product_variation') ",
			" p.post_status IN ('publish', 'private') ",
		);
		$params     = array();

		if ( ! empty( $filters['category'] ) ) {
			$where[]  = 'tt_cat.term_id = %d';
			$params[] = (int) $filters['category'];
		}

		$brand_taxonomy = $this->get_brand_taxonomy();
		if ( $brand_taxonomy && ! empty( $filters['brand'] ) ) {
			$joins[]  = " LEFT JOIN {$wpdb->term_relationships} tr_brand ON tr_brand.object_id = {$owner_expr} ";
			$joins[]  = $wpdb->prepare( " LEFT JOIN {$wpdb->term_taxonomy} tt_brand ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id AND tt_brand.taxonomy = %s ", $brand_taxonomy );
			$where[]  = 'tt_brand.term_id = %d';
			$params[] = (int) $filters['brand'];
		}

		if ( ! empty( $filters['product_type'] ) && 'all' !== $filters['product_type'] ) {
			if ( 'variation' === $filters['product_type'] ) {
				$where[] = " p.post_type = 'product_variation' ";
			} else {
				$where[]  = 't_type.slug = %s';
				$params[] = $filters['product_type'];
			}
		}

		if ( ! empty( $filters['search'] ) ) {
			$like      = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
			$where[]   = '(p.post_title LIKE %s OR pm_sku.meta_value LIKE %s OR parent.post_title LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$sql_base = "
			FROM {$wpdb->posts} p
			" . implode( "\n", $joins ) . '
			WHERE ' . implode( ' AND ', $where );

		$list_sql = "
			SELECT DISTINCT
				p.ID as id,
				p.post_type as post_type,
				parent.post_title as parent_name
			" . $sql_base . '
			ORDER BY p.post_title ASC, p.ID DESC
		';
		if ( ! empty( $params ) ) {
			$list_sql = $wpdb->prepare( $list_sql, $params );
		}

		// For stock-based filters, load full result set, then filter and paginate in PHP for correct totals.
		// Keep this lightweight by using postmeta (with primed cache) instead of wc_get_product() per row.
		if ( 'all' !== $filters['stock_status'] ) {
			$items = $wpdb->get_results( $list_sql, ARRAY_A );
			if ( empty( $items ) ) {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}

			$item_ids = array_map(
				static function( array $item ): int {
					return (int) $item['id'];
				},
				$items
			);
			update_meta_cache( 'post', $item_ids );

			$items = array_values(
				array_filter(
					$items,
					function( array $item ) use ( $filters ): bool {
						return $this->passes_stock_filter_by_meta( (int) $item['id'], $filters['stock_status'] );
					}
				)
			);

			$total = count( $items );
			if ( $per_page > 0 ) {
				$offset = ( $paged - 1 ) * $per_page;
				$items  = array_slice( $items, $offset, $per_page );
			}

			return array(
				'items' => $items,
				'total' => $total,
			);
		}

		$total_sql = 'SELECT COUNT(DISTINCT p.ID) ' . $sql_base;
		if ( ! empty( $params ) ) {
			$total_sql = $wpdb->prepare( $total_sql, $params );
		}
		$total = (int) $wpdb->get_var( $total_sql );

		if ( $per_page > 0 ) {
			$offset   = ( $paged - 1 ) * $per_page;
			$list_sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d ', $per_page, $offset );
		}

		$items = $wpdb->get_results( $list_sql, ARRAY_A );

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Determine if product passes stock filter.
	 *
	 * @param int    $product_id   Product ID.
	 * @param string     $stock_filter Stock filter.
	 * @return bool
	 */
	private function passes_stock_filter_by_meta( int $product_id, string $stock_filter ): bool {
		if ( 'all' === $stock_filter ) {
			return true;
		}

		$status = (string) get_post_meta( $product_id, '_stock_status', true );
		if ( 'low_stock' !== $stock_filter ) {
			return $status === $stock_filter;
		}

		$manage    = (string) get_post_meta( $product_id, '_manage_stock', true );
		$qty_raw   = get_post_meta( $product_id, '_stock', true );
		$qty       = '' === (string) $qty_raw ? null : (int) wc_stock_amount( $qty_raw );
		$threshold = get_post_meta( $product_id, '_low_stock_amount', true );
		$used_limit  = null !== $threshold && '' !== (string) $threshold ? (int) $threshold : $this->get_global_threshold();

		return 'yes' === $manage && null !== $qty && $qty > 0 && $qty <= $used_limit;
	}

	/**
	 * Build redirect URL from POST payload.
	 *
	 * @return string
	 */
	private function build_redirect_url_from_post(): string {
		$default = admin_url( 'edit.php?post_type=product&page=stockdesk-inventory' );
		if ( ! isset( $_POST['sdfw_referrer'] ) ) {
			return $default;
		}

		$decoded = json_decode( (string) wp_unslash( $_POST['sdfw_referrer'] ), true );
		if ( ! is_array( $decoded ) ) {
			return $default;
		}

		$decoded = $this->sanitize_filters( $decoded );

		return add_query_arg(
			array_merge(
				array(
					'post_type' => 'product',
					'page'      => 'stockdesk-inventory',
				),
				$decoded
			),
			admin_url( 'edit.php' )
		);
	}

	/**
	 * Render admin messages.
	 *
	 * @return void
	 */
	private function render_messages(): void {
		if ( empty( $_GET['sdfw_notice'] ) ) {
			return;
		}

		$notice = sanitize_text_field( wp_unslash( $_GET['sdfw_notice'] ) );
		$text   = '';
		$type   = 'success';

		switch ( $notice ) {
			case 'row_saved':
				$text = __( 'Stock row updated successfully.', 'stockdesk-for-woocommerce' );
				break;
			case 'bulk_saved':
				$count = isset( $_GET['sdfw_updated'] ) ? absint( $_GET['sdfw_updated'] ) : 0;
				$text  = sprintf( __( 'Bulk stock update complete. Updated %d items.', 'stockdesk-for-woocommerce' ), $count );
				break;
			case 'bulk_empty_selection':
				$type = 'warning';
				$text = __( 'Select at least one row before applying bulk update.', 'stockdesk-for-woocommerce' );
				break;
			default:
				$type = 'error';
				$text = __( 'Action could not be completed.', 'stockdesk-for-woocommerce' );
				break;
		}
		?>
		<div class="notice notice-<?php echo esc_attr( $type ); ?> is-dismissible"><p><?php echo esc_html( $text ); ?></p></div>
		<?php
	}

	/**
	 * Render Woo-style tablenav pagination section.
	 *
	 * @param string $base_url  Base URL.
	 * @param array  $filters   Filters.
	 * @param int    $paged     Current page.
	 * @param int    $total     Total rows.
	 * @param int    $per_page  Per page.
	 * @param string $context   Pagination context.
	 * @return void
	 */
	private function render_tablenav_pagination( string $base_url, array $filters, int $paged, int $total, int $per_page, string $context = 'top' ): void {
		$total_pages = $per_page > 0 ? (int) ceil( $total / $per_page ) : 1;
		$total_pages = max( 1, $total_pages );
		$paged       = max( 1, min( $paged, $total_pages ) );
		$is_one_page = $total_pages <= 1;

		$url_args = array_merge(
			array(
				'post_type' => 'product',
				'page'      => 'stockdesk-inventory',
			),
			$filters
		);
		unset( $url_args['paged'] );

		$current_url = add_query_arg( $url_args, $base_url );
		$first_url   = add_query_arg( 'paged', 1, $current_url );
		$prev_url    = add_query_arg( 'paged', max( 1, $paged - 1 ), $current_url );
		$next_url    = add_query_arg( 'paged', min( $total_pages, $paged + 1 ), $current_url );
		$last_url    = add_query_arg( 'paged', $total_pages, $current_url );
		$input_id    = 'current-page-selector-' . sanitize_html_class( $context );
		$paging_id   = 'table-paging-' . sanitize_html_class( $context );
		?>
		<div class="tablenav-pages<?php echo $is_one_page ? ' one-page' : ''; ?>">
			<span class="displaying-num">
				<?php
				echo esc_html(
					sprintf(
						/* translators: %d item count. */
						_n( '%d item', '%d items', $total, 'stockdesk-for-woocommerce' ),
						$total
					)
				);
				?>
			</span>
			<span class="pagination-links">
				<?php if ( $is_one_page || $paged <= 1 ) : ?>
					<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&laquo;</span>
					<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&lsaquo;</span>
				<?php else : ?>
					<a class="first-page button" href="<?php echo esc_url( $first_url ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'First page', 'stockdesk-for-woocommerce' ); ?></span>
						<span aria-hidden="true">&laquo;</span>
					</a>
					<a class="prev-page button" href="<?php echo esc_url( $prev_url ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Previous page', 'stockdesk-for-woocommerce' ); ?></span>
						<span aria-hidden="true">&lsaquo;</span>
					</a>
				<?php endif; ?>

				<span class="paging-input">
					<label for="<?php echo esc_attr( $input_id ); ?>" class="screen-reader-text"><?php esc_html_e( 'Current page', 'stockdesk-for-woocommerce' ); ?></label>
					<input class="current-page" id="<?php echo esc_attr( $input_id ); ?>" type="text" name="paged" value="<?php echo esc_attr( (string) $paged ); ?>" size="<?php echo esc_attr( strlen( (string) $total_pages ) ); ?>" aria-describedby="<?php echo esc_attr( $paging_id ); ?>" form="sdfw-paging-form-<?php echo esc_attr( $context ); ?>" />
					<span class="tablenav-paging-text" id="<?php echo esc_attr( $paging_id ); ?>">
						<?php
						echo esc_html(
							sprintf(
								/* translators: %s total pages. */
								__( 'of %s', 'stockdesk-for-woocommerce' ),
								number_format_i18n( $total_pages )
							)
						);
						?>
					</span>
				</span>

				<?php if ( $is_one_page || $paged >= $total_pages ) : ?>
					<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&rsaquo;</span>
					<span class="tablenav-pages-navspan button disabled" aria-hidden="true">&raquo;</span>
				<?php else : ?>
					<a class="next-page button" href="<?php echo esc_url( $next_url ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Next page', 'stockdesk-for-woocommerce' ); ?></span>
						<span aria-hidden="true">&rsaquo;</span>
					</a>
					<a class="last-page button" href="<?php echo esc_url( $last_url ); ?>">
						<span class="screen-reader-text"><?php esc_html_e( 'Last page', 'stockdesk-for-woocommerce' ); ?></span>
						<span aria-hidden="true">&raquo;</span>
					</a>
				<?php endif; ?>
			</span>
			<form id="sdfw-paging-form-<?php echo esc_attr( $context ); ?>" method="get" action="<?php echo esc_url( admin_url( 'edit.php' ) ); ?>">
				<input type="hidden" name="post_type" value="product" />
				<input type="hidden" name="page" value="stockdesk-inventory" />
				<?php $this->render_hidden_filter_fields( $filters, array( 'paged' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * Get and sanitize active filters.
	 *
	 * @return array<string,mixed>
	 */
	private function get_filters(): array {
		return $this->sanitize_filters( $_GET );
	}

	/**
	 * Sanitize incoming filter set.
	 *
	 * @param array $raw Raw source.
	 * @return array<string,mixed>
	 */
	private function sanitize_filters( array $raw ): array {
		$stock_status = isset( $raw['stock_status'] ) ? sanitize_text_field( (string) $raw['stock_status'] ) : 'all';
		$allowed      = array_keys( $this->get_stock_status_options() );
		if ( ! in_array( $stock_status, $allowed, true ) ) {
			$stock_status = 'all';
		}

		$product_type = isset( $raw['product_type'] ) ? sanitize_text_field( (string) $raw['product_type'] ) : 'all';
		$type_allowed = array_keys( $this->get_product_type_options() );
		if ( ! in_array( $product_type, $type_allowed, true ) ) {
			$product_type = 'all';
		}

		return array(
			'stock_status' => $stock_status,
			'category'     => isset( $raw['category'] ) ? absint( $raw['category'] ) : 0,
			'brand'        => isset( $raw['brand'] ) ? absint( $raw['brand'] ) : 0,
			'product_type' => $product_type,
			'search'       => isset( $raw['s'] ) ? sanitize_text_field( (string) $raw['s'] ) : '',
			'paged'        => isset( $raw['paged'] ) ? max( 1, absint( $raw['paged'] ) ) : 1,
		);
	}

	/**
	 * Resolve active brand taxonomy name if available.
	 *
	 * @return string
	 */
	private function get_brand_taxonomy(): string {
		$known_taxonomies = array(
			'product_brand',
			'pwb-brand',
			'pa_brand',
			'berocket_brand',
			'yith_product_brand',
		);

		foreach ( $known_taxonomies as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				return $taxonomy;
			}
		}

		return '';
	}

	/**
	 * Resolve product type label.
	 *
	 * @param WC_Product $product Product.
	 * @return string
	 */
	private function resolve_product_type_label( WC_Product $product ): string {
		$type = $product->get_type();
		if ( 'variation' === $type || $product->is_type( 'variation' ) ) {
			return __( 'Variation', 'stockdesk-for-woocommerce' );
		}

		$options = $this->get_product_type_options();
		return $options[ $type ] ?? ucfirst( $type );
	}

	/**
	 * Render hidden fields for filter persistence.
	 *
	 * @param array $filters Filter map.
	 * @param array $exclude Keys to exclude.
	 * @return void
	 */
	private function render_hidden_filter_fields( array $filters, array $exclude = array() ): void {
		foreach ( $filters as $key => $value ) {
			if ( in_array( $key, $exclude, true ) ) {
				continue;
			}

			$name = 'search' === $key ? 's' : $key;
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
		}
	}

	/**
	 * Get current user's configured items-per-page value.
	 *
	 * @return int
	 */
	private function get_user_per_page(): int {
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			return $this->per_page;
		}

		$saved = (int) get_user_option( 'sdfw_inventory_per_page', $user_id );
		if ( $saved <= 0 ) {
			return $this->per_page;
		}

		return min( $this->max_per_page, $saved );
	}

	/**
	 * Stock status options.
	 *
	 * @return array<string,string>
	 */
	private function get_stock_status_options(): array {
		return array(
			'all'         => __( 'All statuses', 'stockdesk-for-woocommerce' ),
			'instock'     => __( 'In stock', 'stockdesk-for-woocommerce' ),
			'outofstock'  => __( 'Out of stock', 'stockdesk-for-woocommerce' ),
			'onbackorder' => __( 'On backorder', 'stockdesk-for-woocommerce' ),
			'low_stock'   => __( 'Low stock', 'stockdesk-for-woocommerce' ),
		);
	}

	/**
	 * Product type options.
	 *
	 * @return array<string,string>
	 */
	private function get_product_type_options(): array {
		return array(
			'all'      => __( 'All types', 'stockdesk-for-woocommerce' ),
			'simple'   => __( 'Simple', 'stockdesk-for-woocommerce' ),
			'variable' => __( 'Variable', 'stockdesk-for-woocommerce' ),
			'grouped'  => __( 'Grouped', 'stockdesk-for-woocommerce' ),
			'external' => __( 'External', 'stockdesk-for-woocommerce' ),
			'variation'=> __( 'Variation', 'stockdesk-for-woocommerce' ),
		);
	}

	/**
	 * Get global low stock threshold.
	 *
	 * @return int
	 */
	private function get_global_threshold(): int {
		return max( 0, (int) get_option( 'woocommerce_notify_low_stock_amount', 2 ) );
	}

}
