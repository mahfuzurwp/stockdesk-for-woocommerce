<?php
/**
 * Inventory request DTO.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Inventory_Request {

	/**
	 * Stock status filter.
	 *
	 * @var string
	 */
	public $stock_status;

	/**
	 * Category ID.
	 *
	 * @var int
	 */
	public $category;

	/**
	 * Brand ID.
	 *
	 * @var int
	 */
	public $brand;

	/**
	 * Product type filter.
	 *
	 * @var string
	 */
	public $product_type;

	/**
	 * Search query.
	 *
	 * @var string
	 */
	public $search;

	/**
	 * Current page.
	 *
	 * @var int
	 */
	public $paged;

	/**
	 * Constructor.
	 *
	 * @param array $data Raw data.
	 */
	public function __construct( array $data = array() ) {
		$stock_status = isset( $data['stock_status'] ) ? sanitize_text_field( (string) $data['stock_status'] ) : 'all';
		$allowed      = SDFW_Stock_Options::instance()->get_stock_status_options();
		if ( ! isset( $allowed[ $stock_status ] ) ) {
			$stock_status = 'all';
		}

		$product_type = isset( $data['product_type'] ) ? sanitize_text_field( (string) $data['product_type'] ) : 'all';
		$type_allowed = SDFW_Stock_Options::instance()->get_product_type_options();
		if ( ! isset( $type_allowed[ $product_type ] ) ) {
			$product_type = 'all';
		}

		$this->stock_status = $stock_status;
		$this->category     = isset( $data['category'] ) ? absint( $data['category'] ) : 0;
		$this->brand        = isset( $data['brand'] ) ? absint( $data['brand'] ) : 0;
		$this->product_type = $product_type;
		$this->search       = isset( $data['s'] ) ? sanitize_text_field( (string) $data['s'] ) : '';
		$this->paged        = isset( $data['paged'] ) ? max( 1, absint( $data['paged'] ) ) : 1;
	}

	/**
	 * Create from GET parameters.
	 *
	 * @return self
	 */
	public static function from_get(): self {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only filter state, sanitized field-by-field in the constructor above.
		return new self( $_GET );
	}

	/**
	 * Convert to array.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'stock_status' => $this->stock_status,
			'category'     => $this->category,
			'brand'        => $this->brand,
			'product_type' => $this->product_type,
			'search'       => $this->search,
			'paged'        => $this->paged,
		);
	}
}
