/**
 * Inventory DataViews actions (Edit / Bulk Edit stock).
 */

import { useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import { Button, Spinner } from '@wordpress/components';
import { DataForm } from '@wordpress/dataviews';
import { STOCK_FORM } from './fields';
import { bulkUpdateInventory, saveInventoryRow } from './api';

function pickStockFormData( item = {} ) {
	return {
		manageStock: item.manageStock ?? 'no',
		stockQuantity:
			item.stockQuantity === null || item.stockQuantity === undefined
				? ''
				: item.stockQuantity,
		lowStockThreshold:
			item.lowStockThreshold === null ||
			item.lowStockThreshold === undefined
				? ''
				: item.lowStockThreshold,
		stockStatus: item.stockStatus ?? 'instock',
		backorders: item.backorders ?? 'no',
	};
}

function consolidateStockFormData( items ) {
	const base = pickStockFormData( items[ 0 ] );
	const keys = Object.keys( base );

	keys.forEach( ( key ) => {
		const first = items[ 0 ]?.[ key ];
		const allMatch = items.every( ( item ) => item?.[ key ] === first );
		if ( ! allMatch ) {
			base[ key ] = '';
		}
	} );

	return base;
}

function StockEditModal( {
	items,
	formFields,
	closeModal,
	onActionPerformed,
	onNotice,
} ) {
	const isBulk = items.length > 1;
	const [ formData, setFormData ] = useState( () =>
		isBulk
			? consolidateStockFormData( items )
			: pickStockFormData( items[ 0 ] )
	);
	const [ edits, setEdits ] = useState( {} );
	const [ isSaving, setIsSaving ] = useState( false );

	const title = useMemo( () => {
		if ( ! isBulk ) {
			return sprintf(
				/* translators: %s: product name */
				__( 'Edit stock: %s', 'stockdesk-for-woocommerce' ),
				items[ 0 ]?.name || ''
			);
		}
		return sprintf(
			/* translators: %d: number of products */
			__( 'Edit stock for %d products', 'stockdesk-for-woocommerce' ),
			items.length
		);
	}, [ isBulk, items ] );

	const handleChange = ( partial ) => {
		setEdits( ( prev ) => ( { ...prev, ...partial } ) );
		setFormData( ( prev ) => ( { ...prev, ...partial } ) );
	};

	const handleSave = async () => {
		setIsSaving( true );
		try {
			if ( isBulk ) {
				if ( ! Object.keys( edits ).length ) {
					onNotice?.( {
						status: 'warning',
						message: __(
							'Change at least one field before saving.',
							'stockdesk-for-woocommerce'
						),
					} );
					setIsSaving( false );
					return;
				}
				const result = await bulkUpdateInventory(
					items.map( ( item ) => item.id ),
					edits
				);
				const updated = result?.updated ?? 0;
				const skipped = result?.skipped ?? 0;
				const failed = result?.failed ?? 0;

				if ( failed > 0 || skipped > 0 ) {
					onNotice?.( {
						status: 'warning',
						message:
							result?.message ||
							sprintf(
								__(
									'Bulk update finished. Updated: %1$d. Skipped: %2$d. Failed: %3$d.',
									'stockdesk-for-woocommerce'
								),
								updated,
								skipped,
								failed
							),
					} );
				} else {
					onNotice?.( {
						status: 'success',
						message:
							result?.message ||
							sprintf(
								/* translators: %d: number of updated products */
								__(
									'Bulk stock update complete. Updated %d items.',
									'stockdesk-for-woocommerce'
								),
								updated
							),
					} );
				}
			} else {
				await saveInventoryRow( items[ 0 ].id, formData );
				onNotice?.( {
					status: 'success',
					message: __(
						'Stock row updated successfully.',
						'stockdesk-for-woocommerce'
					),
				} );
			}
			onActionPerformed?.( items );
			closeModal?.();
		} catch ( error ) {
			onNotice?.( {
				status: 'error',
				message:
					error?.message ||
					__(
						'Action could not be completed.',
						'stockdesk-for-woocommerce'
					),
			} );
		} finally {
			setIsSaving( false );
		}
	};

	return (
		<div className="sdfw-stock-edit-modal">
			<p>
				<strong>{ title }</strong>
			</p>
			<DataForm
				data={ formData }
				fields={ formFields }
				form={ STOCK_FORM }
				onChange={ handleChange }
			/>
			<div className="sdfw-stock-edit-modal__actions">
				<Button
					variant="tertiary"
					onClick={ closeModal }
					disabled={ isSaving }
				>
					{ __( 'Cancel', 'stockdesk-for-woocommerce' ) }
				</Button>
				<Button
					variant="primary"
					onClick={ handleSave }
					disabled={ isSaving }
				>
					{ isSaving ? (
						<Spinner />
					) : (
						__( 'Save', 'stockdesk-for-woocommerce' )
					) }
				</Button>
			</div>
		</div>
	);
}

/**
 * @param {Object}   args
 * @param {Array}    args.formFields
 * @param {Function} args.onNotice
 * @param {Function} args.onSaved
 * @return {Array} DataViews actions.
 */
export function createInventoryActions( { formFields, onNotice, onSaved } ) {
	const sharedModal = ( { items, closeModal, onActionPerformed } ) => (
		<StockEditModal
			items={ items }
			formFields={ formFields }
			closeModal={ closeModal }
			onActionPerformed={ ( performed ) => {
				onSaved?.( performed );
				onActionPerformed?.( performed );
			} }
			onNotice={ onNotice }
		/>
	);

	return [
		{
			id: 'edit-stock',
			label: __( 'Edit stock', 'stockdesk-for-woocommerce' ),
			isPrimary: true,
			supportsBulk: true,
			modalSize: 'medium',
			RenderModal: sharedModal,
		},
	];
}
