/**
 * DataViews / DataForm field definitions for Inventory.
 */

import { __ } from '@wordpress/i18n';

const MANAGE_STOCK_ELEMENTS = [
	{ value: 'yes', label: __( 'On', 'stockdesk-for-woocommerce' ) },
	{ value: 'no', label: __( 'Off', 'stockdesk-for-woocommerce' ) },
];

const isManageStockOn = ( item ) => item?.manageStock === 'yes';

/**
 * Build list + form fields from filter option payloads.
 *
 * @param {Object} options Options from sdfw_inventory_options.
 * @return {Object} Field collections.
 */
export function createInventoryFields( options = {} ) {
	const stockStatusElements = options.stockStatuses || [];
	const stockStatusFilterElements = options.stockStatusFilters || [];
	const productTypeElements = options.productTypes || [];
	const categoryElements = options.categories || [];
	const brandElements = options.brands || [];
	const backorderElements = options.backorders || [];
	const globalLowStockThreshold = options.globalLowStockThreshold ?? '';

	const listFields = [
		{
			id: 'name',
			type: 'text',
			label: __( 'Product', 'stockdesk-for-woocommerce' ),
			enableHiding: false,
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.name,
			render: ( { item } ) => {
				const meta = [
					item.sku
						? `${ __( 'SKU:', 'stockdesk-for-woocommerce' ) } ${
								item.sku
						  }`
						: null,
					item.typeLabel || null,
					item.parentName
						? `${ __(
								'Variation of',
								'stockdesk-for-woocommerce'
						  ) } ${ item.parentName }`
						: null,
				]
					.filter( Boolean )
					.join( ' · ' );

				return (
					<div className="sdfw-inventory-product">
						<strong className="sdfw-inventory-product__name">
							{ item.name }
						</strong>
						{ meta ? (
							<span
								className="sdfw-inventory-product__meta"
								title={ meta }
							>
								{ meta }
							</span>
						) : null }
					</div>
				);
			},
		},
		{
			id: 'manageStock',
			type: 'text',
			label: __( 'Manage stock', 'stockdesk-for-woocommerce' ),
			enableSorting: false,
			filterBy: false,
			elements: MANAGE_STOCK_ELEMENTS,
			getValue: ( { item } ) => item.manageStock,
		},
		{
			id: 'stockQuantity',
			type: 'integer',
			label: __( 'Stock qty', 'stockdesk-for-woocommerce' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.stockQuantity,
			render: ( { item } ) =>
				item.manageStock === 'yes'
					? item.stockQuantity ?? '—'
					: '—',
		},
		{
			id: 'lowStockThreshold',
			type: 'integer',
			label: __( 'Low stock threshold', 'stockdesk-for-woocommerce' ),
			enableSorting: false,
			filterBy: false,
			getValue: ( { item } ) => item.lowStockThreshold,
			render: ( { item } ) => {
				if ( item.manageStock !== 'yes' ) {
					return '—';
				}
				if (
					item.lowStockThreshold === null ||
					item.lowStockThreshold === ''
				) {
					return (
						<span className="description">
							{ globalLowStockThreshold }
						</span>
					);
				}
				return item.lowStockThreshold;
			},
		},
		{
			id: 'stockStatus',
			type: 'text',
			label: __( 'Stock status', 'stockdesk-for-woocommerce' ),
			enableSorting: false,
			elements: stockStatusFilterElements.length
				? stockStatusFilterElements
				: stockStatusElements,
			filterBy: {
				operators: [ 'is' ],
				isPrimary: true,
			},
			getValue: ( { item } ) => item.stockStatus,
		},
		{
			id: 'backorders',
			type: 'text',
			label: __( 'Allow backorders', 'stockdesk-for-woocommerce' ),
			enableSorting: false,
			filterBy: false,
			elements: backorderElements,
			getValue: ( { item } ) => item.backorders,
			render: ( { item } ) => {
				if ( item.manageStock !== 'yes' ) {
					return '—';
				}
				const match = backorderElements.find(
					( option ) => option.value === item.backorders
				);
				return match?.label || item.backorders || '—';
			},
		},
		{
			id: 'category',
			type: 'integer',
			label: __( 'Category', 'stockdesk-for-woocommerce' ),
			elements: categoryElements,
			enableSorting: false,
			enableHiding: true,
			filterBy: {
				operators: [ 'is' ],
				isPrimary: true,
			},
			getValue: ( { item } ) => item.categoryId || 0,
			render: () => null,
		},
		{
			id: 'productType',
			type: 'text',
			label: __( 'Product type', 'stockdesk-for-woocommerce' ),
			elements: productTypeElements,
			enableSorting: false,
			enableHiding: true,
			filterBy: {
				operators: [ 'is' ],
				isPrimary: true,
			},
			getValue: ( { item } ) => item.type,
			render: () => null,
		},
	];

	if ( brandElements.length ) {
		listFields.push( {
			id: 'brand',
			type: 'integer',
			label: __( 'Brand', 'stockdesk-for-woocommerce' ),
			elements: brandElements,
			enableSorting: false,
			enableHiding: true,
			filterBy: {
				operators: [ 'is' ],
				isPrimary: true,
			},
			getValue: ( { item } ) => item.brandId || 0,
			render: () => null,
		} );
	}

	const formFields = [
		{
			id: 'manageStock',
			type: 'text',
			label: __( 'Manage stock', 'stockdesk-for-woocommerce' ),
			elements: MANAGE_STOCK_ELEMENTS,
			Edit: 'select',
		},
		{
			id: 'stockQuantity',
			type: 'integer',
			label: __( 'Stock quantity', 'stockdesk-for-woocommerce' ),
			isVisible: isManageStockOn,
		},
		{
			id: 'lowStockThreshold',
			type: 'integer',
			label: __( 'Low stock threshold', 'stockdesk-for-woocommerce' ),
			placeholder: String( globalLowStockThreshold ),
			isVisible: isManageStockOn,
		},
		{
			id: 'stockStatus',
			type: 'text',
			label: __( 'Stock status', 'stockdesk-for-woocommerce' ),
			elements: stockStatusElements,
			Edit: 'select',
		},
		{
			id: 'backorders',
			type: 'text',
			label: __( 'Allow backorders', 'stockdesk-for-woocommerce' ),
			elements: backorderElements,
			Edit: 'select',
			isVisible: isManageStockOn,
		},
	];

	return { listFields, formFields };
}

export const STOCK_FORM = {
	layout: { type: 'regular', labelPosition: 'side' },
	fields: [
		'manageStock',
		'stockQuantity',
		'lowStockThreshold',
		'stockStatus',
		'backorders',
	],
};

/**
 * Visible table fields (hide filter-only columns from the table layout).
 */
export const DEFAULT_TABLE_FIELDS = [
	'name',
	'manageStock',
	'stockQuantity',
	'lowStockThreshold',
	'stockStatus',
	'backorders',
];
