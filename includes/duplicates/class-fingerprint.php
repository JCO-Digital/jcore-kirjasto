<?php
/**
 * Fingerprints of attachment files.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Describes the file of an attachment in a way duplicates can be found by: a
 * hash of its bytes, and for images a perceptual hash of how it looks.
 *
 * The perceptual hash is a difference hash: the image is shrunk to 9 by 8
 * grey pixels, and each of the 64 bits says whether a pixel is darker than
 * its right neighbour. Copies of an image that were resized, recompressed or
 * saved in another format get the same or a nearly same hash; crops and
 * edits do not.
 */
final class Fingerprint {

	/**
	 * Version of the hashing rules. Bumping it hashes every file again.
	 */
	public const VERSION = '1';

	/**
	 * Post meta holding the SHA-1 of a file as it was uploaded.
	 */
	public const UPLOAD_HASH_META = '_jcore_kirjasto_upload_hash';

	/**
	 * Image types hashed perceptually: those the image libraries decode.
	 */
	private const IMAGE_TYPES = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif', 'image/bmp' );

	/**
	 * Images with more pixels than this are not hashed perceptually.
	 */
	private const MAX_PIXELS = 50000000;

	/**
	 * The fingerprint of an attachment, as a row of the fingerprints table.
	 *
	 * @param int                       $attachment_id The attachment.
	 * @param array<string, mixed>|null $previous      Its current row, if any:
	 *                                                 an unchanged file keeps its hashes.
	 *
	 * @return array<string, mixed>|null Null for something that is not an attachment.
	 */
	public static function of( int $attachment_id, ?array $previous = null ): ?array {
		$post = get_post( $attachment_id );
		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type ) {
			return null;
		}

		$file = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$path = self::path( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id, true );
		$meta = is_array( $meta ) ? $meta : array();

		$row = array(
			'attachment_id' => $attachment_id,
			'file'          => $file,
			'status'        => 'missing',
			'content_hash'  => '',
			'upload_hash'   => (string) get_post_meta( $attachment_id, self::UPLOAD_HASH_META, true ),
			'phash'         => '',
			'width'         => (int) ( $meta['width'] ?? 0 ),
			'height'        => (int) ( $meta['height'] ?? 0 ),
			'file_size'     => 0,
			'file_mtime'    => 0,
			'version'       => self::VERSION,
		);

		if ( '' === $path || ! is_file( $path ) || ! is_readable( $path ) ) {
			return $row;
		}

		$row['status']     = 'ok';
		$row['file_size']  = (int) filesize( $path );
		$row['file_mtime'] = (int) filemtime( $path );

		$unchanged = $previous
			&& 'ok' === $previous['status']
			&& self::VERSION === $previous['version']
			&& $file === $previous['file']
			&& $row['file_size'] === (int) $previous['file_size']
			&& $row['file_mtime'] === (int) $previous['file_mtime'];

		if ( $unchanged ) {
			$row['content_hash'] = $previous['content_hash'];
			$row['phash']        = $previous['phash'];

			return $row;
		}

		$row['content_hash'] = (string) sha1_file( $path );

		if ( in_array( $post->post_mime_type, self::IMAGE_TYPES, true ) ) {
			$row['phash'] = self::dhash( self::small_copy( $attachment_id, $path, $meta ) );
		}

		return $row;
	}

	/**
	 * The file a fingerprint describes: the original upload, before any
	 * `-scaled` or rotated copy WordPress made of it.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return string Absolute path, or an empty string.
	 */
	public static function path( int $attachment_id ): string {
		$path = wp_attachment_is_image( $attachment_id ) ? wp_get_original_image_path( $attachment_id, true ) : false;

		return (string) ( $path ? $path : get_attached_file( $attachment_id, true ) );
	}

	/**
	 * The difference hash of an image file.
	 *
	 * @param string $path Image file.
	 *
	 * @return string 16 hex digits, or an empty string if the image cannot be read.
	 */
	public static function dhash( string $path ): string {
		$pixels = self::grey_pixels( $path );
		if ( null === $pixels ) {
			return '';
		}

		$hex = '';
		for ( $y = 0; $y < 8; $y++ ) {
			$bits = 0;
			for ( $x = 0; $x < 8; $x++ ) {
				$bits = ( $bits << 1 ) | ( $pixels[ $y * 9 + $x ] < $pixels[ $y * 9 + $x + 1 ] ? 1 : 0 );
			}
			$hex .= sprintf( '%02x', $bits );
		}

		return $hex;
	}

	/**
	 * How many bits two perceptual hashes differ in.
	 *
	 * @param string $a Hash.
	 * @param string $b Hash.
	 *
	 * @return int
	 */
	public static function distance( string $a, string $b ): int {
		$distance = 0;
		for ( $i = 0; $i < 16; $i += 8 ) {
			$distance += substr_count( decbin( hexdec( substr( $a, $i, 8 ) ) ^ hexdec( substr( $b, $i, 8 ) ) ), '1' );
		}

		return $distance;
	}

	/**
	 * Whether a perceptual hash says too little to compare images by: an
	 * image of nearly one colour sets almost no bits, or almost all.
	 *
	 * @param string $hash Hash.
	 *
	 * @return bool
	 */
	public static function is_flat( string $hash ): bool {
		$bits = self::distance( $hash, '0000000000000000' );

		return $bits <= 3 || $bits >= 61;
	}

	/**
	 * A resized copy of the image to hash, rather than the original: a
	 * 300 pixel copy decodes in a fraction of the memory and time. Cropped
	 * sizes such as the thumbnail would hash a different picture.
	 *
	 * @param int                  $attachment_id The attachment.
	 * @param string               $path          Its original file.
	 * @param array<string, mixed> $meta          Its metadata.
	 *
	 * @return string
	 */
	private static function small_copy( int $attachment_id, string $path, array $meta ): string {
		$file = $meta['sizes']['medium']['file'] ?? '';

		if ( is_string( $file ) && '' !== $file ) {
			$copy = path_join( dirname( (string) get_attached_file( $attachment_id, true ) ), wp_basename( $file ) );

			if ( is_file( $copy ) ) {
				return $copy;
			}
		}

		return $path;
	}

	/**
	 * Shrinks an image to 9 by 8 pixels and reads their brightness, with
	 * transparent areas laid on white.
	 *
	 * @param string $path Image file.
	 *
	 * @return int[]|null 72 values from 0 to 255, row by row.
	 */
	private static function grey_pixels( string $path ): ?array {
		// Decoding takes memory by the pixel, whatever the file size; running
		// out of it is fatal, and would stop the scan at this file every time.
		$size = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable images are skipped.
		if ( is_array( $size ) && (int) $size[0] * (int) $size[1] > self::MAX_PIXELS ) {
			return null;
		}

		$pixels = function_exists( 'imagecreatefromstring' ) ? self::grey_pixels_gd( $path ) : null;

		if ( null === $pixels && class_exists( \Imagick::class ) ) {
			$pixels = self::grey_pixels_imagick( $path );
		}

		return $pixels;
	}

	/**
	 * Reads the pixels with GD.
	 *
	 * @param string $path Image file.
	 *
	 * @return int[]|null
	 */
	private static function grey_pixels_gd( string $path ): ?array {
		// As core's image editors do before decoding.
		wp_raise_memory_limit( 'image' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local file.
		$data  = file_get_contents( $path );
		$image = false !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Unreadable images are skipped.

		if ( ! $image ) {
			return null;
		}

		$small = imagecreatetruecolor( 9, 8 );
		imagefill( $small, 0, 0, (int) imagecolorallocate( $small, 255, 255, 255 ) );
		imagecopyresampled( $small, $image, 0, 0, 0, 0, 9, 8, imagesx( $image ), imagesy( $image ) );

		$pixels = array();
		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 9; $x++ ) {
				$rgb      = imagecolorat( $small, $x, $y );
				$pixels[] = self::luminance( ( $rgb >> 16 ) & 0xFF, ( $rgb >> 8 ) & 0xFF, $rgb & 0xFF );
			}
		}

		return $pixels;
	}

	/**
	 * Reads the pixels with Imagick, for formats GD cannot decode.
	 *
	 * @param string $path Image file.
	 *
	 * @return int[]|null
	 */
	private static function grey_pixels_imagick( string $path ): ?array {
		try {
			$image = new \Imagick( $path );
			$image->setIteratorIndex( 0 );
			$image->setImageBackgroundColor( 'white' );
			$image = $image->mergeImageLayers( \Imagick::LAYERMETHOD_FLATTEN );
			$image->resizeImage( 9, 8, \Imagick::FILTER_BOX, 1 );

			$pixels = array();
			$rgb    = $image->exportImagePixels( 0, 0, 9, 8, 'RGB', \Imagick::PIXEL_CHAR );
			for ( $i = 0; $i < 72; $i++ ) {
				$pixels[] = self::luminance( $rgb[ $i * 3 ], $rgb[ $i * 3 + 1 ], $rgb[ $i * 3 + 2 ] );
			}

			return $pixels;
		} catch ( \Exception $e ) {
			return null;
		}
	}

	/**
	 * The brightness of a colour, as the eye sees it.
	 *
	 * @param int $r Red.
	 * @param int $g Green.
	 * @param int $b Blue.
	 *
	 * @return int
	 */
	private static function luminance( int $r, int $g, int $b ): int {
		return (int) round( 0.299 * $r + 0.587 * $g + 0.114 * $b );
	}
}
