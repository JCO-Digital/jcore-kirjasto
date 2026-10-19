import { guard } from './plupload';
import { guardEditor } from './editor';
import './style.scss';

/**
 * Warns before uploading a file that is already in the media library, with
 * the choice of using the existing file instead.
 */
const Uploader = window.wp?.Uploader;

// The media modal and the Media screen: every wp.Uploader, as it starts.
if ( Uploader ) {
	const { init } = Uploader.prototype;

	Uploader.prototype.init = function () {
		guard( this.uploader, true );

		return init.apply( this, arguments );
	};
}

// Media > Add New creates its uploader when the document is ready.
window.jQuery?.( () => {
	if ( window.uploader?.bind && window.wpUploaderInit ) {
		guard( window.uploader, false );
	}
} );

// The block editor, which uploads through the REST API.
guardEditor();
