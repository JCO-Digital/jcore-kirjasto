import { check } from './check';
import { ask } from './dialog';

/**
 * Checks the files added to a plupload uploader before they upload: the media
 * modal's and the Media screen's (wp.Uploader), and Media > Add New's.
 *
 * plupload queues files before it announces them, and an upload in progress
 * goes on to every queued file. So a new batch is taken out of the queue
 * while it is checked, and the files to upload are added again afterwards,
 * passing the check this time.
 */

// Native files that were checked and may upload.
const approved = new WeakSet();

/**
 * Selects attachments in the open media modal, in place of uploading.
 *
 * @param {number[]} ids Attachment IDs.
 * @return {Promise<boolean>} Whether they could be selected.
 */
async function selectInModal( ids ) {
	const frame = window.wp?.media?.frame;
	const state = frame?.state?.();
	const selection = state?.get?.( 'selection' );

	if ( ! selection || ! ids.length ) {
		return false;
	}

	const attachments = ids.map( ( id ) => window.wp.media.attachment( id ) );
	await Promise.all(
		attachments.map( ( attachment ) => attachment.fetch() )
	);

	if ( selection.multiple ) {
		selection.add( attachments );
	} else {
		selection.reset( attachments.slice( -1 ) );
	}

	frame.content.mode( 'browse' );

	return true;
}

/**
 * Whether files can be swapped for existing ones here: in a media modal that
 * picks attachments, not on the Media screen.
 *
 * @return {boolean} Whether the open modal has a selection to add to.
 */
function canSelect() {
	const media = window.wp?.media;
	const frame = media?.frame;

	if ( ! frame || ! frame.$el?.is( ':visible' ) ) {
		return false;
	}

	if (
		media.view.MediaFrame.Manage &&
		frame instanceof media.view.MediaFrame.Manage
	) {
		return false;
	}

	return !! frame.state?.()?.get?.( 'selection' );
}

async function review( up, natives, inModal ) {
	let matches;
	try {
		matches = await check( natives );
	} catch {
		matches = natives.map( () => [] );
	}

	const flagged = natives
		.map( ( file, index ) => ( {
			file,
			index,
			matches: matches[ index ],
		} ) )
		.filter( ( item ) => item.matches.length );

	const keep = new Set( natives );
	const select = [];

	if ( flagged.length ) {
		const selectable = inModal && canSelect();
		const choices = await ask(
			flagged.map( ( item ) => ( {
				name: item.file.name,
				matches: item.matches,
			} ) ),
			selectable
		);

		flagged.forEach( ( item, i ) => {
			if ( 'upload' !== choices[ i ] ) {
				keep.delete( item.file );

				if ( 'existing' === choices[ i ] ) {
					select.push( item.matches[ 0 ].id );
				}
			}
		} );
	}

	const files = natives.filter( ( file ) => keep.has( file ) );
	files.forEach( ( file ) => approved.add( file ) );

	if ( files.length ) {
		up.addFile( files );
	}

	if ( select.length ) {
		await selectInModal( select );
	}
}

/**
 * Hooks an uploader.
 *
 * @param {Object}  up      plupload uploader.
 * @param {boolean} inModal Whether it uploads into a media modal or grid.
 */
export function guard( up, inModal ) {
	up.bind(
		'FilesAdded',
		( uploader, files ) => {
			const natives = files.map( ( file ) => file.getNative() );

			if ( natives.every( ( file ) => file && approved.has( file ) ) ) {
				natives.forEach( ( file ) => approved.delete( file ) );
				return;
			}

			files.forEach( ( file ) => uploader.removeFile( file ) );
			review( uploader, natives.filter( Boolean ), inModal );

			// Stop the uploader from queueing and starting the batch.
			return false;
		},
		null,
		100
	);
}
