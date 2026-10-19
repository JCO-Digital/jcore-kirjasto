import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import './style.scss';

/**
 * "Replace file": the button in the attachment details and the list mode row
 * actions. Picking a file uploads it in place of the attachment's file.
 *
 * In the media modal and grid mode the attachment model takes the answer, and
 * the views showing it redraw. The list and the edit screen reload instead.
 * Either way the images shown carry the time of the replacement in their
 * addresses, so the browser does not show the old file from its cache.
 */
const SELECTOR = 'button.jcore-kirjasto-replace';
const PATH = '/jcore-kirjasto/v1/replace/';
const EVENT = 'jcore-kirjasto:replaced';

// Set by PHP, as are the names below: Media_Library::VERSION and VERSION_ARG.
const VERSION = 'jcoreKirjastoVersion';
const VERSION_ARG = 'jcore-kirjasto-v';

/**
 * Adds the time the file was replaced to an address. A replaced file keeps
 * its address, and the browser may keep showing the old file under it, even
 * after an ordinary reload.
 *
 * @param {string} url     The address.
 * @param {number} version When the file was replaced.
 * @return {string} The address with the time.
 */
function versioned( url, version ) {
	if ( ! url ) {
		return url;
	}

	const parsed = new window.URL( url, window.location.href );
	parsed.searchParams.set( VERSION_ARG, version );

	return parsed.href;
}

/**
 * Template data with the time the file was replaced in every image address
 * the views show. Only for showing: the model keeps the real addresses, which
 * are inserted into content and copied with "Copy URL".
 *
 * @param {Object} data    Template data.
 * @param {number} version When the file was replaced.
 * @return {Object} The data for the template.
 */
function versionedData( data, version ) {
	const sizes = {};

	for ( const [ name, size ] of Object.entries( data.sizes || {} ) ) {
		sizes[ name ] = { ...size, url: versioned( size.url, version ) };
	}

	return {
		...data,
		sizes,
		size: data.size && {
			...data.size,
			url: versioned( data.size.url, version ),
		},
		image: data.image && {
			...data.image,
			src: versioned( data.image.src, version ),
		},
	};
}

const Attachment = window.wp?.media?.view?.Attachment;

// Every attachment view, the thumbnails and the details alike, passes its
// data through its template, and initializes through here. Wrapping render()
// instead would miss the views whose render() was wrapped before this script.
if ( Attachment ) {
	const { initialize } = Attachment.prototype;

	Attachment.prototype.initialize = function () {
		const { template } = this;

		this.template = ( data ) => {
			const version = this.model?.get( VERSION );

			return template.call(
				this,
				version ? versionedData( data, version ) : data
			);
		};

		return initialize.apply( this, arguments );
	};
}

/**
 * Core's attachment details only redraw for an upload's progress, not when
 * the attachment changes; they redraw when its file was replaced.
 */
const Details = Attachment?.Details;

if ( Details ) {
	const { initialize } = Details.prototype;

	Details.prototype.initialize = function () {
		initialize.apply( this, arguments );
		this.listenTo( this.model, EVENT, this.render );
	};
}

/**
 * Shows a message beside every "Replace file" button of the attachment: the
 * details may have been redrawn with new buttons since the upload started.
 *
 * @param {number}  id      Attachment ID.
 * @param {string}  message The message, or an empty string to clear it.
 * @param {boolean} isError Whether the message is an error.
 */
function setStatus( id, message, isError = false ) {
	const statuses = [
		...document.querySelectorAll( `${ SELECTOR }[data-id="${ id }"]` ),
	]
		.map( ( button ) =>
			button.parentElement?.querySelector(
				'.jcore-kirjasto-replace-status'
			)
		)
		.filter( Boolean );

	for ( const status of statuses ) {
		status.textContent = message;
		status.classList.toggle( 'is-error', isError );
	}

	// The list mode row actions have no room for a message.
	if ( ! statuses.length && isError ) {
		window.alert( message ); // eslint-disable-line no-alert
	}
}

async function replace( button, file ) {
	const id = Number( button.dataset.id );
	const label = button.textContent;
	const body = new window.FormData();
	body.append( 'file', file );

	button.disabled = true;
	button.textContent = __( 'Replacing…', 'jcore-kirjasto' );
	setStatus( id, '' );

	try {
		const attachment = await apiFetch( {
			path: PATH + id,
			method: 'POST',
			body,
		} );

		const model = window.wp?.media?.attachment?.( id );

		// The grid's attachment details are a modal, but not a media frame.
		if ( ! button.closest( '.media-modal, .media-frame' ) || ! model ) {
			window.location.reload();
			return;
		}

		model.set( model.parse( attachment ) );
		model.trigger( EVENT, model );
		setStatus( id, __( 'The file was replaced.', 'jcore-kirjasto' ) );
	} catch ( error ) {
		setStatus(
			id,
			error?.message ||
				__( 'The file could not be replaced.', 'jcore-kirjasto' ),
			true
		);
	}

	// Unless the details were redrawn with a new button by now.
	button.disabled = false;
	button.textContent = label;
}

document.addEventListener( 'click', ( event ) => {
	const button = event.target.closest?.( SELECTOR );

	if ( ! button || button.disabled ) {
		return;
	}

	event.preventDefault();

	// Outside the details form: the modal saves that form on every change.
	const input = document.createElement( 'input' );
	input.type = 'file';
	input.accept = button.dataset.accept || '';
	input.addEventListener( 'change', () => {
		if ( input.files?.[ 0 ] ) {
			replace( button, input.files[ 0 ] );
		}
	} );
	input.click();
} );
