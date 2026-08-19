/**
 * Inventory admin app powered by DataViews.
 */

import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import Page from '../../shared/components/Page';
import { fetchInventoryList, fetchOptions } from '../api';
import { createInventoryActions } from '../actions';
import {
	createInventoryFields,
	DEFAULT_TABLE_FIELDS,
} from '../fields';

const DEFAULT_LAYOUTS = {
	table: {
		showMedia: false,
	},
};

function getInitialPerPage() {
	const configured = Number( window.sdfwAdmin?.inventory?.perPage );
	return configured > 0 ? configured : 10;
}

function readViewFromUrl( perPage ) {
	const params = new URLSearchParams( window.location.search );
	const filters = [];

	const category = params.get( 'category' );
	if ( category && Number( category ) > 0 ) {
		filters.push( {
			field: 'category',
			operator: 'is',
			value: Number( category ),
		} );
	}

	const productType = params.get( 'product_type' );
	if ( productType && productType !== 'all' ) {
		filters.push( {
			field: 'productType',
			operator: 'is',
			value: productType,
		} );
	}

	const stockStatus = params.get( 'stock_status' );
	if ( stockStatus && stockStatus !== 'all' ) {
		filters.push( {
			field: 'stockStatus',
			operator: 'is',
			value: stockStatus,
		} );
	}

	const brand = params.get( 'brand' );
	if ( brand && Number( brand ) > 0 ) {
		filters.push( {
			field: 'brand',
			operator: 'is',
			value: Number( brand ),
		} );
	}

	const page = Math.max( 1, Number( params.get( 'paged' ) ) || 1 );
	const search = params.get( 's' ) || '';

	return {
		type: 'table',
		search,
		filters,
		page,
		perPage,
		fields: DEFAULT_TABLE_FIELDS,
		layout: { showMedia: false },
	};
}

function syncViewToUrl( view ) {
	const params = new URLSearchParams( window.location.search );
	params.set( 'page', 'stockdesk' );
	params.set( 'path', '/inventory' );

	if ( view.search ) {
		params.set( 's', view.search );
	} else {
		params.delete( 's' );
	}

	params.set( 'paged', String( view.page || 1 ) );

	const filterMap = {
		category: 'category',
		productType: 'product_type',
		stockStatus: 'stock_status',
		brand: 'brand',
	};

	Object.values( filterMap ).forEach( ( key ) => params.delete( key ) );

	( view.filters || [] ).forEach( ( filter ) => {
		const key = filterMap[ filter.field ];
		if ( ! key || filter.value === undefined || filter.value === '' ) {
			return;
		}
		params.set( key, String( filter.value ) );
	} );

	const next = `${ window.location.pathname }?${ params.toString() }`;
	window.history.replaceState( { path: '/inventory' }, '', next );
}

export default function InventoryApp() {
	const perPage = getInitialPerPage();
	const [ view, setView ] = useState( () => readViewFromUrl( perPage ) );
	const [ options, setOptions ] = useState( null );
	const [ items, setItems ] = useState( [] );
	const [ totalItems, setTotalItems ] = useState( 0 );
	const [ isLoading, setIsLoading ] = useState( true );
	const [ isBootstrapping, setIsBootstrapping ] = useState( true );
	const [ selection, setSelection ] = useState( [] );
	const [ notice, setNotice ] = useState( null );

	const { listFields, formFields } = useMemo(
		() => createInventoryFields( options || {} ),
		[ options ]
	);

	const loadList = useCallback( async ( nextView ) => {
		setIsLoading( true );
		try {
			const data = await fetchInventoryList( nextView );
			setItems( data?.items || [] );
			setTotalItems( Number( data?.total ) || 0 );
		} catch ( error ) {
			setNotice( {
				status: 'error',
				message:
					error?.message ||
					__(
						'Could not load inventory.',
						'stockdesk-for-woocommerce'
					),
			} );
			setItems( [] );
			setTotalItems( 0 );
		} finally {
			setIsLoading( false );
		}
	}, [] );

	useEffect( () => {
		let cancelled = false;
		( async () => {
			try {
				const data = await fetchOptions();
				if ( ! cancelled ) {
					setOptions( data );
				}
			} catch ( error ) {
				if ( ! cancelled ) {
					setNotice( {
						status: 'error',
						message:
							error?.message ||
							__(
								'Could not load inventory options.',
								'stockdesk-for-woocommerce'
							),
					} );
				}
			} finally {
				if ( ! cancelled ) {
					setIsBootstrapping( false );
				}
			}
		} )();
		return () => {
			cancelled = true;
		};
	}, [] );

	useEffect( () => {
		if ( isBootstrapping ) {
			return;
		}
		syncViewToUrl( view );
		loadList( view );
	}, [ view, isBootstrapping, loadList ] );

	const handleChangeView = useCallback( ( nextView ) => {
		setView( ( current ) => {
			const merged = {
				...current,
				...nextView,
				fields: nextView.fields?.length
					? nextView.fields
					: DEFAULT_TABLE_FIELDS,
			};
			if (
				merged.search !== current.search ||
				JSON.stringify( merged.filters || [] ) !==
					JSON.stringify( current.filters || [] )
			) {
				merged.page = 1;
			}
			return merged;
		} );
	}, [] );

	const actions = useMemo(
		() =>
			createInventoryActions( {
				formFields,
				onNotice: setNotice,
				onSaved: () => {
					setSelection( [] );
					loadList( view );
				},
			} ),
		[ formFields, loadList, view ]
	);

	const paginationInfo = useMemo(
		() => ( {
			totalItems,
			totalPages: Math.max(
				1,
				Math.ceil( totalItems / ( view.perPage || perPage ) )
			),
		} ),
		[ totalItems, view.perPage, perPage ]
	);

	if ( isBootstrapping ) {
		return (
			<Page>
				<div
					className="sdfw-inventory-loading"
					aria-busy="true"
					aria-live="polite"
				>
					<span className="screen-reader-text">
						{ __(
							'Loading inventory…',
							'stockdesk-for-woocommerce'
						) }
					</span>
					<div className="sdfw-inventory-skeleton-row" />
					<div className="sdfw-inventory-skeleton-row" />
					<div className="sdfw-inventory-skeleton-row" />
					<div className="sdfw-inventory-skeleton-row" />
					<div className="sdfw-inventory-skeleton-row" />
				</div>
			</Page>
		);
	}

	return (
		<Page>
			{ notice && (
				<Notice
					className="sdfw-inventory-notice"
					status={ notice.status }
					isDismissible
					onRemove={ () => setNotice( null ) }
				>
					{ notice.message }
				</Notice>
			) }
			<DataViews
				data={ items }
				fields={ listFields }
				view={ view }
				onChangeView={ handleChangeView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				defaultLayouts={ DEFAULT_LAYOUTS }
				isLoading={ isLoading }
				selection={ selection }
				onChangeSelection={ setSelection }
				getItemId={ ( item ) => String( item.id ) }
				search
				searchLabel={ __(
					'Search products',
					'stockdesk-for-woocommerce'
				) }
			/>
		</Page>
	);
}
