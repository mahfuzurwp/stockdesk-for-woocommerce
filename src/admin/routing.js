/**
 * Lightweight path query routing for the StockDesk SPA.
 */

import { __ } from '@wordpress/i18n';

export const PATHS = {
	inventory: '/inventory',
	import: '/import',
	export: '/export',
};

const ALLOWED = new Set( Object.values( PATHS ) );

export function normalizePath( path ) {
	if ( ! path ) {
		return PATHS.inventory;
	}
	const next = path.startsWith( '/' ) ? path : `/${ path }`;
	return ALLOWED.has( next ) ? next : PATHS.inventory;
}

export function getPageTitle( path ) {
	const titles = {
		[ PATHS.inventory ]: __( 'Inventory', 'stockdesk-for-woocommerce' ),
		[ PATHS.import ]: __( 'Import', 'stockdesk-for-woocommerce' ),
		[ PATHS.export ]: __( 'Export', 'stockdesk-for-woocommerce' ),
	};

	return titles[ normalizePath( path ) ];
}

export function getPathFromLocation() {
	const params = new URLSearchParams( window.location.search );
	return normalizePath( params.get( 'path' ) || window.sdfwAdmin?.initialPath );
}

/**
 * Navigate to an in-app path.
 *
 * @param {string} path Target path.
 * @param {Object} options
 * @param {boolean} options.replace Use replaceState instead of pushState.
 * @param {boolean} options.clearFilters Clear inventory filter query params.
 */
export function navigate( path, { replace = false, clearFilters = true } = {} ) {
	const nextPath = normalizePath( path );
	const params = new URLSearchParams( window.location.search );
	params.set( 'page', 'stockdesk' );
	params.set( 'path', nextPath );

	if ( clearFilters || nextPath !== PATHS.inventory ) {
		[ 's', 'paged', 'category', 'product_type', 'stock_status', 'brand' ].forEach(
			( key ) => params.delete( key )
		);
	}

	const url = `${ window.location.pathname }?${ params.toString() }`;
	if ( replace ) {
		window.history.replaceState( { path: nextPath }, '', url );
	} else {
		window.history.pushState( { path: nextPath }, '', url );
	}

	window.dispatchEvent(
		new CustomEvent( 'sdfw:navigate', { detail: { path: nextPath } } )
	);
}
