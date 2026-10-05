import { __, sprintf } from '@wordpress/i18n';
import { useContext, useEffect, useState } from '@wordpress/element';
import { Button, DropdownMenu } from '@wordpress/components';
import {
	Icon,
	chevronDown,
	chevronRight,
	moreVertical,
} from '@wordpress/icons';
import {
	deleteFolder,
	moveAttachments,
	moveFolder,
	renameFolder,
} from '../store';
import { ancestors } from '../tree';
import { startDrag } from '../drag';
import { settings, formatNumber } from '../settings';
import { folder as folderIcon } from '../icons';
import { FolderContext, classes } from './context';
import NameInput from './NameInput';
import NewFolder from './NewFolder';
import useDrop from './useDrop';

/**
 * Whether a folder may move into another one: not into itself, not into
 * its own subfolders and not where it already is.
 *
 * @param {Array}  folders Folders.
 * @param {number} id      The folder being moved.
 * @param {number} target  Where it would go.
 * @return {boolean} Whether the move is allowed.
 */
function canNest( folders, id, target ) {
	const moving = folders.find( ( folder ) => folder.id === id );

	return (
		!! moving &&
		id !== target &&
		moving.parent !== target &&
		! ancestors( folders, target ).includes( id )
	);
}

/**
 * Asks before deleting a folder, saying where its contents go.
 *
 * @param {Object}   props
 * @param {Object}   props.folder    The folder.
 * @param {Function} props.onConfirm Deletes it.
 * @param {Function} props.onCancel  Keeps it.
 */
function DeleteConfirm( { folder, onConfirm, onCancel } ) {
	const { folders } = useContext( FolderContext );
	const parent = folders.find( ( item ) => item.id === folder.parent );

	const message = parent
		? sprintf(
				/* translators: 1: folder name, 2: name of its parent folder. */
				__(
					'Delete “%1$s”? Its files and subfolders move to “%2$s”.',
					'jcore-kirjasto'
				),
				folder.name,
				parent.name
		  )
		: sprintf(
				/* translators: %s: folder name. */
				__(
					'Delete “%s”? Its subfolders move to the top level and its files are left without a folder.',
					'jcore-kirjasto'
				),
				folder.name
		  );

	return (
		<div className="jcore-kirjasto-folders__confirm" role="alert">
			<p>{ message }</p>
			<div className="jcore-kirjasto-folders__buttons">
				<Button
					variant="primary"
					isDestructive
					size="small"
					onClick={ onConfirm }
				>
					{ __( 'Delete', 'jcore-kirjasto' ) }
				</Button>
				<Button variant="tertiary" size="small" onClick={ onCancel }>
					{ __( 'Cancel', 'jcore-kirjasto' ) }
				</Button>
			</div>
		</div>
	);
}

/**
 * One folder in the list, with its subfolders.
 *
 * @param {Object} props
 * @param {Object} props.folder The folder.
 * @param {number} props.depth  How deep it is nested.
 */
export default function FolderItem( { folder, depth } ) {
	const context = useContext( FolderContext );
	const { folders, children, expanded, selected, creating } = context;
	const [ mode, setMode ] = useState( null );

	const subfolders = children.get( folder.id ) || [];
	const isOpen = expanded.has( folder.id );
	const isSelected = selected === folder.id;

	const drop = useDrop( {
		accepts: ( drag ) =>
			'attachments' === drag.type ||
			canNest( folders, drag.id, folder.id ),
		onDrop: ( drag ) =>
			context.run( () =>
				'attachments' === drag.type
					? moveAttachments( drag.ids, folder.id )
					: moveFolder( drag.id, folder.id )
			),
	} );

	// Opens a closed folder a drag rests on, so its subfolders can be reached.
	const { expand } = context;
	useEffect( () => {
		if ( ! drop.isOver || isOpen || ! subfolders.length ) {
			return undefined;
		}

		const timer = setTimeout( () => expand( [ folder.id ] ), 600 );

		return () => clearTimeout( timer );
	}, [ drop.isOver, isOpen, subfolders.length, expand, folder.id ] );

	const expandLabel = sprintf(
		/* translators: %s: folder name. */
		__( 'Expand %s', 'jcore-kirjasto' ),
		folder.name
	);
	const collapseLabel = sprintf(
		/* translators: %s: folder name. */
		__( 'Collapse %s', 'jcore-kirjasto' ),
		folder.name
	);

	// Menu actions wait for the menu to close and hand focus back first.
	const later = ( action ) => () => setTimeout( action );

	return (
		<li className="jcore-kirjasto-folders__item">
			<div
				className={ classes(
					'jcore-kirjasto-folders__row',
					isSelected && 'is-selected',
					drop.isOver && 'is-drop-target'
				) }
				style={ { '--jcore-kirjasto-depth': depth } }
				draggable={ settings.canManage && ! mode }
				onDragStart={ ( event ) => {
					event.stopPropagation();
					startDrag( event, { type: 'folder', id: folder.id } );
				} }
				{ ...drop.props }
			>
				{ subfolders.length ? (
					<Button
						className="jcore-kirjasto-folders__toggle"
						size="small"
						icon={ isOpen ? chevronDown : chevronRight }
						label={ isOpen ? collapseLabel : expandLabel }
						showTooltip={ false }
						aria-expanded={ isOpen }
						onClick={ () => context.toggle( folder.id ) }
					/>
				) : (
					<span className="jcore-kirjasto-folders__toggle" />
				) }

				{ 'rename' === mode ? (
					<NameInput
						label={ __( 'Folder name', 'jcore-kirjasto' ) }
						initial={ folder.name }
						onSubmit={ ( name ) => {
							setMode( null );
							context.run( () =>
								renameFolder( folder.id, name )
							);
						} }
						onCancel={ () => setMode( null ) }
					/>
				) : (
					<button
						type="button"
						className="jcore-kirjasto-folders__select"
						aria-current={ isSelected ? 'true' : undefined }
						title={ folder.name }
						onClick={ () => context.select( folder.id ) }
					>
						<Icon icon={ folderIcon } size={ 20 } />
						<span className="jcore-kirjasto-folders__name">
							{ folder.name }
						</span>
						<span className="jcore-kirjasto-folders__count">
							{ formatNumber( folder.count ) }
						</span>
					</button>
				) }

				{ settings.canManage && 'rename' !== mode && (
					<DropdownMenu
						className="jcore-kirjasto-folders__actions"
						icon={ moreVertical }
						label={ sprintf(
							/* translators: %s: folder name. */
							__( 'Actions for %s', 'jcore-kirjasto' ),
							folder.name
						) }
						toggleProps={ { size: 'small' } }
						popoverProps={ { placement: 'bottom-end' } }
						controls={ [
							{
								title: __( 'New subfolder', 'jcore-kirjasto' ),
								onClick: later( () => {
									expand( [ folder.id ] );
									context.setCreating( folder.id );
								} ),
							},
							{
								title: __( 'Rename', 'jcore-kirjasto' ),
								onClick: later( () => setMode( 'rename' ) ),
							},
							{
								title: __( 'Delete', 'jcore-kirjasto' ),
								onClick: later( () => setMode( 'delete' ) ),
								isDestructive: true,
							},
						] }
					/>
				) }
			</div>

			{ 'delete' === mode && (
				<DeleteConfirm
					folder={ folder }
					onCancel={ () => setMode( null ) }
					onConfirm={ () => {
						setMode( null );
						context.run( () => deleteFolder( folder.id ) );
					} }
				/>
			) }

			{ ( ( isOpen && subfolders.length > 0 ) ||
				creating === folder.id ) && (
				<ul className="jcore-kirjasto-folders__group">
					{ isOpen &&
						subfolders.map( ( subfolder ) => (
							<FolderItem
								key={ subfolder.id }
								folder={ subfolder }
								depth={ depth + 1 }
							/>
						) ) }
					{ creating === folder.id && (
						<NewFolder parent={ folder.id } depth={ depth + 1 } />
					) }
				</ul>
			) }
		</li>
	);
}
