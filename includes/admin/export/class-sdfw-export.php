<?php
/**
 * Export handler class.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Export {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Export|null
	 */
	private static $instance = null;

	/**
	 * Product repository.
	 *
	 * @var SDFW_Product_Repository|null
	 */
	private $product_repository = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Export
	 */
	public static function instance(): SDFW_Export {
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
		add_action( 'wp_ajax_sdfw_export_csv', array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_sdfw_export_preview', array( $this, 'ajax_export_preview' ) );
	}

	/**
	 * Client bootstrap data for the SPA.
	 *
	 * @return array<string, mixed>
	 */
	public function get_client_data(): array {
		return array(
			'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
			'nonce'           => wp_create_nonce( 'sdfw_export_action' ),
			'productStatuses' => $this->get_product_status_options_for_js(),
			'taxonomies'      => array(
				'categories' => $this->get_category_options_for_js(),
				'brands'     => $this->get_brand_options_for_js(),
				'tags'       => $this->get_tag_options_for_js(),
			),
		);
	}

	/**
	 * Verify AJAX nonce and capability.
	 *
	 * @return void
	 */
	private function verify_ajax_request(): void {
		if ( ! check_ajax_referer( 'sdfw_export_action', 'nonce', false ) ) {
			SDFW_Ajax_Response::send_invalid_nonce();
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			SDFW_Ajax_Response::send_forbidden();
		}
	}

	/**
	 * AJAX: export products to CSV.
	 *
	 * @return void
	 */
	public function ajax_export_csv(): void {
		$this->verify_ajax_request();

		$parsed_request = $this->parse_filters_from_request();
		if ( empty( $parsed_request['valid'] ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'The export request is invalid. Please reload the page and try again.', 'stockdesk-for-woocommerce' ),
				'invalid_request'
			);
		}

		$filters  = $parsed_request['filters'];
		$products = $this->get_product_repository()->get_products_for_export( $filters, -1, 0 );

		if ( $this->get_product_repository()->had_query_error() ) {
			SDFW_Ajax_Response::send_error(
				__( 'Could not load products for export.', 'stockdesk-for-woocommerce' ),
				'query_failed'
			);
		}

		if ( empty( $products ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'No products matched your filters.', 'stockdesk-for-woocommerce' ),
				'no_results'
			);
		}

		$formatter        = new SDFW_Export_Formatter();
		$include_optional = ! empty( $filters['include_optional_columns'] );
		$csv_delimiter    = $filters['csv_delimiter'] ?? ',';
		$content          = $formatter->to_csv( $products, $include_optional, $csv_delimiter );
		$filename         = 'stockdesk-export-' . gmdate( 'Y-m-d' ) . '.csv';

		wp_send_json_success(
			array(
				'filename' => $filename,
				'content'  => base64_encode( $content ),
			)
		);
	}

	/**
	 * AJAX: preview export results before download.
	 *
	 * @return void
	 */
	public function ajax_export_preview(): void {
		$this->verify_ajax_request();

		$parsed_request = $this->parse_filters_from_request();
		if ( empty( $parsed_request['valid'] ) ) {
			SDFW_Ajax_Response::send_error(
				__( 'The export request is invalid. Please reload the page and try again.', 'stockdesk-for-woocommerce' ),
				'invalid_request'
			);
		}

		$filters          = $parsed_request['filters'];
		$repository       = $this->get_product_repository();
		$include_optional = ! empty( $filters['include_optional_columns'] );
		$total_rows       = $repository->count_products_for_export( $filters );

		if ( $repository->had_query_error() ) {
			SDFW_Ajax_Response::send_error(
				__( 'Could not load products for export.', 'stockdesk-for-woocommerce' ),
				'query_failed'
			);
		}

		if ( $total_rows <= 0 ) {
			SDFW_Ajax_Response::send_error(
				__( 'No products matched your filters.', 'stockdesk-for-woocommerce' ),
				'no_results'
			);
		}

		$products = $repository->get_products_for_export( $filters, 5, 0 );

		if ( $repository->had_query_error() ) {
			SDFW_Ajax_Response::send_error(
				__( 'Could not load products for export.', 'stockdesk-for-woocommerce' ),
				'query_failed'
			);
		}

		$formatter = new SDFW_Export_Formatter();
		$columns   = $formatter->get_preview_columns( $include_optional );
		$column_list = array();

		foreach ( $columns as $key => $label ) {
			$column_list[] = array(
				'key'   => $key,
				'label' => $label,
			);
		}

		wp_send_json_success(
			array(
				'total_rows' => $total_rows,
				'preview'    => $formatter->format_preview_rows( $products, $include_optional ),
				'columns'    => $column_list,
			)
		);
	}

	/**
	 * Get product repository.
	 *
	 * @return SDFW_Product_Repository
	 */
	private function get_product_repository(): SDFW_Product_Repository {
		if ( null === $this->product_repository ) {
			$this->product_repository = SDFW_Container::instance()->get( SDFW_Product_Repository::class );
		}

		return $this->product_repository;
	}

	/**
	 * Get categories for JS.
	 *
	 * @return array
	 */
	private function get_category_options_for_js(): array {
		$categories = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);

		return $this->map_terms_for_js( $categories );
	}

	/**
	 * Get brands for JS.
	 *
	 * @return array
	 */
	private function get_brand_options_for_js(): array {
		$brand_resolver = SDFW_Brand_Taxonomy_Resolver::instance();
		$brand_tax      = $brand_resolver->resolve();

		if ( ! $brand_tax ) {
			return array();
		}

		$brands = get_terms(
			array(
				'taxonomy'   => $brand_tax,
				'hide_empty' => false,
			)
		);

		return $this->map_terms_for_js( $brands );
	}

	/**
	 * Get tags for JS.
	 *
	 * @return array
	 */
	private function get_tag_options_for_js(): array {
		$tags = get_terms(
			array(
				'taxonomy'   => 'product_tag',
				'hide_empty' => false,
			)
		);

		return $this->map_terms_for_js( $tags );
	}

	/**
	 * Get product status options for JS.
	 *
	 * @return array
	 */
	private function get_product_status_options_for_js(): array {
		$product_statuses = array(
			'publish',
			'private',
			'draft',
			'pending',
		);
		$options          = array();
		$status_objects   = get_post_stati( array(), 'objects' );

		foreach ( $product_statuses as $status_key ) {
			if ( ! isset( $status_objects[ $status_key ] ) ) {
				continue;
			}

			$options[] = array(
				'key'  => $status_key,
				'name' => $status_objects[ $status_key ]->label,
			);
		}

		return $options;
	}

	/**
	 * Convert terms to JS-friendly option objects.
	 *
	 * @param mixed $terms Term list or WP_Error.
	 * @return array
	 */
	private function map_terms_for_js( $terms ): array {
		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		return array_map(
			function ( WP_Term $term ) {
				return array(
					'id'   => $term->term_id,
					'name' => $term->name,
				);
			},
			$terms
		);
	}

	/**
	 * Parse filters from request.
	 *
	 * @param array $source Optional source array (defaults to $_POST).
	 * @return array
	 */
	private function parse_filters_from_request( array $source = array() ): array {
		if ( empty( $source ) ) {
			$source = $_POST;
		}

		if ( ! isset( $source['filters'] ) ) {
			return array(
				'valid'   => false,
				'filters' => array(),
			);
		}

		if ( is_array( $source['filters'] ) ) {
			return array(
				'valid'   => true,
				'filters' => $this->sanitize_filters( wp_unslash( $source['filters'] ) ),
			);
		}

		$raw = wp_unslash( $source['filters'] );
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			if ( is_array( $decoded ) ) {
				return array(
					'valid'   => true,
					'filters' => $this->sanitize_filters( $decoded ),
				);
			}
		}

		return array(
			'valid'   => false,
			'filters' => array(),
		);
	}

	/**
	 * Sanitize filter values.
	 *
	 * @param array $raw Raw filter data.
	 * @return array
	 */
	private function sanitize_filters( array $raw ): array {
		$filters = array();

		$filters['product_type'] = isset( $raw['product_type'] ) ? sanitize_text_field( $raw['product_type'] ) : 'all';

		if ( isset( $raw['category'] ) && is_array( $raw['category'] ) ) {
			$filters['category'] = array_map( 'intval', array_filter( $raw['category'] ) );
		} else {
			$filters['category'] = array();
		}

		if ( isset( $raw['brand'] ) && is_array( $raw['brand'] ) ) {
			$filters['brand'] = array_map( 'intval', array_filter( $raw['brand'] ) );
		} else {
			$filters['brand'] = array();
		}

		if ( isset( $raw['tags'] ) && is_array( $raw['tags'] ) ) {
			$filters['tags'] = array_map( 'intval', array_filter( $raw['tags'] ) );
		} else {
			$filters['tags'] = array();
		}

		if ( isset( $raw['product_status'] ) && is_array( $raw['product_status'] ) ) {
			$allowed_statuses          = $this->get_allowed_product_statuses();
			$filters['product_status'] = array_values(
				array_filter(
					array_map( 'sanitize_key', $raw['product_status'] ),
					function ( string $status ) use ( $allowed_statuses ): bool {
						return in_array( $status, $allowed_statuses, true );
					}
				)
			);
		} else {
			$filters['product_status'] = array( 'publish' );
		}

		$filters['stock_status'] = isset( $raw['stock_status'] ) ? sanitize_text_field( $raw['stock_status'] ) : 'all';

		$manage_stock_raw = $raw['manage_stock'] ?? null;
		$filters['manage_stock'] = in_array( $manage_stock_raw, array( true, 'true', '1', 1, 'yes' ), true ) ? 'yes' : 'no';

		$filters['min_stock'] = isset( $raw['min_stock'] ) && '' !== $raw['min_stock'] ? absint( $raw['min_stock'] ) : null;
		$filters['max_stock'] = isset( $raw['max_stock'] ) && '' !== $raw['max_stock'] ? absint( $raw['max_stock'] ) : null;
		$filters['sku_list']  = isset( $raw['sku_list'] ) ? sanitize_textarea_field( $raw['sku_list'] ) : '';

		if ( '' !== trim( $filters['sku_list'] ) ) {
			$filters['product_type']    = 'all';
			$filters['category']        = array();
			$filters['brand']           = array();
			$filters['tags']            = array();
			$filters['product_status']  = array();
			$filters['stock_status']    = 'all';
			$filters['manage_stock']    = 'no';
			$filters['min_stock']       = null;
			$filters['max_stock']       = null;
		}

		$include_optional_raw = $raw['include_optional_columns'] ?? null;
		$filters['include_optional_columns'] = in_array( $include_optional_raw, array( true, 'true', '1', 1, 'yes' ), true );

		$filters['csv_delimiter'] = isset( $raw['csv_delimiter'] ) && ';' === $raw['csv_delimiter'] ? ';' : ',';

		return $filters;
	}

	/**
	 * Get supported product statuses for export filtering.
	 *
	 * @return array
	 */
	private function get_allowed_product_statuses(): array {
		return array(
			'publish',
			'private',
			'draft',
			'pending',
		);
	}
}
