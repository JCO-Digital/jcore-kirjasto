import apiFetch from '@wordpress/api-fetch';
import { fingerprint } from './fingerprint';

const PATH = '/jcore-kirjasto/v1/duplicates/check';

/**
 * Asks the library which of the files it already holds.
 *
 * @param {File[]} files Files about to be uploaded.
 * @return {Promise<Array<Object[]>>} For each file, what it duplicates: the
 *                                    closest first, possibly none.
 */
export async function check( files ) {
	const prints = await Promise.all(
		files.map( ( file ) => fingerprint( file ).catch( () => null ) )
	);
	const known = prints
		.map( ( print, index ) => ( print ? index : null ) )
		.filter( ( index ) => null !== index );

	const matches = files.map( () => [] );
	if ( ! known.length ) {
		return matches;
	}

	const response = await apiFetch( {
		path: PATH,
		method: 'POST',
		data: { files: known.map( ( index ) => prints[ index ] ) },
	} );

	known.forEach( ( index, i ) => {
		matches[ index ] = response.matches?.[ i ] || [];
	} );

	return matches;
}
