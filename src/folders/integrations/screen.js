import { __ } from '@wordpress/i18n';
import {
	createRoot,
	useCallback,
	useEffect,
	useState,
} from '@wordpress/element';
import { Button } from '@wordpress/components';
import { folder as folderIcon } from '../icons';
import { read, write } from '../storage';
import FolderPanel from '../components/FolderPanel';
import { KEY, settings } from '../settings';
import { libraryFolder, trackLibrary } from './libraries';

/**
 * The folder list beside the Media screen, in grid and in list mode.
 *
 * The URL holds the folder in both modes, so a reload, the back button and
 * switching between the modes keep it. List mode is rendered by PHP, so
 * choosing a folder there loads the page for it.
 */
let gridFrame = null;

function urlFor( folder, href = window.location.href ) {
	const url = new URL( href );
	url.searchParams.delete( 'paged' );

	if ( null === folder ) {
		url.searchParams.delete( KEY );
	} else {
		url.searchParams.set( KEY, String( folder ) );
	}

	return url.toString();
}

const HIDDEN = 'folders-hidden';

function ScreenPanel() {
	const [ selected, setSelected ] = useState( settings.current );
	const [ hidden, setHidden ] = useState( () => read( HIDDEN, false ) );

	// The list can be put away when the library needs the room.
	useEffect( () => {
		write( HIDDEN, hidden );
		document.body.classList.toggle(
			'jcore-kirjasto-folders-hidden',
			hidden
		);
	}, [ hidden ] );

	const select = useCallback( ( folder ) => {
		setSelected( folder );

		if ( 'list' === settings.screen ) {
			window.location.assign( urlFor( folder ) );
			return;
		}

		window.history.replaceState(
			window.history.state,
			'',
			urlFor( folder )
		);
		document.querySelectorAll( '.view-switch a' ).forEach( ( link ) => {
			link.href = urlFor( folder, link.href );
		} );

		gridFrame
			?.state( 'library' )
			?.get( 'library' )
			?.props.set( KEY, folder );
	}, [] );

	if ( hidden ) {
		return (
			<Button
				className="jcore-kirjasto-folders-show"
				icon={ folderIcon }
				label={ __( 'Show folders', 'jcore-kirjasto' ) }
				onClick={ () => setHidden( false ) }
			/>
		);
	}

	return (
		<FolderPanel
			selected={ selected }
			onSelect={ select }
			onHide={ () => setHidden( true ) }
		/>
	);
}

/**
 * Opens the grid in the folder from the URL. Runs before the grid starts,
 * so it hooks into the settings core starts the grid with.
 *
 * @param {Object} $ jQuery.
 */
export function prepareGrid( $ ) {
	// Registered before core's own ready handler, so it runs first.
	$( () => {
		const grid = window._wpMediaGridSettings;
		if ( grid && null !== settings.current ) {
			grid.queryVars = { ...grid.queryVars, [ KEY ]: settings.current };
		}
	} );

	$( document ).on( 'wp-media-grid-ready', ( event, frame ) => {
		gridFrame = frame;

		const library = frame.state( 'library' )?.get( 'library' );
		if ( ! library ) {
			return;
		}

		trackLibrary( library, frame );

		if ( libraryFolder( library ) !== settings.current ) {
			library.props.set( KEY, settings.current );
		}
	} );
}

export function mountScreen() {
	const body = document.getElementById( 'wpbody-content' );
	if ( ! body ) {
		return;
	}

	const sidebar = document.createElement( 'div' );
	sidebar.className = 'jcore-kirjasto-folders-sidebar';
	body.prepend( sidebar );
	document.body.classList.add( 'jcore-kirjasto-folders-screen' );

	createRoot( sidebar ).render( <ScreenPanel /> );
}
