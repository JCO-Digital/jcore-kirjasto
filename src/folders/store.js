import apiFetch from '@wordpress/api-fetch';
import { useSyncExternalStore } from '@wordpress/element';
import { settings } from './settings';

/**
 * The folder tree, shared by every folder list on the page.
 *
 * Every route that changes folders answers with the whole tree, which
 * replaces the state, so all lists and folder fields redraw at once.
 */
const PATH = '/jcore-kirjasto/v1/folders';

let state = { ...settings.tree };

const listeners = new Set();
const moveListeners = new Set();

function setTree( { folders, total, unassigned } ) {
	state = { folders, total, unassigned };
	listeners.forEach( ( listener ) => listener() );
}

export const getState = () => state;

export function subscribe( listener ) {
	listeners.add( listener );

	return () => listeners.delete( listener );
}

export const useFolders = () => useSyncExternalStore( subscribe, getState );

/**
 * Registers a callback for files that moved to another folder, so libraries
 * showing a folder can reload.
 *
 * @param {Function} listener Called with the moved IDs and the folder.
 * @return {Function} Unsubscribes.
 */
export function onMoved( listener ) {
	moveListeners.add( listener );

	return () => moveListeners.delete( listener );
}

export function notifyMoved( ids, folder ) {
	moveListeners.forEach( ( listener ) => listener( ids, folder ) );
}

async function send( path, method, data ) {
	const response = await apiFetch( { path: PATH + path, method, data } );
	setTree( response );

	return response;
}

export const refresh = async () => setTree( await apiFetch( { path: PATH } ) );

export const createFolder = ( name, parent ) =>
	send( '', 'POST', { name, parent } ).then( ( response ) => response.id );

export const renameFolder = ( id, name ) =>
	send( `/${ id }`, 'PATCH', { name } );

export const moveFolder = ( id, parent ) =>
	send( `/${ id }`, 'PATCH', { parent } );

export const deleteFolder = ( id ) => send( `/${ id }`, 'DELETE' );

export async function moveAttachments( ids, folder ) {
	const response = await send( '/move', 'POST', {
		attachments: ids,
		folder,
	} );
	notifyMoved( response.moved, folder );

	return response;
}

export const importStep = ( source, offset ) =>
	send( '/import', 'POST', { source, offset } );
