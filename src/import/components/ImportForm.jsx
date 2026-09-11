/**
 * Import Form Component - 4-Step Async Flow
 */

import { useMemo, useRef, useState, useEffect } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	__experimentalText as Text,
} from '@wordpress/components';
import { IconFileTypeCsv } from '@tabler/icons-react';
import Page from '../../shared/components/Page';
import { postAdminAjax } from '../../shared/ajax';

const getImportConfig = () => ( {
	ajaxUrl: window.sdfwAdmin?.import?.ajaxUrl || '/wp-admin/admin-ajax.php',
	nonce: window.sdfwAdmin?.import?.nonce || '',
} );

const STEP_ORDER = [ 'upload', 'mapping', 'dry-run', 'import' ];

const ALLOWED_FIELDS = {
	sku: {
		label: __( 'SKU (Match Key)', 'stockdesk-for-woocommerce' ),
		type: 'text',
		required: true,
	},
	manage_stock: {
		label: __( 'Manage Stock', 'stockdesk-for-woocommerce' ),
		type: 'boolean',
	},
	stock_quantity: {
		label: __( 'Stock Quantity', 'stockdesk-for-woocommerce' ),
		type: 'number',
	},
	stock_status: {
		label: __( 'Stock Status', 'stockdesk-for-woocommerce' ),
		type: 'enum',
		options: [ 'instock', 'outofstock', 'onbackorder' ],
	},
	backorders: {
		label: __( 'Backorders', 'stockdesk-for-woocommerce' ),
		type: 'enum',
		options: [ 'yes', 'no', 'notify' ],
	},
	low_stock_threshold: {
		label: __( 'Low Stock Threshold', 'stockdesk-for-woocommerce' ),
		type: 'number',
	},
};

const normalizeDetectedMapping = ( detectedMap = {} ) =>
	Object.entries( detectedMap ).reduce( ( acc, [ field, header ] ) => {
		if ( ALLOWED_FIELDS[ field ] && header ) {
			acc[ field ] = header;
		}
		return acc;
	}, {} );

const buildMappingPayload = ( fieldMapping = {} ) => {
	const payload = {};
	const headerAssignments = {};
	const duplicates = [];

	Object.keys( ALLOWED_FIELDS ).forEach( ( field ) => {
		const header = ( fieldMapping[ field ] || '' ).trim();
		if ( ! header ) {
			return;
		}
		if ( headerAssignments[ header ] ) {
			duplicates.push( header );
			return;
		}
		headerAssignments[ header ] = field;
		payload[ field ] = header;
	} );

	return {
		payload,
		duplicateHeaders: [ ...new Set( duplicates ) ],
	};
};

const ImportForm = () => {
	const fileInputRef = useRef( null );
	const pollingRef = useRef( null );
	const pollingFailuresRef = useRef( 0 );

	const [ currentStep, setCurrentStep ] = useState( 'upload' );
	const [ sessionId, setSessionId ] = useState( '' );
	const [ fileName, setFileName ] = useState( '' );
	const [ headers, setHeaders ] = useState( [] );
	const [ sampleRows, setSampleRows ] = useState( [] );
	const [ mapping, setMapping ] = useState( {} );
	const [ isUploading, setIsUploading ] = useState( false );
	const [ isDetecting, setIsDetecting ] = useState( false );
	const [ isValidating, setIsValidating ] = useState( false );
	const [ isImporting, setIsImporting ] = useState( false );
	const [ isDragging, setIsDragging ] = useState( false );
	const [ notice, setNotice ] = useState( null );
	const [ validationErrors, setValidationErrors ] = useState( [] );
	const [ dryRunResults, setDryRunResults ] = useState( null );
	const [ importProgress, setImportProgress ] = useState( {
		total: 0,
		processed: 0,
		percent: 0,
		success: 0,
		failed: 0,
		logs: [],
		complete: false,
	} );

	const csvColumnOptions = useMemo(
		() => [
			{
				label: __( 'Do not import', 'stockdesk-for-woocommerce' ),
				value: '',
			},
			...headers.map( ( header ) => ( {
				label: header,
				value: header,
			} ) ),
		],
		[ headers ]
	);

	const showStickyFooter =
		currentStep === 'mapping' || currentStep === 'dry-run';

	const resetAll = () => {
		setCurrentStep( 'upload' );
		setSessionId( '' );
		setFileName( '' );
		setHeaders( [] );
		setSampleRows( [] );
		setMapping( {} );
		setNotice( null );
		setValidationErrors( [] );
		setDryRunResults( null );
		setImportProgress( {
			total: 0,
			processed: 0,
			percent: 0,
			success: 0,
			failed: 0,
			logs: [],
			complete: false,
		} );
		if ( pollingRef.current ) {
			clearInterval( pollingRef.current );
			pollingRef.current = null;
		}
	};

	const processFile = async ( file ) => {
		if ( ! file ) {
			return;
		}

		const lower = ( file.name || '' ).toLowerCase();
		if ( ! lower.endsWith( '.csv' ) ) {
			setNotice( {
				status: 'error',
				message: __(
					'Please upload a CSV file.',
					'stockdesk-for-woocommerce'
				),
			} );
			return;
		}

		setIsUploading( true );
		setIsDetecting( true );
		setNotice( null );

		const { ajaxUrl, nonce } = getImportConfig();

		try {
			const data = await postAdminAjax( {
				ajaxUrl,
				action: 'stockdesk_detect_mapping',
				nonce,
				payload: {
					csv_file: file,
				},
				fallbackMessage: __(
					'Failed to process file.',
					'stockdesk-for-woocommerce'
				),
			} );

			setSessionId( data.session_id );
			setFileName( file.name );
			setHeaders( data.headers );
			setSampleRows( data.sample_rows || [] );
			setMapping(
				normalizeDetectedMapping( data.detected_map || {} )
			);
			setCurrentStep( 'mapping' );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message,
			} );
		} finally {
			setIsUploading( false );
			setIsDetecting( false );
		}
	};

	const handleFileSelection = async ( event ) => {
		const [ file ] = event.target.files || [];
		await processFile( file );
		event.target.value = '';
	};

	const handleDragOver = ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		if ( ! isUploading ) {
			setIsDragging( true );
		}
	};

	const handleDragLeave = ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		setIsDragging( false );
	};

	const handleDrop = async ( event ) => {
		event.preventDefault();
		event.stopPropagation();
		setIsDragging( false );
		if ( isUploading ) {
			return;
		}
		const [ file ] = event.dataTransfer?.files || [];
		await processFile( file );
	};

	const handleMappingChange = ( field, value ) => {
		setMapping( ( prev ) => {
			const next = { ...prev };
			Object.keys( next ).forEach( ( key ) => {
				if ( key !== field && next[ key ] === value && value !== '' ) {
					next[ key ] = '';
				}
			} );
			next[ field ] = value;
			return next;
		} );
	};

	const validateMapping = async () => {
		const { payload, duplicateHeaders } = buildMappingPayload( mapping );

		if ( duplicateHeaders.length > 0 ) {
			setValidationErrors( [
				sprintf(
					/* translators: %s: comma-separated list of duplicated CSV column headers */
					__(
						'Each CSV column can only be used once. Please review: %s',
						'stockdesk-for-woocommerce'
					),
					duplicateHeaders.join( ', ' )
				),
			] );
			return;
		}

		setIsValidating( true );
		setValidationErrors( [] );
		setNotice( null );

		const { ajaxUrl, nonce } = getImportConfig();

		try {
			await postAdminAjax( {
				ajaxUrl,
				action: 'stockdesk_validate_mapping',
				nonce,
				payload: {
					session_id: sessionId,
					mapping: payload,
				},
				fallbackMessage: __(
					'Validation failed.',
					'stockdesk-for-woocommerce'
				),
			} );

			await runDryRun();
		} catch ( err ) {
			if ( err.errors?.length ) {
				setValidationErrors( err.errors );
				return;
			}
			setNotice( {
				status: 'error',
				message: err.message,
			} );
		} finally {
			setIsValidating( false );
		}
	};

	const runDryRun = async () => {
		const { ajaxUrl, nonce } = getImportConfig();

		try {
			const data = await postAdminAjax( {
				ajaxUrl,
				action: 'stockdesk_run_dry_run',
				nonce,
				payload: {
					session_id: sessionId,
				},
				fallbackMessage: __(
					'Dry run failed.',
					'stockdesk-for-woocommerce'
				),
			} );

			setDryRunResults( data );
			setCurrentStep( 'dry-run' );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message,
			} );
		}
	};

	const startImport = async () => {
		setIsImporting( true );
		setCurrentStep( 'import' );
		setNotice( null );

		const { ajaxUrl, nonce } = getImportConfig();

		try {
			const data = await postAdminAjax( {
				ajaxUrl,
				action: 'stockdesk_start_import',
				nonce,
				payload: {
					session_id: sessionId,
				},
				fallbackMessage: __(
					'Failed to start import.',
					'stockdesk-for-woocommerce'
				),
			} );

			startProgressPolling( data.session_id );
		} catch ( err ) {
			setNotice( {
				status: 'error',
				message: err.message,
			} );
			setIsImporting( false );
		}
	};

	const stopPolling = ( message = null ) => {
		if ( pollingRef.current ) {
			clearInterval( pollingRef.current );
			pollingRef.current = null;
		}
		setIsImporting( false );
		if ( message ) {
			setNotice( {
				status: 'error',
				message,
			} );
		}
	};

	const startProgressPolling = ( sid ) => {
		if ( pollingRef.current ) {
			clearInterval( pollingRef.current );
		}

		pollingFailuresRef.current = 0;
		const { ajaxUrl, nonce } = getImportConfig();

		pollingRef.current = setInterval( async () => {
			try {
				const progress = await postAdminAjax( {
					ajaxUrl,
					action: 'stockdesk_import_progress',
					nonce,
					payload: {
						session_id: sid,
					},
					fallbackMessage: __(
						'Could not load import progress.',
						'stockdesk-for-woocommerce'
					),
				} );

				pollingFailuresRef.current = 0;
				setImportProgress( {
					total: progress.total,
					processed: progress.processed,
					percent: progress.percent,
					success: progress.success,
					failed: progress.failed,
					logs: progress.logs || [],
					complete: progress.all_complete,
				} );

				if ( progress.all_complete ) {
					stopPolling();
				}
			} catch ( err ) {
				pollingFailuresRef.current += 1;

				if ( pollingFailuresRef.current >= 3 ) {
					stopPolling(
						__(
							'Lost connection to import progress. Please refresh and check your products.',
							'stockdesk-for-woocommerce'
						)
					);
				}
			}
		}, 2000 );
	};

	useEffect( () => {
		return () => {
			if ( pollingRef.current ) {
				clearInterval( pollingRef.current );
			}
		};
	}, [] );

	const canGoToStep = ( stepKey ) => {
		const targetIndex = STEP_ORDER.indexOf( stepKey );
		const currentIndex = STEP_ORDER.indexOf( currentStep );
		if ( targetIndex < 0 || targetIndex >= currentIndex ) {
			return false;
		}
		if (
			currentStep === 'import' &&
			isImporting &&
			! importProgress.complete
		) {
			return false;
		}
		return true;
	};

	const goToStep = ( stepKey ) => {
		if ( ! canGoToStep( stepKey ) ) {
			return;
		}
		if ( stepKey === 'upload' ) {
			resetAll();
			return;
		}
		setCurrentStep( stepKey );
	};

	const renderStepIndicator = () => {
		const steps = [
			{
				key: 'upload',
				label: __( 'Upload CSV', 'stockdesk-for-woocommerce' ),
			},
			{
				key: 'mapping',
				label: __( 'Review Mapping', 'stockdesk-for-woocommerce' ),
			},
			{
				key: 'dry-run',
				label: __( 'Dry Run', 'stockdesk-for-woocommerce' ),
			},
			{
				key: 'import',
				label: __( 'Import & Results', 'stockdesk-for-woocommerce' ),
			},
		];

		const currentIndex = steps.findIndex( ( s ) => s.key === currentStep );
		const currentLabel = steps[ currentIndex ]?.label || '';

		return (
			<>
				<ol
					className="sdfw-step-indicator"
					aria-label={ __(
						'Import steps',
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
							<li key={ step.key } className="sdfw-step-indicator__li">
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
						__( 'Step %1$d of %2$d: %3$s', 'stockdesk-for-woocommerce' ),
						currentIndex + 1,
						steps.length,
						currentLabel
					) }
				</p>
			</>
		);
	};

	const renderUploadStep = () => (
		<Card className="sdfw-step-card">
			<CardHeader>
				<Text variant="title.large">
					{ __( 'Upload CSV File', 'stockdesk-for-woocommerce' ) }
				</Text>
			</CardHeader>
			<CardBody>
				<div
					className={
						'sdfw-upload-area' +
						( isDragging ? ' is-dragging' : '' )
					}
					onDragOver={ handleDragOver }
					onDragEnter={ handleDragOver }
					onDragLeave={ handleDragLeave }
					onDrop={ handleDrop }
				>
					<label htmlFor="csv-upload" className="sdfw-upload-label">
						<span className="sdfw-upload-icon" aria-hidden="true">
							<IconFileTypeCsv size={ 40 } stroke={ 1.5 } />
						</span>
						<span className="sdfw-upload-text">
							{ isUploading
								? __(
										'Uploading…',
										'stockdesk-for-woocommerce'
								  )
								: __(
										'Click to upload or drag and drop a CSV file',
										'stockdesk-for-woocommerce'
								  ) }
						</span>
						{ isUploading && <Spinner /> }
					</label>
				</div>
				{ fileName && (
					<Notice status="success" isDismissible={ false }>
						{ sprintf(
							/* translators: %s: uploaded file name */
							__( 'Uploaded: %s', 'stockdesk-for-woocommerce' ),
							fileName
						) }
					</Notice>
				) }
			</CardBody>
		</Card>
	);

	const renderMappingStep = () => {
		if ( isDetecting ) {
			return (
				<Card className="sdfw-step-card">
					<CardHeader>
						<Text variant="title.large">
							{ __(
								'Detecting Mapping',
								'stockdesk-for-woocommerce'
							) }
						</Text>
					</CardHeader>
					<CardBody>
						<div className="sdfw-skeleton-loader">
							<div className="sdfw-skeleton-row">
								<Spinner />
								<span>
									{ __(
										'Analyzing CSV headers…',
										'stockdesk-for-woocommerce'
									) }
								</span>
							</div>
							{ [ ...Array( 5 ) ].map( ( _, i ) => (
								<div key={ i } className="sdfw-skeleton-line" />
							) ) }
						</div>
					</CardBody>
				</Card>
			);
		}

		return (
			<Card className="sdfw-step-card">
				<CardHeader>
					<Text variant="title.large">
						{ __(
							'Review Field Mapping',
							'stockdesk-for-woocommerce'
						) }
					</Text>
				</CardHeader>
				<CardBody>
					{ validationErrors.length > 0 && (
						<Notice
							status="error"
							className="sdfw-validation-notice"
						>
							<ul>
								{ validationErrors.map( ( err, i ) => (
									<li key={ i }>{ err }</li>
								) ) }
							</ul>
						</Notice>
					) }
					<Notice status="info" isDismissible={ false }>
						{ __(
							'Map each import field to the CSV column you want to use. SKU is only used to find the matching product and will not be updated.',
							'stockdesk-for-woocommerce'
						) }
					</Notice>
					<div className="sdfw-mapping-table">
						<div className="sdfw-mapping-row sdfw-mapping-row--header">
							<span className="sdfw-mapping-header">
								{ __(
									'Import Field',
									'stockdesk-for-woocommerce'
								) }
							</span>
							<span className="sdfw-mapping-header">
								{ __(
									'CSV Column',
									'stockdesk-for-woocommerce'
								) }
							</span>
							<span className="sdfw-mapping-header">
								{ __(
									'Sample Value',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						{ Object.entries( ALLOWED_FIELDS ).map(
							( [ fieldKey, field ] ) => {
								const selectedHeader =
									mapping[ fieldKey ] || '';
								const headerIndex =
									headers.indexOf( selectedHeader );
								const sampleValue =
									headerIndex >= 0
										? sampleRows[ 0 ]?.[ headerIndex ] ||
										  ''
										: '';

								return (
									<div
										key={ fieldKey }
										className="sdfw-mapping-row"
									>
										<div className="sdfw-mapping-col">
											<strong>{ field.label }</strong>
											{ field.required && (
												<span className="sdfw-mapping-hint">
													{ __(
														'Required',
														'stockdesk-for-woocommerce'
													) }
												</span>
											) }
										</div>
										<div className="sdfw-mapping-col">
											<SelectControl
												value={ selectedHeader }
												options={ csvColumnOptions }
												onChange={ ( value ) =>
													handleMappingChange(
														fieldKey,
														value
													)
												}
											/>
										</div>
										<div className="sdfw-mapping-col">
											<code className="sdfw-sample-value">
												{ sampleValue || '-' }
											</code>
										</div>
									</div>
								);
							}
						) }
					</div>
				</CardBody>
			</Card>
		);
	};

	const renderDryRunStep = () => {
		if ( ! dryRunResults ) {
			return null;
		}

		const {
			valid_count: validCount,
			error_count: errorCount,
			preview,
			warnings,
			total_rows: totalRows,
		} = dryRunResults;

		return (
			<Card className="sdfw-step-card">
				<CardHeader>
					<Text variant="title.large">
						{ __( 'Dry Run Results', 'stockdesk-for-woocommerce' ) }
					</Text>
				</CardHeader>
				<CardBody>
					<div className="sdfw-stats-grid">
						<div className="sdfw-stat-card is-success">
							<span className="sdfw-stat-value">
								{ validCount }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Ready to Import',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						<div className="sdfw-stat-card is-warning">
							<span className="sdfw-stat-value">
								{ errorCount }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Will Be Skipped',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
						<div className="sdfw-stat-card is-neutral">
							<span className="sdfw-stat-value">
								{ totalRows }
							</span>
							<span className="sdfw-stat-label">
								{ __(
									'Total Rows',
									'stockdesk-for-woocommerce'
								) }
							</span>
						</div>
					</div>

					{ warnings && warnings.length > 0 && (
						<Notice
							status="warning"
							className="sdfw-warnings-notice"
						>
							<strong>
								{ __(
									'Non-blocking warnings:',
									'stockdesk-for-woocommerce'
								) }
							</strong>
							<ul>
								{ warnings.map( ( warning, i ) => (
									<li key={ i }>{ warning }</li>
								) ) }
							</ul>
						</Notice>
					) }

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
										<th>
											{ __(
												'SKU',
												'stockdesk-for-woocommerce'
											) }
										</th>
										<th>
											{ __(
												'Status',
												'stockdesk-for-woocommerce'
											) }
										</th>
										<th>
											{ __(
												'Changes',
												'stockdesk-for-woocommerce'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ preview.map( ( row ) => (
										<tr
											key={ row.row }
											className={
												row.errors.length > 0
													? 'has-error'
													: ''
											}
										>
											<td>{ row.row }</td>
											<td>
												<code>{ row.sku }</code>
											</td>
											<td>
												{ row.errors.length > 0 ? (
													<span className="sdfw-status-error">
														{ __(
															'Error',
															'stockdesk-for-woocommerce'
														) }
													</span>
												) : row.warnings.length > 0 ? (
													<span className="sdfw-status-warning">
														{ __(
															'Warning',
															'stockdesk-for-woocommerce'
														) }
													</span>
												) : (
													<span className="sdfw-status-ready">
														{ __(
															'Ready',
															'stockdesk-for-woocommerce'
														) }
													</span>
												) }
											</td>
											<td>
												{ row.errors.length > 0 ? (
													<span className="sdfw-error-message">
														{ row.errors.join(
															', '
														) }
													</span>
												) : (
													Object.entries(
														row.data
													).map( ( [ key, value ] ) => (
														<div
															key={ key }
															className="sdfw-change-item"
														>
															<strong>
																{ ALLOWED_FIELDS[
																	key
																]?.label ||
																	key }
																:
															</strong>{ ' ' }
															<span className="sdfw-change-value">
																{ value }
															</span>
														</div>
													) )
												) }
											</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
					) }

					<div className="sdfw-back-link">
						<Button
							variant="tertiary"
							onClick={ () => setCurrentStep( 'mapping' ) }
						>
							←{ ' ' }
							{ __(
								'Edit Mapping',
								'stockdesk-for-woocommerce'
							) }
						</Button>
					</div>
				</CardBody>
			</Card>
		);
	};

	const renderImportStep = () => {
		const {
			percent,
			processed,
			total,
			success,
			failed,
			complete,
			logs,
		} = importProgress;

		return (
			<Card className="sdfw-step-card">
				<CardHeader>
					<Text variant="title.large">
						{ complete
							? __(
									'Import Complete',
									'stockdesk-for-woocommerce'
							  )
							: __( 'Importing…', 'stockdesk-for-woocommerce' ) }
					</Text>
				</CardHeader>
				<CardBody>
					<div className="sdfw-progress-container">
						<div
							className="sdfw-progress-bar"
							role="progressbar"
							aria-valuemin={ 0 }
							aria-valuemax={ 100 }
							aria-valuenow={ percent }
							aria-label={ __(
								'Import progress',
								'stockdesk-for-woocommerce'
							) }
						>
							<div
								className={
									'sdfw-progress-fill' +
									( complete ? ' is-complete' : '' )
								}
								style={ { width: `${ percent }%` } }
							/>
						</div>
						<div className="sdfw-progress-text">
							{ complete
								? sprintf(
										/* translators: 1: rows processed, 2: total rows */
										__(
											'Completed: %1$d/%2$d rows',
											'stockdesk-for-woocommerce'
										),
										processed,
										total
								  )
								: sprintf(
										/* translators: 1: rows processed, 2: total rows, 3: percent complete */
										__(
											'Processing: %1$d/%2$d rows (%3$d%%)',
											'stockdesk-for-woocommerce'
										),
										processed,
										total,
										percent
								  ) }
						</div>
					</div>

					{ complete && (
						<div className="sdfw-results-stats">
							<div className="sdfw-stat-card is-success">
								<span className="sdfw-stat-value">
									{ success }
								</span>
								<span className="sdfw-stat-label">
									{ __(
										'Successful',
										'stockdesk-for-woocommerce'
									) }
								</span>
							</div>
							<div className="sdfw-stat-card is-error">
								<span className="sdfw-stat-value">
									{ failed }
								</span>
								<span className="sdfw-stat-label">
									{ __(
										'Failed',
										'stockdesk-for-woocommerce'
									) }
								</span>
							</div>
							<div className="sdfw-stat-card is-neutral">
								<span className="sdfw-stat-value">
									{ total }
								</span>
								<span className="sdfw-stat-label">
									{ __(
										'Total',
										'stockdesk-for-woocommerce'
									) }
								</span>
							</div>
						</div>
					) }

					{ logs && logs.length > 0 && (
						<div className="sdfw-import-logs">
							<h4>
								{ __(
									'Import Log',
									'stockdesk-for-woocommerce'
								) }
							</h4>
							<div className="sdfw-logs-container">
								{ logs.map( ( log, i ) => (
									<div
										key={ i }
										className={ `sdfw-log-item is-${
											log.success ? 'success' : 'error'
										}` }
									>
										<span className="sdfw-log-row">
											{ sprintf(
												/* translators: %d: CSV row number */
												__(
													'Row %d',
													'stockdesk-for-woocommerce'
												),
												log.row
											) }
										</span>
										<code className="sdfw-log-sku">
											{ log.sku }
										</code>
										<span className="sdfw-log-message">
											{ log.message }
										</span>
									</div>
								) ) }
							</div>
						</div>
					) }

					{ complete && (
						<div className="sdfw-action-row">
							<Button variant="primary" onClick={ resetAll }>
								{ __(
									'Start New Import',
									'stockdesk-for-woocommerce'
								) }
							</Button>
						</div>
					) }
				</CardBody>
			</Card>
		);
	};

	return (
		<Page>
			<div
				className={
					'sdfw-import-form' +
					( showStickyFooter ? ' has-sticky-footer' : '' )
				}
			>
				<div className="sdfw-sticky-header">
					{ renderStepIndicator() }
				</div>

				{ notice && (
					<Notice
						status={ notice.status }
						onRemove={ () => setNotice( null ) }
					>
						{ notice.message }
					</Notice>
				) }

				{ currentStep === 'upload' && renderUploadStep() }
				{ currentStep === 'mapping' && renderMappingStep() }
				{ currentStep === 'dry-run' && renderDryRunStep() }
				{ currentStep === 'import' && renderImportStep() }

				{ currentStep === 'mapping' && (
					<div className="sdfw-sticky-footer">
						<div className="sdfw-footer-actions">
							<Button
								variant="secondary"
								onClick={ () =>
									fileInputRef.current?.click()
								}
								disabled={ isUploading }
							>
								{ __(
									'Upload Different File',
									'stockdesk-for-woocommerce'
								) }
							</Button>
							<Button
								variant="primary"
								onClick={ validateMapping }
								disabled={ isValidating || isDetecting }
							>
								{ isValidating ? (
									<>
										<Spinner />
										{ __(
											'Validating…',
											'stockdesk-for-woocommerce'
										) }
									</>
								) : (
									__(
										'Run Dry Run',
										'stockdesk-for-woocommerce'
									)
								) }
							</Button>
						</div>
					</div>
				) }

				{ currentStep === 'dry-run' && (
					<div className="sdfw-sticky-footer">
						<div className="sdfw-footer-actions">
							<Button
								variant="secondary"
								onClick={ () => setCurrentStep( 'mapping' ) }
							>
								{ __(
									'Edit Mapping',
									'stockdesk-for-woocommerce'
								) }
							</Button>
							<Button
								variant="primary"
								onClick={ startImport }
								isBusy={ isImporting }
							>
								{ isImporting
									? __(
											'Starting Import…',
											'stockdesk-for-woocommerce'
									  )
									: __(
											'Confirm & Start Import',
											'stockdesk-for-woocommerce'
									  ) }
							</Button>
						</div>
					</div>
				) }

				<input
					ref={ fileInputRef }
					id="csv-upload"
					type="file"
					accept=".csv,text/csv"
					onChange={ handleFileSelection }
					className="sdfw-file-input-hidden"
				/>
			</div>
		</Page>
	);
};

export default ImportForm;
