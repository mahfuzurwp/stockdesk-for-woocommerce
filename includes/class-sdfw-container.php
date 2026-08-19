<?php
/**
 * Simple service container for dependency injection.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SDFW_Container {

	/**
	 * Singleton instance.
	 *
	 * @var SDFW_Container|null
	 */
	private static $instance = null;

	/**
	 * Bindings.
	 *
	 * @var array<string, callable>
	 */
	private $bindings = array();

	/**
	 * Resolved instances.
	 *
	 * @var array<string, mixed>
	 */
	private $instances = array();

	/**
	 * Get singleton instance.
	 *
	 * @return SDFW_Container
	 */
	public static function instance(): SDFW_Container {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Bind a service.
	 *
	 * @param string   $id      Service ID.
	 * @param callable $factory Factory callback.
	 * @return void
	 */
	public function bind( string $id, callable $factory ): void {
		$this->bindings[ $id ] = $factory;
	}

	/**
	 * Resolve a service.
	 *
	 * @param string $id Service ID.
	 * @return mixed
	 */
	public function get( string $id ) {
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		if ( ! isset( $this->bindings[ $id ] ) ) {
			return null;
		}

		$instance = call_user_func( $this->bindings[ $id ], $this );
		$this->instances[ $id ] = $instance;

		return $instance;
	}

	/**
	 * Check if a service is bound.
	 *
	 * @param string $id Service ID.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] );
	}
}
