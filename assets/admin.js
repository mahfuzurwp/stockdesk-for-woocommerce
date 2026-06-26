/* global window, document */
( function () {
	'use strict';

	const config = window.sdfwAdmin || {};
	const i18n = config.i18n || {};

	const toggle = document.getElementById( 'sdfw-select-all' );
	const bulkActionSelector = document.getElementById( 'sdfw-bulk-action-selector' );
	const bulkApplyButton = document.getElementById( 'sdfw-bulk-action-apply' );
	const bulkRow = document.getElementById( 'sdfw-bulk-edit-row' );
	const bulkRowTitle = bulkRow ? bulkRow.querySelector( 'strong' ) : null;
	const bulkCancelButton = document.querySelector( '.sdfw-bulk-cancel' );
	const table = document.querySelector( '.sdfw-table' );
	const boxes = document.querySelectorAll( '.sdfw-select-row' );

	const updateBulkTitle = ( count ) => {
		if ( ! bulkRowTitle ) {
			return;
		}

		bulkRowTitle.textContent = count === 1
			? ( i18n.bulkEditOne || '' )
			: ( i18n.bulkEditMany || '' ).replace( '%d', String( count ) );
	};

	const setSelectionLocked = ( locked ) => {
		if ( toggle ) {
			toggle.disabled = locked;
		}

		boxes.forEach( ( box ) => {
			box.style.pointerEvents = locked ? 'none' : '';
			box.setAttribute( 'aria-disabled', locked ? 'true' : 'false' );
		} );
	};

	const setRowEditorsDisabled = ( disabled ) => {
		document.querySelectorAll( '.sdfw-table tbody tr:not(#sdfw-bulk-edit-row) select, .sdfw-table tbody tr:not(#sdfw-bulk-edit-row) input[type="number"], .sdfw-table tbody tr:not(#sdfw-bulk-edit-row) .sdfw-update-button' ).forEach( ( field ) => {
			if ( disabled ) {
				field.dataset.sdfwWasDisabled = field.disabled ? '1' : '0';
				field.disabled = true;
			} else {
				field.disabled = field.dataset.sdfwWasDisabled === '1';
				delete field.dataset.sdfwWasDisabled;
			}
		} );
	};

	const exitBulkEditMode = () => {
		if ( bulkRow ) {
			bulkRow.hidden = true;
		}

		if ( table ) {
			table.classList.remove( 'sdfw-bulk-editing' );
		}

		setRowEditorsDisabled( false );
		setSelectionLocked( false );
	};

	const enterBulkEditMode = () => {
		if ( ! bulkRow ) {
			return;
		}

		bulkRow.hidden = false;
		updateBulkTitle( document.querySelectorAll( '.sdfw-select-row:checked' ).length );

		if ( table ) {
			table.classList.add( 'sdfw-bulk-editing' );
		}

		setRowEditorsDisabled( true );
		setSelectionLocked( true );
	};

	if ( toggle ) {
		toggle.addEventListener( 'change', () => {
			boxes.forEach( ( box ) => {
				box.checked = toggle.checked;
			} );
		} );
	}

	if ( bulkApplyButton && bulkActionSelector && bulkRow ) {
		bulkApplyButton.addEventListener( 'click', () => {
			const selected = document.querySelectorAll( '.sdfw-select-row:checked' ).length;
			if ( bulkActionSelector.value !== 'edit' ) {
				exitBulkEditMode();
				return;
			}

			if ( selected < 1 ) {
				window.alert( i18n.selectAtLeastOne || '' );
				return;
			}

			enterBulkEditMode();
		} );
	}

	if ( bulkCancelButton ) {
		bulkCancelButton.addEventListener( 'click', () => {
			exitBulkEditMode();
		} );
	}

	const bulkManageStock = document.querySelector( '.sdfw-bulk-manage-stock' );
	const bulkThresholdMode = document.querySelector( '.sdfw-bulk-threshold-mode' );
	const bulkThresholdInput = document.querySelector( '.sdfw-bulk-threshold-input' );
	if ( bulkManageStock ) {
		const syncBulkDependents = () => {
			const enabled = bulkManageStock.value === 'yes';
			document.querySelectorAll( '.sdfw-bulk-stock-dependent' ).forEach( ( field ) => {
				field.disabled = ! enabled;
			} );

			if ( bulkThresholdMode && bulkThresholdInput ) {
				bulkThresholdInput.disabled = ! enabled || bulkThresholdMode.value !== 'set';
			}
		};

		syncBulkDependents();
		bulkManageStock.addEventListener( 'change', syncBulkDependents );
		if ( bulkThresholdMode && bulkThresholdInput ) {
			bulkThresholdMode.addEventListener( 'change', syncBulkDependents );
		}
	}

	const manageStockInputs = document.querySelectorAll( '.sdfw-manage-stock' );
	manageStockInputs.forEach( ( input ) => {
		const form = input.form;
		if ( ! form ) {
			return;
		}

		const syncDependentFields = () => {
			const shouldEnable = input.value === 'yes';
			form.querySelectorAll( '.sdfw-stock-dependent' ).forEach( ( field ) => {
				field.disabled = ! shouldEnable;
			} );
		};

		syncDependentFields();
		input.addEventListener( 'change', syncDependentFields );
	} );
}() );
