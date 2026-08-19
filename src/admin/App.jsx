/**
 * StockDesk SPA root.
 */

import { useEffect, useState } from '@wordpress/element';
import AppShell from './components/AppShell';
import ErrorBoundary from './components/ErrorBoundary';
import InventoryApp from '../inventory/components/InventoryApp';
import ImportForm from '../import/components/ImportForm';
import ExportForm from '../export/components/ExportForm';
import {
	PATHS,
	getPathFromLocation,
	normalizePath,
	navigate,
} from './routing';

function Screen( { path } ) {
	switch ( path ) {
		case PATHS.import:
			return <ImportForm />;
		case PATHS.export:
			return <ExportForm />;
		case PATHS.inventory:
		default:
			return <InventoryApp />;
	}
}

export default function App() {
	const [ path, setPath ] = useState( () => getPathFromLocation() );

	useEffect( () => {
		const onPopState = () => setPath( getPathFromLocation() );
		const onNavigate = ( event ) => {
			setPath( normalizePath( event.detail?.path ) );
		};

		window.addEventListener( 'popstate', onPopState );
		window.addEventListener( 'sdfw:navigate', onNavigate );

		const params = new URLSearchParams( window.location.search );
		if ( ! params.get( 'path' ) ) {
			navigate( getPathFromLocation(), {
				replace: true,
				clearFilters: false,
			} );
		}

		return () => {
			window.removeEventListener( 'popstate', onPopState );
			window.removeEventListener( 'sdfw:navigate', onNavigate );
		};
	}, [] );

	return (
		<AppShell currentPath={ path }>
			<ErrorBoundary>
				<Screen path={ path } />
			</ErrorBoundary>
		</AppShell>
	);
}
