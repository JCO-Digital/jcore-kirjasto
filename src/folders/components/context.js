import { createContext } from '@wordpress/element';

/**
 * Shared by the rows of one folder list: the tree, which folders are open,
 * the selection and the folder being created.
 */
export const FolderContext = createContext( null );

export const classes = ( ...names ) => names.filter( Boolean ).join( ' ' );
