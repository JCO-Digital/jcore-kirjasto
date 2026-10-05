import { _n, sprintf } from '@wordpress/i18n';

/**
 * What is being dragged: `{ type: 'attachments', ids }` or
 * `{ type: 'folder', id }`. Drop targets read it during `dragover`, when the
 * data transfer itself cannot be read yet.
 */
let current = null;

const listeners = new Set();

export const getDrag = () => current;

/**
 * Registers a callback for drags starting and ending, with the drag or null.
 *
 * @param {Function} listener Callback.
 * @return {Function} Unsubscribes.
 */
export function onDragChange( listener ) {
	listeners.add( listener );

	return () => listeners.delete( listener );
}

// Deferred: changing the page while a drag starts can cancel it.
const notify = () =>
	setTimeout( () =>
		listeners.forEach( ( listener ) => listener( current ) )
	);

/**
 * Starts dragging files or a folder.
 *
 * @param {DragEvent} event The native dragstart event.
 * @param {Object}    drag  What is dragged.
 */
export function startDrag( event, drag ) {
	current = drag;
	notify();

	event.dataTransfer.effectAllowed = 'move';
	// Firefox only starts a drag that carries data.
	event.dataTransfer.setData( 'text/plain', '' );

	if ( 'attachments' === drag.type ) {
		setDragImage( event, drag.ids.length );
	}

	document.addEventListener(
		'dragend',
		() => {
			current = null;
			notify();
		},
		{ once: true }
	);
}

/**
 * Starts dragging attachments, unless there are none.
 *
 * @param {DragEvent} event The native dragstart event.
 * @param {number[]}  ids   Attachment IDs.
 */
export function startAttachmentDrag( event, ids ) {
	const valid = ids.map( Number ).filter( Boolean );

	if ( valid.length ) {
		startDrag( event, { type: 'attachments', ids: valid } );
	}
}

function setDragImage( event, count ) {
	const image = document.createElement( 'div' );
	image.className = 'jcore-kirjasto-drag-image';
	image.textContent = sprintf(
		/* translators: %d: number of files being dragged. */
		_n( 'Move %d file', 'Move %d files', count, 'jcore-kirjasto' ),
		count
	);

	document.body.appendChild( image );
	event.dataTransfer.setDragImage( image, 16, 16 );
	setTimeout( () => image.remove() );
}
