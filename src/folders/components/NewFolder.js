import { __ } from '@wordpress/i18n';
import { useContext } from '@wordpress/element';
import { Icon } from '@wordpress/icons';
import { createFolder } from '../store';
import { folder as folderIcon } from '../icons';
import { FolderContext } from './context';
import NameInput from './NameInput';

/**
 * The row of a folder being created, with its name field.
 *
 * @param {Object} props
 * @param {number} props.parent Parent folder; 0 for the top level.
 * @param {number} props.depth  How deep it is nested.
 */
export default function NewFolder( { parent, depth } ) {
	const context = useContext( FolderContext );

	return (
		<li className="jcore-kirjasto-folders__item">
			<div
				className="jcore-kirjasto-folders__row"
				style={ { '--jcore-kirjasto-depth': depth } }
			>
				<span className="jcore-kirjasto-folders__toggle" />
				<Icon icon={ folderIcon } size={ 20 } />
				<NameInput
					label={ __( 'New folder name', 'jcore-kirjasto' ) }
					onSubmit={ ( name ) => {
						context.setCreating( null );
						context.run( () => createFolder( name, parent ) );
					} }
					onCancel={ () => context.setCreating( null ) }
				/>
			</div>
		</li>
	);
}
