import { __ } from '@wordpress/i18n';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';
import { Button, Notice } from '@wordpress/components';
import {
	Icon,
	arrowUp,
	chevronLeft,
	file,
	media,
	plus,
} from '@wordpress/icons';
import { moveAttachments, moveFolder, useFolders } from '../store';
import { ancestors, childrenOf } from '../tree';
import { onDragChange } from '../drag';
import { settings, formatNumber } from '../settings';
import { read, write } from '../storage';
import { FolderContext, classes } from './context';
import FolderItem from './FolderItem';
import NewFolder from './NewFolder';
import ImportPrompt from './ImportPrompt';
import useDrop from './useDrop';

const EXPANDED = 'folders-expanded';

/**
 * "All files" or "No folder", above the folders.
 *
 * @param {Object}   props
 * @param {Object}   props.icon       Icon.
 * @param {string}   props.label      Label.
 * @param {number}   props.count      Number of files.
 * @param {boolean}  props.isSelected Whether the library shows it.
 * @param {Function} props.onClick    Shows it.
 * @param {Object}   [props.drop]     Drop target, from useDrop().
 */
function ViewItem( { icon, label, count, isSelected, onClick, drop } ) {
	return (
		<li className="jcore-kirjasto-folders__item">
			<div
				className={ classes(
					'jcore-kirjasto-folders__row',
					isSelected && 'is-selected',
					drop?.isOver && 'is-drop-target'
				) }
				{ ...drop?.props }
			>
				<span className="jcore-kirjasto-folders__toggle" />
				<button
					type="button"
					className="jcore-kirjasto-folders__select"
					aria-current={ isSelected ? 'true' : undefined }
					onClick={ onClick }
				>
					<Icon icon={ icon } size={ 20 } />
					<span className="jcore-kirjasto-folders__name">
						{ label }
					</span>
					<span className="jcore-kirjasto-folders__count">
						{ formatNumber( count ) }
					</span>
				</button>
			</div>
		</li>
	);
}

/**
 * The folder list: all files, files without a folder and the folder tree.
 * Files and folders can be dropped on it to move them.
 *
 * @param {Object}   props
 * @param {?number}  props.selected What the library shows: a folder ID, 0 for
 *                                  files without a folder, null for all files.
 * @param {Function} props.onSelect Called with the folder to show.
 * @param {Function} [props.onHide] Hides the list, where it can be hidden.
 */
export default function FolderPanel( { selected, onSelect, onHide } ) {
	const { folders, total, unassigned } = useFolders();
	const children = useMemo( () => childrenOf( folders ), [ folders ] );
	const [ expanded, setExpanded ] = useState(
		() => new Set( read( EXPANDED, [] ) )
	);
	const [ creating, setCreating ] = useState( null );
	const [ error, setError ] = useState( '' );
	const [ drag, setDrag ] = useState( null );

	useEffect( () => onDragChange( setDrag ), [] );

	const expand = useCallback(
		( ids ) =>
			setExpanded( ( current ) =>
				ids.every( ( id ) => current.has( id ) )
					? current
					: new Set( [ ...current, ...ids ] )
			),
		[]
	);

	const toggle = ( id ) =>
		setExpanded( ( current ) => {
			const next = new Set( current );
			if ( ! next.delete( id ) ) {
				next.add( id );
			}

			return next;
		} );

	useEffect( () => write( EXPANDED, [ ...expanded ] ), [ expanded ] );

	// The selected folder is always in view, and gone once it is deleted.
	useEffect( () => {
		if ( selected > 0 ) {
			if ( folders.some( ( folder ) => folder.id === selected ) ) {
				expand( ancestors( folders, selected ) );
			} else {
				onSelect( null );
			}
		}
	}, [ selected, folders, expand, onSelect ] );

	const run = useCallback( async ( action ) => {
		setError( '' );

		try {
			return await action();
		} catch ( caught ) {
			setError(
				caught?.message ||
					__( 'The folders could not be saved.', 'jcore-kirjasto' )
			);

			return undefined;
		}
	}, [] );

	const noFolder = useDrop( {
		accepts: ( dragged ) => 'attachments' === dragged.type,
		onDrop: ( dragged ) => run( () => moveAttachments( dragged.ids, 0 ) ),
	} );

	// A subfolder being dragged can go back to the top level.
	const isSubfolder = ( dragged ) =>
		'folder' === dragged?.type &&
		0 !== folders.find( ( folder ) => folder.id === dragged.id )?.parent;

	const topLevel = useDrop( {
		accepts: isSubfolder,
		onDrop: ( dragged ) => run( () => moveFolder( dragged.id, 0 ) ),
	} );

	const startCreating = () => {
		const parent = selected > 0 ? selected : 0;
		if ( parent ) {
			expand( [ parent ] );
		}
		setCreating( parent );
	};

	const context = {
		folders,
		children,
		expanded,
		expand,
		toggle,
		selected,
		select: onSelect,
		creating,
		setCreating,
		run,
	};

	return (
		<FolderContext.Provider value={ context }>
			<div className="jcore-kirjasto-folders">
				<div className="jcore-kirjasto-folders__header">
					<h2 className="jcore-kirjasto-folders__title">
						{ __( 'Folders', 'jcore-kirjasto' ) }
					</h2>
					{ settings.canManage && (
						<Button
							size="small"
							icon={ plus }
							label={
								selected > 0
									? __( 'New subfolder', 'jcore-kirjasto' )
									: __( 'New folder', 'jcore-kirjasto' )
							}
							onClick={ startCreating }
						/>
					) }
					{ onHide && (
						<Button
							size="small"
							icon={ chevronLeft }
							label={ __( 'Hide folders', 'jcore-kirjasto' ) }
							onClick={ onHide }
						/>
					) }
				</div>

				{ error && (
					<Notice status="error" onRemove={ () => setError( '' ) }>
						{ error }
					</Notice>
				) }

				<ul className="jcore-kirjasto-folders__views">
					<ViewItem
						icon={ media }
						label={ __( 'All files', 'jcore-kirjasto' ) }
						count={ total }
						isSelected={ null === selected }
						onClick={ () => onSelect( null ) }
					/>
					<ViewItem
						icon={ file }
						label={ __( 'No folder', 'jcore-kirjasto' ) }
						count={ unassigned }
						isSelected={ 0 === selected }
						onClick={ () => onSelect( 0 ) }
						drop={ noFolder }
					/>
				</ul>

				<ul
					className="jcore-kirjasto-folders__tree"
					aria-label={ __( 'Folders', 'jcore-kirjasto' ) }
				>
					{ isSubfolder( drag ) && (
						<li
							className={ classes(
								'jcore-kirjasto-folders__top-level',
								topLevel.isOver && 'is-drop-target'
							) }
							{ ...topLevel.props }
						>
							<Icon icon={ arrowUp } size={ 20 } />
							{ __( 'Move to the top level', 'jcore-kirjasto' ) }
						</li>
					) }
					{ ( children.get( 0 ) || [] ).map( ( folder ) => (
						<FolderItem
							key={ folder.id }
							folder={ folder }
							depth={ 0 }
						/>
					) ) }
					{ 0 === creating && <NewFolder parent={ 0 } depth={ 0 } /> }
				</ul>

				{ ! folders.length && null === creating && (
					<p className="jcore-kirjasto-folders__empty">
						{ settings.canManage
							? __(
									'No folders yet. Create one with the + button, then drag files onto it.',
									'jcore-kirjasto'
							  )
							: __( 'No folders yet.', 'jcore-kirjasto' ) }
					</p>
				) }

				{ settings.canManage && <ImportPrompt /> }
			</div>
		</FolderContext.Provider>
	);
}
