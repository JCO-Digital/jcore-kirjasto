import domReady from '@wordpress/dom-ready';
import { settings } from './settings';
import { extendMediaViews } from './integrations/media';
import { hydrateFields } from './integrations/field';
import { initList } from './integrations/list';
import { mountScreen, prepareGrid } from './integrations/screen';
import './style.scss';

/**
 * Media folders: the folder list beside the Media screen and inside every
 * media modal, the folder field of the attachment details, dragging files
 * onto folders and uploading into the open folder.
 */
if ( window.wp?.media?.view?.AttachmentsBrowser ) {
	extendMediaViews( window.wp.media );
}

if ( 'grid' === settings.screen && window.jQuery ) {
	prepareGrid( window.jQuery );
}

domReady( () => {
	hydrateFields();

	if ( 'grid' === settings.screen || 'list' === settings.screen ) {
		mountScreen();
	}

	if ( 'list' === settings.screen ) {
		initList();
	}
} );
