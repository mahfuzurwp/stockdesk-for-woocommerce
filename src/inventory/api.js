/**
 * Inventory admin-ajax helpers.
 */

import { postAdminAjax } from '../shared/ajax';

const getConfig = () => window.sdfwAdmin?.inventory || {};

async function postAction( action, payload = {} ) {
	const { ajaxUrl, nonce } = getConfig();

	return postAdminAjax( {
		ajaxUrl,
		action,
		nonce,
		payload,
		fallbackMessage:
			window.sdfwAdmin?.inventory?.i18n?.requestFailed ??
			'Request failed.',
	} );
}

export function fetchOptions() {
	return postAction( 'sdfw_inventory_options' );
}

export function fetchInventoryList( view ) {
	return postAction( 'sdfw_inventory_list', { view } );
}

export function saveInventoryRow( productId, data ) {
	return postAction( 'sdfw_inventory_save_row', {
		product_id: productId,
		data,
	} );
}

export function bulkUpdateInventory( productIds, data ) {
	return postAction( 'sdfw_inventory_bulk_update', {
		product_ids: productIds,
		data,
	} );
}
