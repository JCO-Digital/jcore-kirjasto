import { LOCALE } from './settings';

const collator = new Intl.Collator( LOCALE, {
	numeric: true,
	sensitivity: 'base',
} );

/**
 * Groups folders by parent, each group sorted by name.
 *
 * @param {Array} folders Folders.
 * @return {Map<number, Array>} Children, keyed by parent ID; 0 for the top level.
 */
export function childrenOf( folders ) {
	const children = new Map();

	for ( const folder of folders ) {
		if ( ! children.has( folder.parent ) ) {
			children.set( folder.parent, [] );
		}
		children.get( folder.parent ).push( folder );
	}

	for ( const list of children.values() ) {
		list.sort( ( a, b ) => collator.compare( a.name, b.name ) );
	}

	return children;
}

/**
 * The IDs of a folder's parent, its parent's parent and so on.
 *
 * @param {Array}  folders Folders.
 * @param {number} id      Folder ID.
 * @return {number[]} Ancestor IDs, nearest first.
 */
export function ancestors( folders, id ) {
	const byId = new Map( folders.map( ( folder ) => [ folder.id, folder ] ) );
	const found = [];

	let folder = byId.get( id );
	while ( folder?.parent && ! found.includes( folder.parent ) ) {
		found.push( folder.parent );
		folder = byId.get( folder.parent );
	}

	return found;
}

/**
 * Lists folders depth first, in tree order, with their depth.
 *
 * @param {Array} folders Folders.
 * @return {Array} Folders with a `depth`.
 */
export function flatten( folders ) {
	const children = childrenOf( folders );
	const list = [];

	const walk = ( parent, depth ) => {
		for ( const folder of children.get( parent ) || [] ) {
			list.push( { ...folder, depth } );
			walk( folder.id, depth + 1 );
		}
	};
	walk( 0, 0 );

	return list;
}
