/** لایهٔ نازک روی fetch — همهٔ فراخوانی‌ها یک جا، تا مدیریت خطا یک‌شکل باشد. */

/**
 * @param {string} path
 * @param {any} [options]
 */
export async function api( path, options ) {
	const res = await fetch( path, {
		headers: { 'Content-Type': 'application/json' },
		...options,
	} );
	const data = await res.json().catch( () => ( {} ) );
	if ( ! res.ok && ! data.error ) {
		data.error = `خطای سرور (${ res.status })`;
	}
	return data;
}

/**
 * @param {string} path
 * @param {any} body
 */
export function post( path, body ) {
	return api( path, { method: 'POST', body: JSON.stringify( body || {} ) } );
}

export const state = {
	/** @type {any} */
	data: null,
	/** @type {Set<(s:any)=>void>} */
	listeners: new Set(),
};

/** @param {(s:any)=>void} fn */
export function subscribe( fn ) {
	state.listeners.add( fn );
	if ( state.data ) {
		fn( state.data );
	}
	return () => state.listeners.delete( fn );
}

export async function refreshState() {
	state.data = await api( '/api/state' );
	for ( const fn of state.listeners ) {
		try {
			fn( state.data );
		} catch ( e ) {
			// یک شنوندهٔ خراب نباید بقیهٔ رابط را بخواباند.
			console.error( e );
		}
	}
	return state.data;
}

export const getState = () => state.data;
