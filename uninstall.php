<?php
/**
 * Plugin uninstall handler.
 *
 * @package MahfuzStockDeskInventoryWorkspace
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$users = get_users(
	array(
		'fields' => 'ID',
	)
);

foreach ( $users as $user_id ) {
	delete_user_option( (int) $user_id, 'sdfw_inventory_per_page' );
}
