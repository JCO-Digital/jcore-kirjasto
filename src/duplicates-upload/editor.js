import apiFetch from '@wordpress/api-fetch';
import { check } from './check';
import { ask } from './dialog';

/*
 * Checks files the block editor uploads through the REST API, e.g. dropped
 * onto an image block. Using the file already in the library answers the
 * upload request with that attachment, so the block shows it instead.
 */

/**
 * Whether a request uploads a file to the media library.
 *
 * @param {Object} options apiFetch options.
 * @return {boolean} Whether it is an upload.
 */
function isUpload( options ) {
	const path = options.path || options.url || '';

	return (
		'POST' === ( options.method || '' ).toUpperCase() &&
		options.body instanceof window.FormData &&
		options.body.get( 'file' ) instanceof window.File &&
		/\/wp\/v2\/media\/?(?:\?|$)/.test( path )
	);
}

export function guardEditor() {
	apiFetch.use( async ( options, next ) => {
		if ( ! isUpload( options ) ) {
			return next( options );
		}

		const file = options.body.get( 'file' );

		let matches;
		try {
			[ matches ] = await check( [ file ] );
		} catch {
			return next( options );
		}

		if ( ! matches.length ) {
			return next( options );
		}

		const [ choice ] = await ask( [ { name: file.name, matches } ], true );

		if ( 'upload' === choice ) {
			return next( options );
		}

		// The attachment as the upload would have answered, also for authors,
		// who may not read every attachment through /wp/v2/media.
		return apiFetch( {
			path: `/jcore-kirjasto/v1/duplicates/media/${ matches[ 0 ].id }`,
		} );
	} );
}
