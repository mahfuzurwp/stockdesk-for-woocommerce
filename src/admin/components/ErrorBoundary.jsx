/**
 * Recover from unexpected render errors in the admin SPA.
 */

import { Component } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { Button } from '@wordpress/components';

export default class ErrorBoundary extends Component {
	constructor( props ) {
		super( props );
		this.state = { hasError: false };
	}

	static getDerivedStateFromError() {
		return { hasError: true };
	}

	componentDidCatch( error, errorInfo ) {
		if ( process.env.NODE_ENV !== 'production' ) {
			// eslint-disable-next-line no-console
			console.error( 'StockDesk render error:', error, errorInfo );
		}
	}

	render() {
		if ( this.state.hasError ) {
			return (
				<div className="sdfw-error-boundary">
					<h2>
						{ __(
							'Something went wrong',
							'stockdesk-for-woocommerce'
						) }
					</h2>
					<p>
						{ __(
							'StockDesk encountered an unexpected error. Reload the page to try again.',
							'stockdesk-for-woocommerce'
						) }
					</p>
					<Button variant="primary" onClick={ () => window.location.reload() }>
						{ __( 'Reload page', 'stockdesk-for-woocommerce' ) }
					</Button>
				</div>
			);
		}

		return this.props.children;
	}
}
