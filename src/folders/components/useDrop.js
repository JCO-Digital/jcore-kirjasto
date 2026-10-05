import { useState } from '@wordpress/element';
import { getDrag } from '../drag';
import { settings } from '../settings';

/**
 * Makes an element a drop target for dragged files, folders or both.
 *
 * @param {Object}   options
 * @param {Function} options.accepts Called with the drag; whether it may drop here.
 * @param {Function} options.onDrop  Called with the drag when it is dropped.
 * @return {{isOver: boolean, props: Object}} Whether a drag hovers, and the
 *                                             handlers to spread on the element.
 */
export default function useDrop( { accepts, onDrop } ) {
	const [ isOver, setIsOver ] = useState( false );

	const allowed = () => {
		const drag = getDrag();
		if ( ! drag || ( 'folder' === drag.type && ! settings.canManage ) ) {
			return null;
		}

		return accepts( drag ) ? drag : null;
	};

	return {
		isOver,
		props: {
			onDragOver( event ) {
				if ( ! allowed() ) {
					return;
				}
				event.preventDefault();
				event.stopPropagation();
				event.dataTransfer.dropEffect = 'move';
				setIsOver( true );
			},
			onDragLeave( event ) {
				if ( ! event.currentTarget.contains( event.relatedTarget ) ) {
					setIsOver( false );
				}
			},
			onDrop( event ) {
				setIsOver( false );

				const drag = allowed();
				if ( drag ) {
					event.preventDefault();
					event.stopPropagation();
					onDrop( drag );
				}
			},
		},
	};
}
