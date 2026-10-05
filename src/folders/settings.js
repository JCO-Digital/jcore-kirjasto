/**
 * What PHP hands the folder list: the tree, the user's rights and the screen.
 *
 * `current` is the folder the Media screen was opened with, from the URL:
 * a folder ID, 0 for files without a folder, or null for all files.
 */
const defaults = {
	tree: { folders: [], total: 0, unassigned: 0 },
	canManage: false,
	screen: 'modal',
	current: null,
	imports: [],
	key: 'jcore_kirjasto_folder',
	field: 'jcore_kirjasto_folder_id',
	bulkAction: 'jcore_kirjasto_move',
};

export const settings = {
	...defaults,
	...( window.jcoreKirjastoFolders || {} ),
};

/**
 * The attachments collection prop, request parameter and query var holding
 * the folder a library shows.
 */
export const KEY = settings.key;

export const LOCALE = document.documentElement.lang || undefined;

const numbers = new Intl.NumberFormat( LOCALE );

export const formatNumber = ( value ) => numbers.format( value );
