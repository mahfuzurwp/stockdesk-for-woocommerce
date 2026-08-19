import { createRoot } from '@wordpress/element';
import App from './App';
import './style.scss';
import '../inventory/style.scss';
import '../import/style.scss';
import '../export/style.scss';

const root = document.getElementById( 'sdfw-app-root' );
if ( root ) {
	createRoot( root ).render( <App /> );
}
