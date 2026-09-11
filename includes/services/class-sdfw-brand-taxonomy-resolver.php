<?php
/**
 * Brand taxonomy resolver.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brand taxonomy resolver.
 */
final class SDFW_Brand_Taxonomy_Resolver {

	/**
	 * Known brand taxonomies.
	 *
	 * @var array<int, string>
	 */
	private const KNOWN_TAXONOMIES = array(
		'product_brand',
		'pwb-brand',
		'pa_brand',
		'berocket_brand',
		'yith_product_brand',
	);

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Brand_Taxonomy_Resolver|null
	 */
	private static $instance = null;

	/**
	 * Cached taxonomy name.
	 *
	 * @var string|null
	 */
	private $cached_taxonomy = null;

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Brand_Taxonomy_Resolver
	 */
	public static function instance(): SDFW_Brand_Taxonomy_Resolver {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Resolve active brand taxonomy name if available.
	 *
	 * @return string
	 */
	public function resolve(): string {
		if ( null !== $this->cached_taxonomy ) {
			return $this->cached_taxonomy;
		}

		foreach ( self::KNOWN_TAXONOMIES as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				$this->cached_taxonomy = $taxonomy;
				return $taxonomy;
			}
		}

		$this->cached_taxonomy = '';
		return '';
	}
}
