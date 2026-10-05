import { useEffect, useRef, useState } from '@wordpress/element';

/**
 * An inline text field for naming a folder. Enter or leaving the field saves,
 * Escape cancels.
 *
 * @param {Object}   props
 * @param {string}   props.label    Accessible label.
 * @param {string}   props.initial  Starting value.
 * @param {Function} props.onSubmit Called with the trimmed name.
 * @param {Function} props.onCancel Called when nothing is saved.
 */
export default function NameInput( {
	label,
	initial = '',
	onSubmit,
	onCancel,
} ) {
	const [ value, setValue ] = useState( initial );
	const input = useRef();
	const done = useRef( false );

	useEffect( () => {
		input.current?.focus();
		input.current?.select();
	}, [] );

	const finish = ( save ) => {
		if ( done.current ) {
			return;
		}
		done.current = true;

		const name = value.trim();
		if ( save && name && name !== initial ) {
			onSubmit( name );
		} else {
			onCancel();
		}
	};

	return (
		<input
			ref={ input }
			type="text"
			className="jcore-kirjasto-folders__input"
			aria-label={ label }
			value={ value }
			onChange={ ( event ) => setValue( event.target.value ) }
			onBlur={ () => finish( true ) }
			onKeyDown={ ( event ) => {
				if ( 'Enter' === event.key ) {
					event.preventDefault();
					finish( true );
				} else if ( 'Escape' === event.key ) {
					// Keeps the media modal from closing.
					event.preventDefault();
					event.stopPropagation();
					finish( false );
				}
			} }
		/>
	);
}
