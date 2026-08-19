<?php
/**
 * WPDB-backed product repository.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_WPDB_Product_Repository implements SDFW_Product_Repository {

	/**
	 * WordPress database instance.
	 *
	 * @var wpdb
	 */
	private $db;

	/**
	 * Brand taxonomy resolver.
	 *
	 * @var SDFW_Brand_Taxonomy_Resolver
	 */
	private $brand_resolver;

	/**
	 * Constructor.
	 *
	 * @param wpdb                         $db Database instance.
	 * @param SDFW_Brand_Taxonomy_Resolver $brand_resolver Brand taxonomy resolver.
	 */
	public function __construct( wpdb $db, SDFW_Brand_Taxonomy_Resolver $brand_resolver ) {
		$this->db            = $db;
		$this->brand_resolver = $brand_resolver;
	}

	/**
	 * Whether the last query failed.
	 *
	 * @var bool
	 */
	private $query_error = false;

	/**
	 * {@inheritdoc}
	 */
	public function get_inventory_items( array $filters, int $paged, int $per_page ): array {
		$this->query_error = false;
		$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
		$joins      = array(
			" LEFT JOIN {$this->db->posts} parent ON parent.ID = p.post_parent ",
			" LEFT JOIN {$this->db->postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku' ",
			" LEFT JOIN {$this->db->term_relationships} tr_cat ON tr_cat.object_id = {$owner_expr} ",
			" LEFT JOIN {$this->db->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'product_cat' ",
			" LEFT JOIN {$this->db->term_relationships} tr_type ON tr_type.object_id = {$owner_expr} ",
			" LEFT JOIN {$this->db->term_taxonomy} tt_type ON tt_type.term_taxonomy_id = tr_type.term_taxonomy_id AND tt_type.taxonomy = 'product_type' ",
			" LEFT JOIN {$this->db->terms} t_type ON t_type.term_id = tt_type.term_id ",
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

		$brand_taxonomy = $this->brand_resolver->resolve();
		if ( $brand_taxonomy && ! empty( $filters['brand'] ) ) {
			$joins[]  = " LEFT JOIN {$this->db->term_relationships} tr_brand ON tr_brand.object_id = {$owner_expr} ";
			$joins[]  = $this->db->prepare( " LEFT JOIN {$this->db->term_taxonomy} tt_brand ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id AND tt_brand.taxonomy = %s ", $brand_taxonomy );
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
			$like      = '%' . $this->db->esc_like( $filters['search'] ) . '%';
			$where[]   = '(p.post_title LIKE %s OR pm_sku.meta_value LIKE %s OR parent.post_title LIKE %s)';
			$params[]  = $like;
			$params[]  = $like;
			$params[]  = $like;
		}

		$sql_base = "
			FROM {$this->db->posts} p
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
			$list_sql = $this->db->prepare( $list_sql, $params );
		}

		if ( 'all' !== $filters['stock_status'] ) {
			$items = $this->db->get_results( $list_sql, ARRAY_A );
			if ( $this->mark_query_error() ) {
				return array(
					'items' => array(),
					'total' => 0,
					'error' => true,
				);
			}
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
			$total_sql = $this->db->prepare( $total_sql, $params );
		}
		$total = (int) $this->db->get_var( $total_sql );
		if ( $this->mark_query_error() ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => true,
			);
		}

		if ( $per_page > 0 ) {
			$offset   = ( $paged - 1 ) * $per_page;
			$list_sql .= $this->db->prepare( ' LIMIT %d OFFSET %d ', $per_page, $offset );
		}

		$items = $this->db->get_results( $list_sql, ARRAY_A );
		if ( $this->mark_query_error() ) {
			return array(
				'items' => array(),
				'total' => 0,
				'error' => true,
			);
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_products_for_export( array $filters, int $limit = -1, int $offset = 0 ): array {
		$this->query_error = false;
		$joins  = $this->build_export_joins( $filters );
		$where  = $this->build_export_where( $filters );

		$sql = "
			SELECT DISTINCT
				p.ID as product_id,
				p.post_title as product_name,
				p.post_type as product_type,
				p.post_parent as parent_id,
				pm_sku.meta_value as sku,
				pm_manage.meta_value as manage_stock,
				pm_stock.meta_value as stock_quantity,
				pm_status.meta_value as stock_status,
				pm_backorders.meta_value as backorders,
				pm_threshold.meta_value as low_stock_threshold
			FROM {$this->db->posts} p
			{$joins}
			WHERE {$where}
			ORDER BY p.post_title ASC
		";

		$limit_sql = '';
		if ( $limit > 0 ) {
			$limit_sql = $this->db->prepare( 'LIMIT %d OFFSET %d', $limit, $offset );
		}

		$sql .= $limit_sql;

		$results = $this->db->get_results( $sql, ARRAY_A );
		if ( $this->mark_query_error() ) {
			return array();
		}

		if ( ! is_array( $results ) ) {
			return array();
		}

		return $this->enrich_export_data( $results, $filters );
	}

	/**
	 * {@inheritdoc}
	 */
	public function count_products_for_export( array $filters ): int {
		$this->query_error = false;
		$joins = $this->build_export_joins( $filters );
		$where = $this->build_export_where( $filters );

		$sql = "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$this->db->posts} p
			{$joins}
			WHERE {$where}
		";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$count = (int) $this->db->get_var( $sql );
		if ( $this->mark_query_error() ) {
			return 0;
		}

		return $count;
	}

	/**
	 * {@inheritdoc}
	 */
	public function find_product_id_by_sku( string $sku ): ?int {
		$product_id = $this->db->get_var(
			$this->db->prepare(
				"SELECT post_id FROM {$this->db->postmeta} WHERE meta_key = '_sku' AND meta_value = %s LIMIT 1",
				$sku
			)
		);

		return $product_id ? (int) $product_id : null;
	}

	public function had_query_error(): bool {
		return $this->query_error;
	}

	/**
	 * Detect and log database query errors.
	 *
	 * @return bool True when a query error was detected.
	 */
	private function mark_query_error(): bool {
		if ( '' === $this->db->last_error ) {
			return false;
		}

		$this->query_error = true;

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->error(
				$this->db->last_error,
				array(
					'source' => 'stockdesk-for-woocommerce',
				)
			);
		}

		return true;
	}

	/**
	 * Build export SQL joins.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	private function build_export_joins( array $filters ): string {
		$joins = array(
			"LEFT JOIN {$this->db->posts} parent ON parent.ID = p.post_parent",
			"LEFT JOIN {$this->db->postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'",
			"LEFT JOIN {$this->db->postmeta} pm_manage ON pm_manage.post_id = p.ID AND pm_manage.meta_key = '_manage_stock'",
			"LEFT JOIN {$this->db->postmeta} pm_stock ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock'",
			"LEFT JOIN {$this->db->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_stock_status'",
			"LEFT JOIN {$this->db->postmeta} pm_backorders ON pm_backorders.post_id = p.ID AND pm_backorders.meta_key = '_backorders'",
			"LEFT JOIN {$this->db->postmeta} pm_threshold ON pm_threshold.post_id = p.ID AND pm_threshold.meta_key = '_low_stock_amount'",
		);

		if ( ! empty( $filters['category'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$joins[]    = "LEFT JOIN {$this->db->term_relationships} tr_cat ON tr_cat.object_id = {$owner_expr}";
			$joins[]    = "LEFT JOIN {$this->db->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'product_cat'";
		}

		if ( ! empty( $filters['brand'] ) && is_array( $filters['brand'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$brand_tax  = $this->brand_resolver->resolve();
			if ( $brand_tax ) {
				$joins[] = "LEFT JOIN {$this->db->term_relationships} tr_brand ON tr_brand.object_id = {$owner_expr}";
				$joins[] = $this->db->prepare( "LEFT JOIN {$this->db->term_taxonomy} tt_brand ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id AND tt_brand.taxonomy = %s", $brand_tax );
			}
		}

		if ( ! empty( $filters['tags'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$joins[]    = "LEFT JOIN {$this->db->term_relationships} tr_tag ON tr_tag.object_id = {$owner_expr}";
			$joins[]    = "LEFT JOIN {$this->db->term_taxonomy} tt_tag ON tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id AND tt_tag.taxonomy = 'product_tag'";
		}

		return implode( "\n", $joins );
	}

	/**
	 * Build export WHERE clause.
	 *
	 * @param array $filters Filters.
	 * @return string
	 */
	private function build_export_where( array $filters ): string {
		$where = array(
			"p.post_type IN ('product', 'product_variation')",
		);

		$product_statuses = $filters['product_status'] ?? array( 'publish' );
		if ( ! empty( $product_statuses ) && is_array( $product_statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $product_statuses ), '%s' ) );
			$where[]      = $this->db->prepare( "p.post_status IN ({$placeholders})", ...$product_statuses );
		}

		if ( ! empty( $filters['product_type'] ) && 'all' !== $filters['product_type'] ) {
			if ( 'simple' === $filters['product_type'] ) {
				$where[] = "p.post_type = 'product'";
			} elseif ( 'variable' === $filters['product_type'] ) {
				$where[] = "p.post_type = 'product' AND p.ID IN (SELECT DISTINCT post_parent FROM {$this->db->posts} WHERE post_type = 'product_variation')";
			}
		}

		if ( ! empty( $filters['category'] ) && is_array( $filters['category'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['category'] ), '%d' ) );
			$where[]      = $this->db->prepare( "tt_cat.term_id IN ({$placeholders})", ...$filters['category'] );
		}

		if ( ! empty( $filters['brand'] ) && is_array( $filters['brand'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['brand'] ), '%d' ) );
			$where[]      = $this->db->prepare( "tt_brand.term_id IN ({$placeholders})", ...$filters['brand'] );
		}

		if ( ! empty( $filters['tags'] ) && is_array( $filters['tags'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $filters['tags'] ), '%d' ) );
			$where[]      = $this->db->prepare( "tt_tag.term_id IN ({$placeholders})", ...$filters['tags'] );
		}

		if ( ! empty( $filters['stock_status'] ) && 'all' !== $filters['stock_status'] ) {
			$where[] = $this->db->prepare( "pm_status.meta_value = %s", $filters['stock_status'] );
		}

		if ( ! empty( $filters['manage_stock'] ) && 'yes' === $filters['manage_stock'] ) {
			$where[] = "pm_manage.meta_value = 'yes'";
		}

		if ( isset( $filters['min_stock'] ) && null !== $filters['min_stock'] ) {
			$where[] = $this->db->prepare( 'pm_stock.meta_value >= %d', $filters['min_stock'] );
		}

		if ( isset( $filters['max_stock'] ) && null !== $filters['max_stock'] ) {
			$where[] = $this->db->prepare( 'pm_stock.meta_value <= %d', $filters['max_stock'] );
		}

		if ( ! empty( $filters['sku_list'] ) ) {
			$skus = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $filters['sku_list'] ) ) );
			if ( ! empty( $skus ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $skus ), '%s' ) );
				$where[]      = $this->db->prepare( "pm_sku.meta_value IN ({$placeholders})", ...$skus );
			}
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Enrich export product data.
	 *
	 * @param array $products Raw products.
	 * @param array $filters  Filters.
	 * @return array
	 */
	private function enrich_export_data( array $products, array $filters ): array {
		$parent_ids      = array();
		$owner_ids       = array();

		foreach ( $products as $product ) {
			$product_id = (int) $product['product_id'];
			$parent_id  = (int) $product['parent_id'];
			$owner_ids[] = $parent_id > 0 ? $parent_id : $product_id;

			if ( $parent_id > 0 ) {
				$parent_ids[] = $parent_id;
			}
		}

		$parent_titles = $this->get_post_title_map( $parent_ids );
		$category_map  = $this->get_term_name_map( $owner_ids, 'product_cat', false );
		$tag_map       = $this->get_term_name_map( $owner_ids, 'product_tag', true );
		$brand_map     = $this->get_term_name_map( $owner_ids, $this->brand_resolver->resolve(), false );
		$type_map      = $this->get_product_type_map( $products );

		foreach ( $products as &$product ) {
			$product_id = (int) $product['product_id'];
			$parent_id  = (int) $product['parent_id'];
			$owner_id   = $parent_id > 0 ? $parent_id : $product_id;

			$product['parent_name']  = $parent_id > 0 ? ( $parent_titles[ $parent_id ] ?? '' ) : '';
			$product['category']     = $category_map[ $owner_id ] ?? '';
			$product['tags']         = $tag_map[ $owner_id ] ?? '';
			$product['brand']        = $brand_map[ $owner_id ] ?? '';
			$product['product_type'] = $type_map[ $product_id ] ?? '';
		}
		unset( $product );

		return $products;
	}

	/**
	 * Get post title map.
	 *
	 * @param array $post_ids Post IDs.
	 * @return array<int, string>
	 */
	private function get_post_title_map( array $post_ids ): array {
		$post_ids = array_values( array_unique( array_filter( array_map( 'absint', $post_ids ) ) ) );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$posts = get_posts(
			array(
				'post_type'              => 'any',
				'post_status'            => 'any',
				'post__in'               => $post_ids,
				'posts_per_page'         => count( $post_ids ),
				'orderby'                => 'post__in',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);

		$title_map = array();
		foreach ( $posts as $post ) {
			$title_map[ $post->ID ] = $post->post_title;
		}

		return $title_map;
	}

	/**
	 * Get term name map.
	 *
	 * @param array  $object_ids Object IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $join_all   Whether to join all terms.
	 * @return array<int, string>
	 */
	private function get_term_name_map( array $object_ids, string $taxonomy, bool $join_all ): array {
		$object_ids = array_values( array_unique( array_filter( array_map( 'absint', $object_ids ) ) ) );
		if ( empty( $object_ids ) ) {
			return array();
		}

		$terms = wp_get_object_terms(
			$object_ids,
			$taxonomy,
			array(
				'fields' => 'all_with_object_id',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return array();
		}

		$grouped_terms = array();
		foreach ( $terms as $term ) {
			$object_id = (int) $term->object_id;
			if ( ! isset( $grouped_terms[ $object_id ] ) ) {
				$grouped_terms[ $object_id ] = array();
			}

			$grouped_terms[ $object_id ][] = $term->name;
		}

		$mapped_terms = array();
		foreach ( $grouped_terms as $object_id => $names ) {
			$mapped_terms[ $object_id ] = $join_all ? implode( ', ', $names ) : reset( $names );
		}

		return $mapped_terms;
	}

	/**
	 * Get product type map.
	 *
	 * @param array $products Products.
	 * @return array<int, string>
	 */
	private function get_product_type_map( array $products ): array {
		$product_type_map = array();
		$product_ids      = array();

		foreach ( $products as $product ) {
			$product_id = (int) $product['product_id'];
			if ( 'product_variation' === $product['product_type'] ) {
				$product_type_map[ $product_id ] = 'variation';
				continue;
			}

			$product_ids[] = $product_id;
		}

		$product_ids = array_values( array_unique( $product_ids ) );
		if ( empty( $product_ids ) ) {
			return $product_type_map;
		}

		$terms = wp_get_object_terms(
			$product_ids,
			'product_type',
			array(
				'fields' => 'all_with_object_id',
			)
		);

		if ( is_wp_error( $terms ) || ! is_array( $terms ) ) {
			return $product_type_map;
		}

		foreach ( $terms as $term ) {
			$product_type_map[ (int) $term->object_id ] = $term->slug;
		}

		return $product_type_map;
	}

	/**
	 * Determine if product passes stock filter.
	 *
	 * @param int     $product_id   Product ID.
	 * @param string  $stock_filter Stock filter.
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
		$resolver  = SDFW_Low_Stock_Resolver::instance();
		$used_limit = null !== $threshold && '' !== (string) $threshold ? (int) $threshold : $resolver->get_global_threshold();

		return 'yes' === $manage && null !== $qty && $qty > 0 && $qty <= $used_limit;
	}
}
