<?php
/**
 * Export query builder class.
 *
 * @package StockDesk for WooCommerce
 *
 * @deprecated Use SDFW_WPDB_Product_Repository instead.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDFW_Export_Query {

	/**
	 * Filters.
	 *
	 * @var array
	 */
	private $filters = array();

	/**
	 * Set filters.
	 *
	 * @param array $filters Filters.
	 * @return void
	 */
	public function set_filters( array $filters ): void {
		$this->filters = $filters;
	}

	/**
	 * Get matching product count.
	 *
	 * @param array $filters Filters (optional).
	 * @return int
	 */
	public function get_count( array $filters = array() ): int {
		if ( ! empty( $filters ) ) {
			$this->set_filters( $filters );
		}

		global $wpdb;

		$sql = $this->build_count_sql();

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get products for export.
	 *
	 * @param array $filters Filters (optional).
	 * @param int   $limit   Limit (-1 for all).
	 * @param int   $offset  Offset.
	 * @return array
	 */
	public function get_products( array $filters = array(), int $limit = -1, int $offset = 0 ): array {
		if ( ! empty( $filters ) ) {
			$this->set_filters( $filters );
		}

		global $wpdb;

		$sql = $this->build_select_sql( $limit, $offset );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$results = $wpdb->get_results( $sql, ARRAY_A );

		if ( ! is_array( $results ) ) {
			return array();
		}

		return $this->enrich_product_data( $results );
	}

	/**
	 * Build count SQL.
	 *
	 * @return string
	 */
	private function build_count_sql(): string {
		global $wpdb;

		$joins  = $this->build_joins();
		$where  = $this->build_where();

		return "
			SELECT COUNT(DISTINCT p.ID)
			FROM {$wpdb->posts} p
			{$joins}
			WHERE {$where}
		";
	}

	/**
	 * Build select SQL.
	 *
	 * @param int $limit  Limit.
	 * @param int $offset Offset.
	 * @return string
	 */
	private function build_select_sql( int $limit, int $offset ): string {
		global $wpdb;

		$joins  = $this->build_joins();
		$where  = $this->build_where();
		$limit_sql = '';

		if ( $limit > 0 ) {
			$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $limit, $offset );
		}

		return "
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
			FROM {$wpdb->posts} p
			{$joins}
			WHERE {$where}
			ORDER BY p.post_title ASC
			{$limit_sql}
		";
	}

	/**
	 * Build SQL joins.
	 *
	 * @return string
	 */
	private function build_joins(): string {
		global $wpdb;

		$joins = array(
			"LEFT JOIN {$wpdb->posts} parent ON parent.ID = p.post_parent",
			"LEFT JOIN {$wpdb->postmeta} pm_sku ON pm_sku.post_id = p.ID AND pm_sku.meta_key = '_sku'",
			"LEFT JOIN {$wpdb->postmeta} pm_manage ON pm_manage.post_id = p.ID AND pm_manage.meta_key = '_manage_stock'",
			"LEFT JOIN {$wpdb->postmeta} pm_stock ON pm_stock.post_id = p.ID AND pm_stock.meta_key = '_stock'",
			"LEFT JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_stock_status'",
			"LEFT JOIN {$wpdb->postmeta} pm_backorders ON pm_backorders.post_id = p.ID AND pm_backorders.meta_key = '_backorders'",
			"LEFT JOIN {$wpdb->postmeta} pm_threshold ON pm_threshold.post_id = p.ID AND pm_threshold.meta_key = '_low_stock_amount'",
		);

		if ( ! empty( $this->filters['category'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$joins[]    = "LEFT JOIN {$wpdb->term_relationships} tr_cat ON tr_cat.object_id = {$owner_expr}";
			$joins[]    = "LEFT JOIN {$wpdb->term_taxonomy} tt_cat ON tt_cat.term_taxonomy_id = tr_cat.term_taxonomy_id AND tt_cat.taxonomy = 'product_cat'";
		}

		if ( ! empty( $this->filters['brand'] ) && is_array( $this->filters['brand'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$brand_tax  = $this->get_brand_taxonomy();
			if ( $brand_tax ) {
				$joins[] = "LEFT JOIN {$wpdb->term_relationships} tr_brand ON tr_brand.object_id = {$owner_expr}";
				$joins[] = $wpdb->prepare( "LEFT JOIN {$wpdb->term_taxonomy} tt_brand ON tt_brand.term_taxonomy_id = tr_brand.term_taxonomy_id AND tt_brand.taxonomy = %s", $brand_tax );
			}
		}

		if ( ! empty( $this->filters['tags'] ) ) {
			$owner_expr = 'IF(p.post_parent > 0, p.post_parent, p.ID)';
			$joins[]    = "LEFT JOIN {$wpdb->term_relationships} tr_tag ON tr_tag.object_id = {$owner_expr}";
			$joins[]    = "LEFT JOIN {$wpdb->term_taxonomy} tt_tag ON tt_tag.term_taxonomy_id = tr_tag.term_taxonomy_id AND tt_tag.taxonomy = 'product_tag'";
		}

		return implode( "\n", $joins );
	}

	/**
	 * Build WHERE clause.
	 *
	 * @return string
	 */
	private function build_where(): string {
		global $wpdb;

		$where = array(
			"p.post_type IN ('product', 'product_variation')",
		);

		$product_statuses = $this->filters['product_status'] ?? array( 'publish' );
		if ( ! empty( $product_statuses ) && is_array( $product_statuses ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $product_statuses ), '%s' ) );
			$where[]      = $wpdb->prepare( "p.post_status IN ({$placeholders})", ...$product_statuses );
		}

		if ( ! empty( $this->filters['product_type'] ) && 'all' !== $this->filters['product_type'] ) {
			if ( 'simple' === $this->filters['product_type'] ) {
				$where[] = "p.post_type = 'product'";
			} elseif ( 'variable' === $this->filters['product_type'] ) {
				$where[] = "p.post_type = 'product' AND p.ID IN (SELECT DISTINCT post_parent FROM {$wpdb->posts} WHERE post_type = 'product_variation')";
			}
		}

		if ( ! empty( $this->filters['category'] ) && is_array( $this->filters['category'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $this->filters['category'] ), '%d' ) );
			$where[]      = $wpdb->prepare( "tt_cat.term_id IN ({$placeholders})", ...$this->filters['category'] );
		}

		if ( ! empty( $this->filters['brand'] ) && is_array( $this->filters['brand'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $this->filters['brand'] ), '%d' ) );
			$where[]      = $wpdb->prepare( "tt_brand.term_id IN ({$placeholders})", ...$this->filters['brand'] );
		}

		if ( ! empty( $this->filters['tags'] ) && is_array( $this->filters['tags'] ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $this->filters['tags'] ), '%d' ) );
			$where[]      = $wpdb->prepare( "tt_tag.term_id IN ({$placeholders})", ...$this->filters['tags'] );
		}

		if ( ! empty( $this->filters['stock_status'] ) && 'all' !== $this->filters['stock_status'] ) {
			$where[] = $wpdb->prepare( "pm_status.meta_value = %s", $this->filters['stock_status'] );
		}

		if ( ! empty( $this->filters['manage_stock'] ) && 'yes' === $this->filters['manage_stock'] ) {
			$where[] = "pm_manage.meta_value = 'yes'";
		}

		if ( isset( $this->filters['min_stock'] ) && null !== $this->filters['min_stock'] ) {
			$where[] = $wpdb->prepare( 'pm_stock.meta_value >= %d', $this->filters['min_stock'] );
		}

		if ( isset( $this->filters['max_stock'] ) && null !== $this->filters['max_stock'] ) {
			$where[] = $wpdb->prepare( 'pm_stock.meta_value <= %d', $this->filters['max_stock'] );
		}

		if ( ! empty( $this->filters['sku_list'] ) ) {
			$skus = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $this->filters['sku_list'] ) ) );
			if ( ! empty( $skus ) ) {
				$placeholders = implode( ', ', array_fill( 0, count( $skus ), '%s' ) );
				$where[]      = $wpdb->prepare( "pm_sku.meta_value IN ({$placeholders})", ...$skus );
			}
		}

		return implode( ' AND ', $where );
	}

	/**
	 * Enrich product data with additional information.
	 *
	 * @param array $products Raw product data from SQL.
	 * @return array
	 */
	private function enrich_product_data( array $products ): array {
		$parent_ids      = array();
		$owner_ids       = array();
		$product_type_map = $this->get_product_type_map( $products );
		$brand_taxonomy  = $this->get_brand_taxonomy();

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
		$brand_map     = $brand_taxonomy ? $this->get_term_name_map( $owner_ids, $brand_taxonomy, false ) : array();

		foreach ( $products as &$product ) {
			$product_id = (int) $product['product_id'];
			$parent_id  = (int) $product['parent_id'];
			$owner_id   = $parent_id > 0 ? $parent_id : $product_id;

			$product['parent_name']  = $parent_id > 0 ? ( $parent_titles[ $parent_id ] ?? '' ) : '';
			$product['category']     = $category_map[ $owner_id ] ?? '';
			$product['tags']         = $tag_map[ $owner_id ] ?? '';
			$product['brand']        = $brand_map[ $owner_id ] ?? '';
			$product['product_type'] = $product_type_map[ $product_id ] ?? '';
		}
		unset( $product );

		return $products;
	}

	/**
	 * Get a map of post IDs to titles.
	 *
	 * @param array $post_ids Post IDs.
	 * @return array
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
	 * Get mapped term names for object IDs.
	 *
	 * @param array  $object_ids Object IDs.
	 * @param string $taxonomy Taxonomy.
	 * @param bool   $join_all Whether to join all terms.
	 * @return array
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
	 * Get WooCommerce product types for exported rows.
	 *
	 * @param array $products Product rows.
	 * @return array
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
	 * Get brand taxonomy if available.
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
}
