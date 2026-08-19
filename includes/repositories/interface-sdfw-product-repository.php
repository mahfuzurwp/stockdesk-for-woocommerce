<?php
/**
 * Product repository interface.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

interface SDFW_Product_Repository {

	/**
	 * Get inventory items matching filters.
	 *
	 * @param array $filters Filters.
	 * @param int   $paged   Current page.
	 * @param int   $per_page Per page.
	 * @return array{items: array<int, array<string, mixed>>, total: int, error?: bool}
	 */
	public function get_inventory_items( array $filters, int $paged, int $per_page ): array;

	/**
	 * Get products for export matching filters.
	 *
	 * @param array $filters Filters.
	 * @param int   $limit   Limit (-1 for all).
	 * @param int   $offset  Offset.
	 * @return array<int, array<string, mixed>>
	 */
	public function get_products_for_export( array $filters, int $limit = -1, int $offset = 0 ): array;

	/**
	 * Count products matching export filters.
	 *
	 * @param array $filters Filters.
	 * @return int
	 */
	public function count_products_for_export( array $filters ): int;

	/**
	 * Find product ID by SKU.
	 *
	 * @param string $sku SKU.
	 * @return int|null Product ID or null if not found.
	 */
	public function find_product_id_by_sku( string $sku ): ?int;

	/**
	 * Whether the most recent query failed.
	 *
	 * @return bool
	 */
	public function had_query_error(): bool;
}
