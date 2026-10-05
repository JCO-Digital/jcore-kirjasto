import { __ } from '@wordpress/i18n';
import { getState, subscribe } from '../store';
import { flatten } from '../tree';

/**
 * Folder pickers: the "Folder" field of the attachment details and the list
 * mode bulk action. PHP renders them with the current folder only; the
 * options come from the tree here, and follow it when it changes.
 */
const SELECTOR = 'select.jcore-kirjasto-folder-field';

function fill( select ) {
	const current = String( select.value || select.dataset.folder || 0 );
	const options = [
		new window.Option( __( 'No folder', 'jcore-kirjasto' ), '0' ),
	];

	for ( const folder of flatten( getState().folders ) ) {
		options.push(
			new window.Option(
				'   '.repeat( folder.depth ) + folder.name,
				String( folder.id )
			)
		);
	}

	select.replaceChildren( ...options );
	select.value = current;

	if ( select.value !== current ) {
		select.value = '0';
	}
}

export function hydrateFields( root = document ) {
	root.querySelectorAll( SELECTOR ).forEach( fill );
}

export function createPicker( name, label ) {
	const select = document.createElement( 'select' );
	select.name = name;
	select.className = 'jcore-kirjasto-folder-field';
	select.setAttribute( 'aria-label', label );
	fill( select );

	return select;
}

subscribe( () => hydrateFields() );
