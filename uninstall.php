<?php
/**
 * Plugin uninstall handler.
 *
 * @package StockDesk for WooCommerce
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$sdfw_users = get_users(
	array(
		'fields' => 'ID',
	)
);

foreach ( $sdfw_users as $sdfw_user_id ) {
	delete_user_option( (int) $sdfw_user_id, 'sdfw_inventory_per_page' );
}
