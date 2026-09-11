/**
 * Export Form Component — 3-step flow
 */

import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Card,
	CardHeader,
	CardBody,
	Button,
	SelectControl,
	CheckboxControl,
	TextControl,
	TextareaControl,
	FormTokenField,
	Notice,
	Spinner,
	__experimentalText as Text,
} from '@wordpress/components';
import { IconChevronDown } from '@tabler/icons-react';
import Page from '../../shared/components/Page';
import { postAdminAjax } from '../../shared/ajax';

const STEP_ORDER = [ 'filters', 'preview', 'export' ];

function useInert( active ) {
	const ref = useRef( null );

	useEffect( () => {
		const node = ref.current;
		if ( ! node ) {
			return;
		}
		if ( active ) {
			node.setAttribute( 'inert', '' );
		} else {
			node.removeAttribute( 'inert' );
		}
	}, [ active ] );

	return ref;
}

const normalizeName = ( value ) =>
	( value || '' ).toString().trim().toLowerCase();

const tokensToValues = ( tokens, options, valueKey ) => {
	if ( ! Array.isArray( tokens ) || tokens.length === 0 ) {
		return [];
	}
	const byName = new Map(
		( options || [] ).map( ( item ) => [
			normalizeName( item?.name ),
			item?.[ valueKey ],
		] )
	);
	return tokens
		.map( ( token ) => byName.get( normalizeName( token ) ) )
		.filter(
			( value ) => value !== undefined && value !== null && value !== ''
		);
};

const downloadExportFile = ( content, filename ) => {
	const binary = atob( content || '' );
	const bytes = new Uint8Array( binary.length );
	for ( let i = 0; i < binary.length; i += 1 ) {
		bytes[ i ] = binary.charCodeAt( i );
	}
	const blob = new Blob( [ bytes ], {
		type: 'text/csv;charset=utf-8',
	} );
	const url = URL.createObjectURL( blob );
	const link = document.createElement( 'a' );
	link.href = url;
	link.download = filename || 'stockdesk-export.csv';
	document.body.appendChild( link );
	link.click();
	document.body.removeChild( link );
	URL.revokeObjectURL( url );
};

const ExportForm = () => {
	const productStatusOptions =
		window.sdfwAdmin?.export?.productStatuses || [];
	const defaultProductStatuses = productStatusOptions
		.filter( ( status ) => status.key === 'publish' )
		.map( ( status ) => status.name );

	const [ currentStep, setCurrentStep ] = useState( 'filters' );
	const [ filtersExpanded, setFiltersExpanded ] = useState( true );
	const [ settingsExpanded, setSettingsExpanded ] = useState( true );
	const [ isExporting, setIsExporting ] = useState( false );
	const [ isPreviewing, setIsPreviewing ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ previewResults, setPreviewResults ] = useState( null );
	const [ exportResult, setExportResult ] = useState( null );

	const filtersContentRef = useInert( ! filtersExpanded );
	const settingsContentRef = useInert( ! settingsExpanded );

	const [ filters, setFilters ] = useState( {
		product_type: 'all',
		product_status: defaultProductStatuses,
		stock_status: 'all',
		manage_stock: true,
		min_stock: '',
		max_stock: '',
		sku_list: '',
		category: [],
		brand: [],
		tags: [],
		include_optional_columns: false,
		csv_delimiter: ',',
	} );

	const hasSKUs = filters.sku_list.trim().length > 0;
	const showStickyFooter =
		currentStep === 'filters' || currentStep === 'preview';

	const updateFilter = ( key, value ) => {
		setPreviewResults( null );
		setExportResult( null );
		setFilters( ( prev ) => ( {
			...prev,
			[ key ]: value,
		} ) );
	};

	const buildFinalFilters = () => {
		const stockQtyEnabled = ! hasSKUs && filters.manage_stock;
		const payloadFilters = {
			...filters,
			manage_stock: filters.manage_stock ? 'yes' : 'no',
			min_stock: stockQtyEnabled ? filters.min_stock : '',
			max_stock: stockQtyEnabled ? filters.max_stock : '',
			product_status: tokensToValues(
				filters.product_status,
				productStatusOptions,
				'key'
			),
			category: tokensToValues(
				filters.category,
				window.sdfwAdmin?.export?.taxonomies?.categories,
				'id'
			).map( ( id ) => Number( id ) ),
			brand: tokensToValues(
				filters.brand,
				window.sdfwAdmin?.export?.taxonomies?.brands,
				'id'
			).map( ( id ) => Number( id ) ),
			tags: tokensToValues(
				filters.tags,
				window.sdfwAdmin?.export?.taxonomies?.tags,
				'id'
			).map( ( id ) => Number( id ) ),
		};

		if ( hasSKUs ) {
			return {
				...payloadFilters,
				product_type: 'all',
				product_status: [],
				stock_status: 'all',
				manage_stock: 'no',
				min_stock: '',
				max_stock: '',
				category: [],
				brand: [],
				tags: [],
			};
		}

		return payloadFilters;
	};

	const validateFilters = () => {
		const stockQtyEnabled = ! hasSKUs && filters.manage_stock;
		const minStock =
			stockQtyEnabled && filters.min_stock !== ''
				? Number( filters.min_stock )
				: null;
		const maxStock =
			stockQtyEnabled && filters.max_stock !== ''
				? Number( filters.max_stock )
				: null;

		if (
			stockQtyEnabled &&
			minStock !== null &&
			maxStock !== null &&
			! Number.isNaN( minStock ) &&
			! Number.isNaN( maxStock ) &&
			minStock > maxStock
		) {
			return __(
				'Min stock quantity cannot be greater than max stock quantity.',
				'stockdesk-for-woocommerce'
			);
		}

		if ( ! window.sdfwAdmin?.export?.ajaxUrl ) {
			return __(
				'Export is unavailable. Please reload the page and try again.',
				'stockdesk-for-woocommerce'
			);
		}

		return null;
	};

	const resetAll = () => {
		setCurrentStep( 'filters' );
		setPreviewResults( null );
		setExportResult( null );
		setNotice( null );
	};

	const runPreview = async () => {
		const validationError = validateFilters();
		if ( validationError ) {
			setNotice( {
				status: 'error',
				message: validationError,
			} );
			return false;
		}

		setIsPreviewing( true );
		setNotice( null );

		try {
			const data = await postAdminAjax( {
				ajaxUrl: window.sdfwAdmin.export.ajaxUrl,
				action: 'sdfw_export_preview',
				nonce: window.sdfwAdmin?.export?.nonce || '',
				payload: {
					filters: buildFinalFilters(),
				},
				fallbackMessage: __(
					'Preview failed. Please try again.',
					'stockdesk-for-woocommerce'
				),
			} );

			setPreviewResults( data );
			setCurrentStep( 'preview' );
			return true;
		} catch ( err ) {
			setPreviewResults( null );
			setNotice( {
				status: 'error',
				message: err.message,
			} );
			return false;
		} finally {
			setIsPreviewing( false );
		}
	};

	const handleExport = async () => {
		const validationError = validateFilters();
		if ( validationError ) {
			setNotice( {
				status: 'error',
				message: validationError,
			} );
			return;
		}

		setIsExporting( true );
		setNotice( null );

		try {
			const data = await postAdminAjax( {
				ajaxUrl: window.sdfwAdmin.export.ajaxUrl,
				action: 'sdfw_export_csv',
				nonce: window.sdfwAdmin?.export?.nonce || '',
				payload: {
					filters: buildFinalFilters(),
				},
				fallbackMessage: __(
					'Export failed. Please try again.',
					'stockdesk-for-woocommerce'
				),
			} );

			const filename = data.filename || 'stockdesk-export.csv';
			downloadExportFile( data.content, filename );

			setExportResult( {
				filename,
				totalRows: previewResults?.total_rows ?? 0,
				content: data.content || '',
			} );
			setCurrentStep( 'export' );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message,
			} );
		} finally {
			setIsExporting( false );
		}
	};

	const canGoToStep = ( stepKey ) => {
		const targetIndex = STEP_ORDER.indexOf( stepKey );
		const currentIndex = STEP_ORDER.indexOf( currentStep );
		if ( targetIndex < 0 || targetIndex >= currentIndex ) {
			return false;
		}
		if ( isExporting || isPreviewing ) {
			return false;
		}
		return true;
	};

	const goToStep = ( stepKey ) => {
		if ( ! canGoToStep( stepKey ) ) {
			return;
		}
		if ( stepKey === 'filters' ) {
			setExportResult( null );
		}
		setCurrentStep( stepKey );
	};

	const handleRedownload = () => {
		if ( ! exportResult?.content ) {
			return;
		}
		downloadExportFile( exportResult.content, exportResult.filename );
	};

	const renderStepIndicator = () => {
		const steps = [
			{
				key: 'filters',
				label: __( 'Filters & Settings', 'stockdesk-for-woocommerce' ),
			},
			{
				key: 'preview',
				label: __( 'Preview', 'stockdesk-for-woocommerce' ),
			},
			{
				key: 'export',
				label: __( 'Export Complete', 'stockdesk-for-woocommerce' ),
			},
		];

		const currentIndex = steps.findIndex( ( s ) => s.key === currentStep );
		const currentLabel = steps[ currentIndex ]?.label || '';

		return (
			<>
				<ol
					className="sdfw-step-indicator"
					aria-label={ __(
						'Export steps',
						'stockdesk-for-woocommerce'
					) }
				>
					{ steps.map( ( step, index ) => {
						const status =
							index < currentIndex
								? 'completed'
								: index === currentIndex
								? 'current'
								: 'pending';
						const isClickable = canGoToStep( step.key );

						return (
							<li
								key={ step.key }
								className="sdfw-step-indicator__li"
							>
								<button
									type="button"
									className={ `sdfw-step-indicator__item is-${ status }` }
									aria-current={
										status === 'current'
											? 'step'
											: undefined
									}
									disabled={ ! isClickable }
									onClick={ () => goToStep( step.key ) }
								>
									<span className="sdfw-step-indicator__number">
										{ index + 1 }
									</span>
									<span className="sdfw-step-indicator__label">
										{ step.label }
									</span>
								</button>
							</li>
						);
					} ) }
				</ol>
				<p className="sdfw-step-status" aria-live="polite">
					{ sprintf(
						/* translators: 1: current step number, 2: total step count, 3: current step label */
						__( 'Step %1$d of %2$d: %3$s', 'stockdesk-for-woocommerce' ),
						currentIndex + 1,
						steps.length,
						currentLabel
					) }
				</p>
			</>
		);
	};

	const renderFiltersStep = () => (
		<>
			<Card
				className={ `sdfw-step-card sdfw-expandable-card ${
					filtersExpanded ? 'is-expanded' : 'is-collapsed'
				}` }
			>
				<CardHeader className="sdfw-expandable-card__header">
					<Text variant="title.large">
						{ __( 'Export Filters', 'stockdesk-for-woocommerce' ) }
					</Text>
					<Button
						variant="tertiary"
						className="sdfw-card-toggle"
						onClick={ () =>
							setFiltersExpanded( ( expanded ) => ! expanded )
						}
						aria-expanded={ filtersExpanded }
						aria-controls="sdfw-export-filters-content"
					>
						<span>
							{ filtersExpanded
								? __( 'Collapse', 'stockdesk-for-woocommerce' )
								: __( 'Expand', 'stockdesk-for-woocommerce' ) }
						</span>
						<span
							className="sdfw-card-toggle__icon"
							aria-hidden="true"
						>
							<IconChevronDown size={ 18 } stroke={ 1.75 } />
						</span>
					</Button>
				</CardHeader>
				<div
					ref={ filtersContentRef }
					id="sdfw-export-filters-content"
					className={ `sdfw-expandable-card__content ${
						filtersExpanded ? 'is-expanded' : 'is-collapsed'
					}` }
					aria-hidden={ ! filtersExpanded }
				>
					<div className="sdfw-expandable-card__content-inner">
						<CardBody>
							<div
								className={
									hasSKUs ? 'sdfw-filters-disabled' : ''
								}
							>
								<SelectControl
									label={ __(
										'Product Type',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.product_type }
									options={ [
										{
											label: __(
												'All types',
												'stockdesk-for-woocommerce'
											),
											value: 'all',
										},
										{
											label: __(
												'Simple',
												'stockdesk-for-woocommerce'
											),
											value: 'simple',
										},
										{
											label: __(
												'Variable',
												'stockdesk-for-woocommerce'
											),
											value: 'variable',
										},
									] }
									onChange={ ( value ) =>
										updateFilter( 'product_type', value )
									}
									disabled={ hasSKUs }
								/>

								<FormTokenField
									__experimentalExpandOnFocus
									label={ __(
										'Product Status',
										'stockdesk-for-woocommerce'
									) }
									help={ __(
										'Select which product statuses to include in the export.',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.product_status }
									onChange={ ( tokens ) =>
										updateFilter(
											'product_status',
											tokens
										)
									}
									suggestions={ productStatusOptions.map(
										( status ) => status.name
									) }
									placeholder={ __(
										'Type product statuses…',
										'stockdesk-for-woocommerce'
									) }
									disabled={ hasSKUs }
								/>

								<FormTokenField
									__experimentalExpandOnFocus
									label={ __(
										'Categories',
										'stockdesk-for-woocommerce'
									) }
									help={ __(
										'Select one or more product categories to filter by.',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.category }
									onChange={ ( tokens ) =>
										updateFilter( 'category', tokens )
									}
									suggestions={
										window.sdfwAdmin?.export?.taxonomies?.categories?.map(
											( c ) => c.name
										) || []
									}
									placeholder={ __(
										'Type category names…',
										'stockdesk-for-woocommerce'
									) }
									disabled={ hasSKUs }
								/>

								<FormTokenField
									__experimentalExpandOnFocus
									label={ __(
										'Brands',
										'stockdesk-for-woocommerce'
									) }
									help={ __(
										'Select one or more product brands to filter by.',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.brand }
									onChange={ ( tokens ) =>
										updateFilter( 'brand', tokens )
									}
									suggestions={
										window.sdfwAdmin?.export?.taxonomies?.brands?.map(
											( b ) => b.name
										) || []
									}
									placeholder={ __(
										'Type brand names…',
										'stockdesk-for-woocommerce'
									) }
									disabled={ hasSKUs }
								/>

								<FormTokenField
									__experimentalExpandOnFocus
									label={ __(
										'Tags',
										'stockdesk-for-woocommerce'
									) }
									help={ __(
										'Select one or more product tags to filter by.',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.tags }
									onChange={ ( tokens ) =>
										updateFilter( 'tags', tokens )
									}
									suggestions={
										window.sdfwAdmin?.export?.taxonomies?.tags?.map(
											( t ) => t.name
										) || []
									}
									placeholder={ __(
										'Type tag names…',
										'stockdesk-for-woocommerce'
									) }
									disabled={ hasSKUs }
								/>

								<hr className="sdfw-filter-divider" />

								<CheckboxControl
									label={ __(
										'Only export products where stock management is enabled',
										'stockdesk-for-woocommerce'
									) }
									checked={
										hasSKUs ? false : filters.manage_stock
									}
									onChange={ ( checked ) => {
										setPreviewResults( null );
										setExportResult( null );
										setFilters( ( prev ) => ( {
											...prev,
											manage_stock: checked,
											...( checked
												? {}
												: {
														min_stock: '',
														max_stock: '',
												  } ),
										} ) );
									} }
									disabled={ hasSKUs }
								/>

								<SelectControl
									label={ __(
										'Stock Status',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.stock_status }
									options={ [
										{
											label: __(
												'All statuses',
												'stockdesk-for-woocommerce'
											),
											value: 'all',
										},
										{
											label: __(
												'In stock',
												'stockdesk-for-woocommerce'
											),
											value: 'instock',
										},
										{
											label: __(
												'Out of stock',
												'stockdesk-for-woocommerce'
											),
											value: 'outofstock',
										},
										{
											label: __(
												'On backorder',
												'stockdesk-for-woocommerce'
											),
											value: 'onbackorder',
										},
									] }
									onChange={ ( value ) =>
										updateFilter( 'stock_status', value )
									}
									disabled={ hasSKUs }
								/>

								<div className="sdfw-range-filters">
									<TextControl
										label={ __(
											'Min Stock Quantity',
											'stockdesk-for-woocommerce'
										) }
										help={ __(
											'Enter the minimum stock quantity threshold.',
											'stockdesk-for-woocommerce'
										) }
										type="number"
										value={ filters.min_stock }
										onChange={ ( value ) =>
											updateFilter( 'min_stock', value )
										}
										disabled={
											hasSKUs || ! filters.manage_stock
										}
									/>
									<TextControl
										label={ __(
											'Max Stock Quantity',
											'stockdesk-for-woocommerce'
										) }
										help={ __(
											'Enter the maximum stock quantity threshold.',
											'stockdesk-for-woocommerce'
										) }
										type="number"
										value={ filters.max_stock }
										onChange={ ( value ) =>
											updateFilter( 'max_stock', value )
										}
										disabled={
											hasSKUs || ! filters.manage_stock
										}
									/>
								</div>
							</div>

							<div className="sdfw-sku-section">
								<TextareaControl
									label={ __(
										'SKU List',
										'stockdesk-for-woocommerce'
									) }
									help={ __(
										'Enter specific SKUs to export. Overrides all filters above when filled.',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.sku_list }
									onChange={ ( value ) =>
										updateFilter( 'sku_list', value )
									}
									placeholder={ __(
										'e.g. SHIRT-001, SHIRT-002 or paste one SKU per line',
										'stockdesk-for-woocommerce'
									) }
									rows={ 3 }
								/>
							</div>
						</CardBody>
					</div>
				</div>
			</Card>

			<Card
				className={ `sdfw-step-card sdfw-expandable-card sdfw-export-settings-card ${
					settingsExpanded ? 'is-expanded' : 'is-collapsed'
				}` }
			>
				<CardHeader className="sdfw-expandable-card__header">
					<Text variant="title.large">
						{ __(
							'Export Settings',
							'stockdesk-for-woocommerce'
						) }
					</Text>
					<Button
						variant="tertiary"
						className="sdfw-card-toggle"
						onClick={ () =>
							setSettingsExpanded( ( expanded ) => ! expanded )
						}
						aria-expanded={ settingsExpanded }
						aria-controls="sdfw-export-settings-content"
					>
						<span>
							{ settingsExpanded
								? __( 'Collapse', 'stockdesk-for-woocommerce' )
								: __( 'Expand', 'stockdesk-for-woocommerce' ) }
						</span>
						<span
							className="sdfw-card-toggle__icon"
							aria-hidden="true"
						>
							<IconChevronDown size={ 18 } stroke={ 1.75 } />
						</span>
					</Button>
				</CardHeader>
				<div
					ref={ settingsContentRef }
					id="sdfw-export-settings-content"
					className={ `sdfw-expandable-card__content ${
						settingsExpanded ? 'is-expanded' : 'is-collapsed'
					}` }
					aria-hidden={ ! settingsExpanded }
				>
					<div className="sdfw-expandable-card__content-inner">
						<CardBody>
							<div className="sdfw-export-options">
								<CheckboxControl
									label={ __(
										'Include additional product details (product type, parent product name, category, brand, and tags)',
										'stockdesk-for-woocommerce'
									) }
									checked={
										filters.include_optional_columns
									}
									onChange={ ( checked ) =>
										updateFilter(
											'include_optional_columns',
											checked
										)
									}
								/>
								<SelectControl
									label={ __(
										'CSV Delimiter',
										'stockdesk-for-woocommerce'
									) }
									value={ filters.csv_delimiter }
									options={ [
										{
											label: __(
												'Comma (,)',
												'stockdesk-for-woocommerce'
											),
											value: ',',
										},
										{
											label: __(
												'Semicolon (;)',
												'stockdesk-for-woocommerce'
											),
											value: ';',
										},
									] }
									onChange={ ( value ) =>
										updateFilter( 'csv_delimiter', value )
									}
									help={ __(
										'Use semicolon (;) if your CSV opens with everything in one column, common with European Excel installs. Comma works for most systems.',
										'stockdesk-for-woocommerce'
									) }
								/>
							</div>
						</CardBody>
					</div>
				</div>
			</Card>
		</>
	);

	const renderPreviewStep = () => {
		if ( ! previewResults ) {
			return (
				<Card className="sdfw-step-card">
					<CardBody>
						<div className="sdfw-export-loading">
							<Spinner />
							<p>
								{ __(
									'Loading preview…',
									'stockdesk-for-woocommerce'
								) }
							</p>
						</div>
					</CardBody>
				</Card>
			);
		}

		const { total_rows: totalRows, preview, columns } = previewResults;

		return (
			<Card className="sdfw-step-card">
				<CardHeader>
					<Text variant="title.large">
						{ __( 'Export Preview', 'stockdesk-for-woocommerce' ) }
					</Text>
				</CardHeader>
				<CardBody>
					<div className="sdfw-stats-grid">
						<div className="sdfw-stat-card is-success">
							<span className="sdfw-stat-value">{ totalRows }</span>
							<span className="sdfw-stat-label">
								{ __(
									'Products to Export',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						<div className="sdfw-stat-card is-neutral">
							<span className="sdfw-stat-value">
								{ preview?.length || 0 }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Preview Rows',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						<div className="sdfw-stat-card is-neutral">
							<span className="sdfw-stat-value">
								{ filters.include_optional_columns
									? __( 'Yes', 'stockdesk-for-woocommerce' )
									: __( 'No', 'stockdesk-for-woocommerce' ) }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Optional Columns',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
					</div>

					{ preview && preview.length > 0 && (
						<div className="sdfw-preview-section">
							<h4>
								{ __(
									'Sample Preview (First 5 rows)',
									'stockdesk-for-woocommerce'
								) }
							</h4>
							<table className="sdfw-preview-table">
								<thead>
									<tr>
										<th>
											{ __(
												'Row',
												'stockdesk-for-woocommerce'
											) }
										</th>
										{ ( columns || [] ).map( ( column ) => (
											<th key={ column.key }>
												{ column.label }
											</th>
										) ) }
									</tr>
								</thead>
								<tbody>
									{ preview.map( ( row ) => (
										<tr key={ row.row }>
											<td>{ row.row }</td>
											{ ( columns || [] ).map(
												( column ) => (
													<td key={ column.key }>
														{ column.key ===
														'sku' ? (
															<code>
																{ row[
																	column.key
																] || '—' }
															</code>
														) : (
															row[ column.key ] ||
															'—'
														) }
													</td>
												)
											) }
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }
				</CardBody>
			</Card>
		);
	};

	const renderExportStep = () => {
		if ( ! exportResult ) {
			return null;
		}

		return (
			<Card className="sdfw-step-card">
				<CardHeader>
					<Text variant="title.large">
						{ __(
							'Export Complete',
							'stockdesk-for-woocommerce'
						) }
					</Text>
				</CardHeader>
				<CardBody>
					<Notice status="success" isDismissible={ false }>
						{ __(
							'Your CSV export downloaded successfully.',
							'stockdesk-for-woocommerce'
						) }
					</Notice>

					<div className="sdfw-stats-grid sdfw-export-results-stats">
						<div className="sdfw-stat-card is-success">
							<span className="sdfw-stat-value">
								{ exportResult.totalRows }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Products Exported',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						<button
							type="button"
							className="sdfw-stat-card is-neutral sdfw-stat-card--filename is-clickable"
							onClick={ handleRedownload }
							title={ __(
								'Click to download again',
								'stockdesk-for-woocommerce'
							) }
						>
							<span className="sdfw-stat-value">
								<code>{ exportResult.filename }</code>
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Downloaded File',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</button>
					</div>

					<div className="sdfw-action-row">
						<Button variant="primary" onClick={ resetAll }>
							{ __(
								'Start New Export',
								'stockdesk-for-woocommerce'
							) }
						</Button>
					</div>
				</CardBody>
			</Card>
		);
	};

	return (
		<Page>
			<div
				className={
					'sdfw-export-form' +
					( showStickyFooter ? ' has-sticky-footer' : '' )
				}
			>
				<div className="sdfw-sticky-header">
					{ renderStepIndicator() }
				</div>

				{ notice && (
					<Notice
						className="sdfw-export-notice"
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ currentStep === 'filters' && renderFiltersStep() }
				{ currentStep === 'preview' && renderPreviewStep() }
				{ currentStep === 'export' && renderExportStep() }

				{ currentStep === 'filters' && (
					<div className="sdfw-sticky-footer">
						<div className="sdfw-footer-actions sdfw-footer-actions--end">
							<Button
								variant="primary"
								onClick={ runPreview }
								disabled={ isPreviewing }
							>
								{ isPreviewing ? (
									<>
										<Spinner />
										{ __(
											'Loading preview…',
											'stockdesk-for-woocommerce'
										) }
									</>
								) : (
									__(
										'Continue to Preview',
										'stockdesk-for-woocommerce'
									)
								) }
							</Button>
						</div>
					</div>
				) }

				{ currentStep === 'preview' && (
					<div className="sdfw-sticky-footer">
						<div className="sdfw-footer-actions">
							<Button
								variant="secondary"
								onClick={ () => goToStep( 'filters' ) }
								disabled={ isExporting }
							>
								{ __(
									'Edit Filters',
									'stockdesk-for-woocommerce'
								) }
							</Button>
							<Button
								variant="primary"
								onClick={ handleExport }
								isBusy={ isExporting }
								disabled={ isExporting || ! previewResults }
							>
								{ isExporting
									? __(
											'Starting download…',
											'stockdesk-for-woocommerce'
									  )
									: __(
											'Export to CSV',
											'stockdesk-for-woocommerce'
									  ) }
							</Button>
						</div>
					</div>
				) }
			</div>
		</Page>
	);
};

export default ExportForm;
