<?php
/**
 * Inventory row save and bulk update handler.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Inventory_Handler {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Inventory_Handler|null
	 */
	private static $instance = null;

	/**
	 * Product service.
	 *
	 * @var SDFW_Product_Service
	 */
	private $product_service;

	/**
	 * Low stock resolver.
	 *
	 * @var SDFW_Low_Stock_Resolver
	 */
	private $low_stock_resolver;

	/**
	 * Product repository.
	 *
	 * @var SDFW_Product_Repository
	 */
	private $product_repository;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Inventory_Handler
	 */
	public static function instance(): SDFW_Inventory_Handler {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->product_service     = SDFW_Product_Service::instance();
		$this->low_stock_resolver  = SDFW_Low_Stock_Resolver::instance();
		$this->product_repository  = SDFW_Container::instance()->get( SDFW_Product_Repository::class );
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'wp_ajax_sdfw_inventory_options', array( $this, 'ajax_options' ) );
		add_action( 'wp_ajax_sdfw_inventory_list', array( $this, 'ajax_list' ) );
		add_action( 'wp_ajax_sdfw_inventory_save_row', array( $this, 'ajax_save_row' ) );
		add_action( 'wp_ajax_sdfw_inventory_bulk_update', array( $this, 'ajax_bulk_update' ) );
	}

	/**
	 * Verify AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function verify_ajax_request(): void {
		if ( ! check_ajax_referer( 'sdfw_inventory_nonce', 'nonce', false ) ) {
			SDFW_Ajax_Response::send_invalid_nonce();
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			SDFW_Ajax_Response::send_forbidden();
		}
	}

	/**
	 * AJAX: filter/form option elements.
	 *
	 * @return void
	 */
	public function ajax_options(): void {
		$this->verify_ajax_request();

		$stock_options  = SDFW_Stock_Options::instance();
		$brand_taxonomy = SDFW_Brand_Taxonomy_Resolver::instance()->resolve();

		$stock_status_options = $stock_options->get_stock_status_options();
		unset( $stock_status_options['all'] );

		$product_type_options = $stock_options->get_product_type_options();
		unset( $product_type_options['all'] );

		$wc_stock_statuses = wc_get_product_stock_status_options();
		$wc_backorders     = wc_get_product_backorder_options();

		wp_send_json_success(
			array(
				'stockStatuses'           => $this->map_elements( $wc_stock_statuses ),
				'stockStatusFilters'     => $this->map_elements( $stock_status_options ),
				'productTypes'           => $this->map_elements( $product_type_options ),
				'backorders'             => $this->map_elements( $wc_backorders ),
				'categories'             => $this->get_term_elements( 'product_cat' ),
				'brands'                 => $brand_taxonomy ? $this->get_term_elements( $brand_taxonomy ) : array(),
				'globalLowStockThreshold'=> $this->low_stock_resolver->get_global_threshold(),
			)
		);
	}

	/**
	 * AJAX: inventory list for DataViews.
	 *
	 * @return void
	 */
	public function ajax_list(): void {
		$this->verify_ajax_request();

		$view_raw = isset( $_POST['view'] ) ? wp_unslash( $_POST['view'] ) : '';
		$view     = is_string( $view_raw ) ? json_decode( $view_raw, true ) : null;
		if ( ! is_array( $view ) ) {
			$view = array();
		}

		$filters  = $this->filters_from_view( $view );
		$paged    = max( 1, absint( $view['page'] ?? 1 ) );
		$per_page = max( 1, min( 200, absint( $view['perPage'] ?? 10 ) ) );

		$query_data = $this->product_repository->get_inventory_items( $filters, $paged, $per_page );

		if ( ! empty( $query_data['error'] ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Could not load inventory.', 'stockdesk-for-woocommerce' ),
				'query_failed'
			);
		}

		$items      = array();
		$stock_options = SDFW_Stock_Options::instance();
		$type_labels   = $stock_options->get_product_type_options();

		foreach ( $query_data['items'] as $row ) {
			$hydrated = $this->hydrate_inventory_item( $row, $type_labels );
			if ( null !== $hydrated ) {
				$items[] = $hydrated;
			}
		}

		wp_send_json_success(
			array(
				'items' => $items,
				'total' => (int) $query_data['total'],
			)
		);
	}

	/**
	 * AJAX: save a single inventory row.
	 *
	 * @return void
	 */
	public function ajax_save_row(): void {
		$this->verify_ajax_request();

		$product_id = isset( $_POST['product_id'] ) ? absint( wp_unslash( $_POST['product_id'] ) ) : 0;
		$data_raw   = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$data       = is_string( $data_raw ) ? json_decode( $data_raw, true ) : null;

		if ( ! is_array( $data ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid request data.', 'stockdesk-for-woocommerce' ),
				'invalid_request'
			);
		}

		$product = $this->product_service->get_product( $product_id );
		if ( ! $product ) {
			SDFW_Ajax_Response::send_error(
				__( 'Invalid product.', 'stockdesk-for-woocommerce' ),
				'invalid_product'
			);
		}

		$this->apply_row_update( $product, $data, false );
		$saved = $this->product_service->save_product( $product );

		if ( is_wp_error( $saved ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Could not save product changes. Please try again.', 'stockdesk-for-woocommerce' ),
				'save_failed'
			);
		}

		wp_send_json_success(
			array(
				'message' => __( 'Stock row updated successfully.', 'stockdesk-for-woocommerce' ),
			)
		);
	}

	/**
	 * AJAX: bulk update selected products.
	 *
	 * @return void
	 */
	public function ajax_bulk_update(): void {
		$this->verify_ajax_request();

		$ids_raw = isset( $_POST['product_ids'] ) ? wp_unslash( $_POST['product_ids'] ) : '';
		$ids     = is_string( $ids_raw ) ? json_decode( $ids_raw, true ) : null;
		if ( ! is_array( $ids ) ) {
			$ids = array();
		}

		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Select at least one row before applying bulk update.', 'stockdesk-for-woocommerce' ),
				'no_selection'
			);
		}

		$data_raw = isset( $_POST['data'] ) ? wp_unslash( $_POST['data'] ) : '';
		$data     = is_string( $data_raw ) ? json_decode( $data_raw, true ) : null;
		if ( ! is_array( $data ) || empty( $data ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'Change at least one field before saving.', 'stockdesk-for-woocommerce' ),
				'no_changes'
			);
		}

		$updated = 0;
		$skipped = 0;
		$failed  = 0;

		foreach ( $ids as $product_id ) {
			$product = $this->product_service->get_product( $product_id );
			if ( ! $product ) {
				++$skipped;
				continue;
			}

			$this->apply_row_update( $product, $data, true );
			$saved = $this->product_service->save_product( $product );

			if ( is_wp_error( $saved ) ) {
				++$failed;
				continue;
			}

			++$updated;
		}

		$message = sprintf(
			/* translators: %d: number of updated products */
			__( 'Bulk stock update complete. Updated %d items.', 'stockdesk-for-woocommerce' ),
			$updated
		);

		if ( $skipped > 0 || $failed > 0 ) {
			$message = sprintf(
				/* translators: 1: updated count, 2: skipped count, 3: failed count */
				__( 'Bulk update finished. Updated: %1$d. Skipped: %2$d. Failed: %3$d.', 'stockdesk-for-woocommerce' ),
				$updated,
				$skipped,
				$failed
			);
		}

		wp_send_json_success(
			array(
				'updated' => $updated,
				'skipped' => $skipped,
				'failed'  => $failed,
				'message' => $message,
			)
		);
	}

	/**
	 * Apply stock field updates to a product.
	 *
	 * @param WC_Product           $product Product.
	 * @param array<string, mixed> $data    Incoming field data.
	 * @param bool                 $partial Whether only provided keys should be applied.
	 * @return void
	 */
	public function apply_row_update( WC_Product $product, array $data, bool $partial = false ): void {
		$has = static function( string $key ) use ( $data, $partial ): bool {
			if ( ! array_key_exists( $key, $data ) ) {
				return false;
			}
			if ( ! $partial ) {
				return true;
			}
			// Empty string in bulk mode means "mixed / untouched".
			return '' !== $data[ $key ] && null !== $data[ $key ];
		};

		$manage_stock = $product->get_manage_stock();
		if ( $has( 'manageStock' ) ) {
			$manage_stock = 'yes' === sanitize_text_field( (string) $data['manageStock'] );
			$product->set_manage_stock( $manage_stock );
		}

		if ( $has( 'stockStatus' ) ) {
			$stock_status = sanitize_text_field( (string) $data['stockStatus'] );
			if ( in_array( $stock_status, array_keys( wc_get_product_stock_status_options() ), true ) ) {
				$product->set_stock_status( $stock_status );
			}
		}

		if ( $manage_stock && $has( 'backorders' ) ) {
			$backorders = sanitize_text_field( (string) $data['backorders'] );
			if ( in_array( $backorders, array_keys( wc_get_product_backorder_options() ), true ) ) {
				$product->set_backorders( $backorders );
			}
		}

		if ( $manage_stock && $has( 'stockQuantity' ) ) {
			$qty_raw = $data['stockQuantity'];
			if ( '' !== $qty_raw && null !== $qty_raw ) {
				$product->set_stock_quantity( wc_stock_amount( $qty_raw ) );
			}
		}

		if ( $manage_stock && $has( 'lowStockThreshold' ) ) {
			$threshold = trim( (string) $data['lowStockThreshold'] );
			if ( '' === $threshold ) {
				$product->set_low_stock_amount( '' );
			} else {
				$product->set_low_stock_amount( $this->low_stock_resolver->resolve_threshold( $threshold ) );
			}
		}
	}

	/**
	 * Map DataViews view state to repository filters.
	 *
	 * @param array<string, mixed> $view View payload.
	 * @return array<string, mixed>
	 */
	private function filters_from_view( array $view ): array {
		$filters = array(
			'stock_status' => 'all',
			'category'     => 0,
			'brand'        => 0,
			'product_type' => 'all',
			'search'       => isset( $view['search'] ) ? sanitize_text_field( (string) $view['search'] ) : '',
			'paged'        => max( 1, absint( $view['page'] ?? 1 ) ),
		);

		$view_filters = isset( $view['filters'] ) && is_array( $view['filters'] ) ? $view['filters'] : array();
		foreach ( $view_filters as $filter ) {
			if ( ! is_array( $filter ) || empty( $filter['field'] ) ) {
				continue;
			}

			$field = (string) $filter['field'];
			$value = $filter['value'] ?? null;

			if ( 'stockStatus' === $field ) {
				$filters['stock_status'] = sanitize_text_field( (string) $value );
			} elseif ( 'productType' === $field ) {
				$filters['product_type'] = sanitize_text_field( (string) $value );
			} elseif ( 'category' === $field ) {
				$filters['category'] = absint( $value );
			} elseif ( 'brand' === $field ) {
				$filters['brand'] = absint( $value );
			}
		}

		$allowed_status = SDFW_Stock_Options::instance()->get_stock_status_options();
		if ( ! isset( $allowed_status[ $filters['stock_status'] ] ) ) {
			$filters['stock_status'] = 'all';
		}

		$allowed_types = SDFW_Stock_Options::instance()->get_product_type_options();
		if ( ! isset( $allowed_types[ $filters['product_type'] ] ) ) {
			$filters['product_type'] = 'all';
		}

		return $filters;
	}

	/**
	 * Hydrate a repository row into a DataViews item.
	 *
	 * @param array<string, mixed> $row         Repository row.
	 * @param array<string, string> $type_labels Product type labels.
	 * @return array<string, mixed>|null
	 */
	private function hydrate_inventory_item( array $row, array $type_labels ): ?array {
		$product = wc_get_product( (int) ( $row['id'] ?? 0 ) );
		if ( ! $product ) {
			return null;
		}

		$type       = $product->get_type();
		$type_label = $type_labels[ $type ] ?? ucfirst( $type );
		$threshold  = $product->get_low_stock_amount();

		return array(
			'id'                => $product->get_id(),
			'name'              => $product->get_name(),
			'sku'               => (string) $product->get_sku(),
			'type'              => $type,
			'typeLabel'         => $type_label,
			'parentName'        => ! empty( $row['parent_name'] ) ? (string) $row['parent_name'] : '',
			'manageStock'       => $product->get_manage_stock() ? 'yes' : 'no',
			'stockQuantity'     => $product->get_stock_quantity(),
			'lowStockThreshold' => ( null === $threshold || '' === $threshold ) ? null : (int) $threshold,
			'stockStatus'       => $product->get_stock_status(),
			'backorders'        => $product->get_backorders(),
			'categoryId'        => 0,
			'brandId'           => 0,
		);
	}

	/**
	 * Map associative options to DataViews elements.
	 *
	 * @param array<string|int, string> $options Options.
	 * @return array<int, array{value: string|int, label: string}>
	 */
	private function map_elements( array $options ): array {
		$elements = array();
		foreach ( $options as $value => $label ) {
			$elements[] = array(
				'value' => $value,
				'label' => $label,
			);
		}
		return $elements;
	}

	/**
	 * Term options for a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy name.
	 * @return array<int, array{value: int, label: string}>
	 */
	private function get_term_elements( string $taxonomy ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$elements = array();
		foreach ( $terms as $term ) {
			$elements[] = array(
				'value' => (int) $term->term_id,
				'label' => $term->name,
			);
		}

		return $elements;
	}
}
