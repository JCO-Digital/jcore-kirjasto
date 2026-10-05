import { createRoot, useCallback, useState } from '@wordpress/element';
import FolderPanel from '../components/FolderPanel';
import { KEY, settings } from '../settings';
import { getState, notifyMoved, refresh } from '../store';
import { startAttachmentDrag } from '../drag';
import { read, write } from '../storage';
import { hydrateFields } from './field';
import { libraryFolder, trackLibrary } from './libraries';

const MODAL_FOLDER = 'modal-folder';

/**
 * The folder list inside a media modal. The folder it shows last is
 * remembered for the next modal.
 *
 * @param {Object} props
 * @param {Object} props.collection The library's attachments collection.
 */
function ModalPanel( { collection } ) {
	const [ selected, setSelected ] = useState( () =>
		libraryFolder( collection )
	);

	const select = useCallback(
		( value ) => {
			setSelected( value );
			write( MODAL_FOLDER, value );
			collection.props.set( KEY, value );
		},
		[ collection ]
	);

	return <FolderPanel selected={ selected } onSelect={ select } />;
}

/**
 * The remembered modal folder, if it still exists.
 *
 * @return {?number} Folder ID, 0 or null.
 */
function rememberedFolder() {
	const value = read( MODAL_FOLDER, null );

	if (
		0 === value ||
		getState().folders.some( ( folder ) => folder.id === value )
	) {
		return value;
	}

	return null;
}

/**
 * Hooks the folders into the media views: the folder list in modals,
 * dragging attachments, uploading into the open folder and the folder field.
 *
 * @param {Object} media `wp.media`.
 */
export function extendMediaViews( media ) {
	const isGrid = ( controller ) =>
		!! media.view.MediaFrame?.Manage &&
		controller instanceof media.view.MediaFrame.Manage;

	const FoldersView = media.View.extend( {
		className: 'jcore-kirjasto-folders-modal',

		initialize() {
			this.untrack = trackLibrary( this.collection, this.controller );
		},

		render() {
			if ( ! this.root ) {
				this.root = createRoot( this.el );
				this.root.render(
					<ModalPanel collection={ this.collection } />
				);
			}

			return this;
		},

		remove() {
			this.root?.unmount();
			this.root = null;
			this.untrack();

			return media.View.prototype.remove.apply( this, arguments );
		},
	} );

	const AttachmentsBrowser = media.view.AttachmentsBrowser;

	media.view.AttachmentsBrowser = AttachmentsBrowser.extend( {
		initialize() {
			AttachmentsBrowser.prototype.initialize.apply( this, arguments );

			this.$el.on( 'dragstart', 'li.attachment', ( event ) =>
				this.jcoreKirjastoDragStart( event )
			);

			// The Media screen has its own folder list; a gallery being
			// edited is not a query, so has no folders to show.
			if (
				! this.collection?.props?.get( 'query' ) ||
				isGrid( this.controller )
			) {
				return;
			}

			if ( undefined === this.collection.props.get( KEY ) ) {
				const folder = rememberedFolder();
				if ( null !== folder ) {
					this.collection.props.set( KEY, folder );
				}
			}

			this.$el.addClass( 'has-jcore-kirjasto-folders' );
			this.views.add(
				new FoldersView( {
					controller: this.controller,
					collection: this.collection,
				} ).render()
			);
		},

		// Drags the selection when the dragged attachment is part of it.
		jcoreKirjastoDragStart( event ) {
			const id = Number( event.currentTarget.dataset.id );
			const selection = this.options.selection;
			const ids =
				selection?.get( id ) && selection.length > 1
					? selection.pluck( 'id' )
					: [ id ];

			startAttachmentDrag( event.originalEvent, ids );
		},
	} );

	const Library = media.view.Attachment.Library;
	const renderAttachment = Library.prototype.render;

	Library.prototype.render = function () {
		const view = renderAttachment.apply( this, arguments );
		this.el.draggable = true;

		return view;
	};

	// Uploads go into the folder the library shows.
	const UploaderWindow = media.view.UploaderWindow;
	const uploaderReady = UploaderWindow.prototype.ready;

	UploaderWindow.prototype.ready = function () {
		const isNew = ! this.uploader;
		uploaderReady.apply( this, arguments );

		const plupload = isNew && this.uploader?.uploader;
		if ( ! plupload ) {
			return;
		}

		const controller = this.controller;
		plupload.bind( 'BeforeUpload', ( uploader ) => {
			const folder = libraryFolder(
				controller.state?.()?.get?.( 'library' )
			);
			uploader.settings.multipart_params[ KEY ] =
				folder > 0 ? folder : '';
		} );
	};

	window.wp?.Uploader?.queue?.on( 'reset', () => refresh() );

	// Core shows new uploads only in queries it can match them against.
	// Uploads go into the folder the library shows, so a folder query
	// matches them too.
	const Query = media.model.Query;
	const initializeQuery = Query.prototype.initialize;
	const matchable = [
		KEY,
		's',
		'order',
		'orderby',
		'posts_per_page',
		'post_mime_type',
		'post_parent',
		'author',
	];

	Query.prototype.initialize = function () {
		initializeQuery.apply( this, arguments );

		const keys = Object.keys( this.args || {} );
		if (
			window.wp?.Uploader?.queue &&
			keys.includes( KEY ) &&
			keys.every( ( key ) => matchable.includes( key ) )
		) {
			this.observe( window.wp.Uploader.queue );
		}
	};

	// The folder field: options from the tree, and a moved file reloads
	// the libraries showing folders.
	const AttachmentCompat = media.view.AttachmentCompat;
	const renderCompat = AttachmentCompat.prototype.render;

	AttachmentCompat.prototype.render = function () {
		const view = renderCompat.apply( this, arguments );
		hydrateFields( this.el );

		return view;
	};

	const Attachment = media.model.Attachment;
	const saveCompat = Attachment.prototype.saveCompat;

	Attachment.prototype.saveCompat = function ( data, ...rest ) {
		const name = `attachments[${ this.id }][${ settings.field }]`;
		const field = document.getElementById(
			`attachments-${ this.id }-${ settings.field }`
		);
		const moved =
			undefined !== data?.[ name ] &&
			String( data[ name ] ) !== String( field?.dataset.folder );

		const request = saveCompat.call( this, data, ...rest );

		if ( moved ) {
			request.done( () => {
				// The compat form is not redrawn after a save.
				if ( field ) {
					field.dataset.folder = String( data[ name ] );
				}
				refresh();
				notifyMoved( [ this.id ], Number( data[ name ] ) );
			} );
		}

		return request;
	};
}
