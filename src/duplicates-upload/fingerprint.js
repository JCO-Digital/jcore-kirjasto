/**
 * The fingerprint of a file about to be uploaded, computed the way PHP's
 * Fingerprint class computes it for the files in the library: a SHA-1 of the
 * bytes, and for images a difference hash of how they look.
 */

// Files larger than this are not read into memory to be hashed.
const MAX_HASHED = 128 * 1024 * 1024;

const IMAGE_TYPES = [
	'image/jpeg',
	'image/png',
	'image/gif',
	'image/webp',
	'image/avif',
	'image/bmp',
];

function toHex( buffer ) {
	return Array.from( new Uint8Array( buffer ), ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
}

function canvas( width, height ) {
	const element = document.createElement( 'canvas' );
	element.width = width;
	element.height = height;

	const context = element.getContext( '2d', { willReadFrequently: true } );
	context.imageSmoothingEnabled = true;
	context.imageSmoothingQuality = 'high';

	return [ element, context ];
}

/**
 * Shrinks an image to 9 by 8 pixels in halving steps, which averages the
 * pixels much like the server's resampling does, and compares each pixel
 * with its right neighbour.
 *
 * @param {ImageBitmap} bitmap The image.
 * @return {string} 16 hex digits.
 */
function dhash( bitmap ) {
	let source = bitmap;
	let width = bitmap.width;
	let height = bitmap.height;

	// Transparent areas are laid on white, as on the server.
	let [ step, context ] = canvas( width, height );
	context.fillStyle = '#fff';
	context.fillRect( 0, 0, width, height );
	context.drawImage( source, 0, 0 );
	source = step;

	while ( width > 18 || height > 16 ) {
		width = Math.max( 9, Math.round( width / 2 ) );
		height = Math.max( 8, Math.round( height / 2 ) );
		[ step, context ] = canvas( width, height );
		context.drawImage( source, 0, 0, width, height );
		source = step;
	}

	[ step, context ] = canvas( 9, 8 );
	context.drawImage( source, 0, 0, 9, 8 );

	const { data } = context.getImageData( 0, 0, 9, 8 );
	const grey = [];
	for ( let i = 0; i < data.length; i += 4 ) {
		grey.push(
			Math.round(
				0.299 * data[ i ] +
					0.587 * data[ i + 1 ] +
					0.114 * data[ i + 2 ]
			)
		);
	}

	let hex = '';
	for ( let y = 0; y < 8; y++ ) {
		let bits = 0;
		for ( let x = 0; x < 8; x++ ) {
			bits =
				bits * 2 +
				( grey[ y * 9 + x ] < grey[ y * 9 + x + 1 ] ? 1 : 0 );
		}
		hex += bits.toString( 16 ).padStart( 2, '0' );
	}

	return hex;
}

/**
 * Fingerprints a file.
 *
 * @param {File} file The file.
 * @return {Promise<Object|null>} `sha1`, `phash`, `width` and `height`, or
 *                                null when the file cannot be fingerprinted.
 */
export async function fingerprint( file ) {
	if ( ! window.crypto?.subtle || ! file || file.size > MAX_HASHED ) {
		return null;
	}

	const sha1 = toHex(
		await window.crypto.subtle.digest( 'SHA-1', await file.arrayBuffer() )
	);
	const result = { sha1, phash: '', width: 0, height: 0 };

	if ( IMAGE_TYPES.includes( file.type ) && window.createImageBitmap ) {
		try {
			const bitmap = await window.createImageBitmap( file );
			result.width = bitmap.width;
			result.height = bitmap.height;
			result.phash = dhash( bitmap );
			bitmap.close();
		} catch {
			// A format the browser cannot decode: the SHA-1 still counts.
		}
	}

	return result;
}
