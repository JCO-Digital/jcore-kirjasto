import { onMoved } from '../store';
import { KEY } from '../settings';

/**
 * The attachment collections a folder list controls, with the frame each
 * belongs to. When files move, the ones showing a folder reload, and an
 * attachment open in the details reloads so its folder field is current.
 */
const libraries = new Set();

export function trackLibrary( collection, controller ) {
	const entry = { collection, controller };
	libraries.add( entry );

	return () => libraries.delete( entry );
}

/**
 * Reads a library's folder prop: a folder ID, 0, or null for all files.
 *
 * @param {Object} collection Attachments collection.
 * @return {?number} The folder.
 */
export function libraryFolder( collection ) {
	const value = collection?.props?.get( KEY );

	return null === value || undefined === value || '' === value
		? null
		: Number( value );
}

onMoved( ( ids ) => {
	const moved = new Set( ids.map( Number ) );

	for ( const { collection, controller } of libraries ) {
		if ( null !== libraryFolder( collection ) ) {
			// Private, but stable since 3.5: rebuilds the query from the props.
			collection._requery?.();
		}

		const open = controller?.state?.()?.get?.( 'selection' )?.single?.();
		if ( open && moved.has( open.id ) ) {
			open.fetch();
		}
	}
} );
