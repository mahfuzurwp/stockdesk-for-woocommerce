/**
 * Shared admin-ajax fetch helper.
 */

/**
 * @typedef {Object} AdminAjaxOptions
 * @property {string} ajaxUrl          Admin AJAX URL.
 * @property {string} action           WordPress AJAX action name.
 * @property {string} [nonce]          Request nonce.
 * @property {string} [nonceField]     Form field name for nonce (default: nonce).
 * @property {Object} [payload]          Additional POST fields.
 * @property {string} [fallbackMessage]  Message when server response has no message.
 */

/**
 * @param {AdminAjaxOptions} options Request options.
 * @return {Promise<any>} Parsed success payload (`json.data`).
 */
export async function postAdminAjax( {
	ajaxUrl,
	action,
	nonce = '',
	nonceField = 'nonce',
	payload = {},
	fallbackMessage = '',
} ) {
	const formData = new FormData();
	formData.append( 'action', action );

	if ( nonce ) {
		formData.append( nonceField, nonce );
	}

	Object.entries( payload ).forEach( ( [ key, value ] ) => {
		if ( value === undefined || value === null ) {
			return;
		}

		if ( value instanceof File ) {
			formData.append( key, value );
			return;
		}

		if ( typeof value === 'object' ) {
			formData.append( key, JSON.stringify( value ) );
			return;
		}

		formData.append( key, String( value ) );
	} );

	let response;

	try {
		response = await fetch( ajaxUrl || '/wp-admin/admin-ajax.php', {
			method: 'POST',
			credentials: 'same-origin',
			body: formData,
		} );
	} catch {
		const error = new Error(
			fallbackMessage ||
				'Network error. Please check your connection and try again.'
		);
		error.code = 'network_error';
		throw error;
	}

	let json;

	try {
		json = await response.json();
	} catch {
		const error = new Error(
			fallbackMessage ||
				'Unexpected server response. Please reload the page and try again.'
		);
		error.code = 'invalid_response';
		throw error;
	}

	if ( ! response.ok || ! json?.success ) {
		const message =
			json?.data?.message ||
			fallbackMessage ||
			'Request failed.';
		const error = new Error( message );
		error.code = json?.data?.code || '';
		error.errors = json?.data?.errors || null;
		throw error;
	}

	return json.data;
}
