/**
 * Remembers small UI preferences in localStorage, quietly doing without when
 * storage is unavailable.
 *
 * @param {string} key      Key, without the plugin prefix.
 * @param {*}      fallback Value when nothing is stored.
 * @return {*} The stored value.
 */
export function read( key, fallback ) {
	try {
		const value = window.localStorage.getItem( `jcore-kirjasto-${ key }` );

		return null === value ? fallback : JSON.parse( value );
	} catch {
		return fallback;
	}
}

export function write( key, value ) {
	try {
		window.localStorage.setItem(
			`jcore-kirjasto-${ key }`,
			JSON.stringify( value )
		);
	} catch {
		// Not remembered, then.
	}
}
