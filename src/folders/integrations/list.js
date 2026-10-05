import { __ } from '@wordpress/i18n';
import { KEY, settings } from '../settings';
import { onMoved } from '../store';
import { startAttachmentDrag } from '../drag';
import { createPicker } from './field';

/**
 * List mode: rows can be dragged onto folders, and "Move to folder" in the
 * bulk actions gets a folder picker.
 */
export function initList() {
	const rows = document.getElementById( 'the-list' );
	if ( ! rows ) {
		return;
	}

	rows.querySelectorAll( 'tr[id^="post-"]' ).forEach( ( row ) => {
		row.draggable = true;
	} );

	// Drags every checked row when the dragged row is checked.
	rows.addEventListener( 'dragstart', ( event ) => {
		const row = event.target.closest?.( 'tr[id^="post-"]' );
		if ( ! row ) {
			return;
		}

		const id = Number( row.id.slice( 'post-'.length ) );
		const checked = [
			...rows.querySelectorAll( 'input[name="media[]"]:checked' ),
		].map( ( input ) => Number( input.value ) );

		startAttachmentDrag( event, checked.includes( id ) ? checked : [ id ] );
	} );

	// The page lists one folder: files moved out of it should go.
	onMoved( () => {
		if ( null !== settings.current ) {
			window.location.reload();
		}
	} );

	addBulkPickers();

	// Searching and filtering stay in the folder.
	const form = document.getElementById( 'posts-filter' );
	if ( form && null !== settings.current ) {
		const input = document.createElement( 'input' );
		input.type = 'hidden';
		input.name = KEY;
		input.value = String( settings.current );
		form.appendChild( input );
	}
}

/**
 * Adds a folder picker beside each bulk action dropdown, shown while
 * "Move to folder" is chosen. Like core's `action` and `action2`, the
 * bottom one has a `2` at the end of its name.
 */
function addBulkPickers() {
	const pickers = [
		[ 'bulk-action-selector-top', `${ settings.bulkAction }_to` ],
		[ 'bulk-action-selector-bottom', `${ settings.bulkAction }_to2` ],
	];

	for ( const [ id, name ] of pickers ) {
		const action = document.getElementById( id );
		if ( ! action ) {
			continue;
		}

		const picker = createPicker(
			name,
			__( 'Folder to move the files to', 'jcore-kirjasto' )
		);
		picker.classList.add( 'jcore-kirjasto-bulk-folder' );
		action.after( picker );

		const update = () => {
			const isMove = settings.bulkAction === action.value;
			picker.hidden = ! isMove;
			picker.disabled = ! isMove;
		};

		action.addEventListener( 'change', update );
		update();
	}
}
