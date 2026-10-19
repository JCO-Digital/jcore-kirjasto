<?php
/**
 * The files of an attachment, and links to them.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the addresses of an attachment's files and moves links from one set
 * of files to another, for features that change which file content shows:
 * replacing a file, and merging duplicates.
 */
final class Attachment_Files {

	/**
	 * The URL of each file of an attachment, relative to the uploads directory:
	 * content may link to them on any domain. Keyed `file` for the attached
	 * file, `original` for the upload it was scaled from and `size:{name}` for
	 * the resized copies; a PDF's preview, for one, is a size named `full`.
	 *
	 * @param string               $file Attached file, as an absolute path.
	 * @param array<string, mixed> $meta Metadata.
	 *
	 * @return array<string, string>
	 */
	public static function urls( string $file, array $meta ): array {
		$base = _wp_relative_upload_path( $file );
		$dir  = dirname( $base );
		$dir  = '.' === $dir ? '' : trailingslashit( $dir );
		$urls = array( 'file' => $base );

		if ( ! empty( $meta['original_image'] ) ) {
			$urls['original'] = $dir . wp_basename( $meta['original_image'] );
		}

		foreach ( is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array() as $size => $data ) {
			if ( is_array( $data ) && ! empty( $data['file'] ) ) {
				$urls[ 'size:' . $size ] = $dir . wp_basename( $data['file'] );
			}
		}

		return $urls;
	}

	/**
	 * The URLs of an attachment's files, keyed as urls() keys them.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return array<string, string>
	 */
	public static function attachment_urls( int $attachment_id ): array {
		$meta = wp_get_attachment_metadata( $attachment_id, true );

		return self::urls( (string) get_attached_file( $attachment_id, true ), is_array( $meta ) ? $meta : array() );
	}

	/**
	 * Maps the old URLs that are gone to their replacements: the same size, or
	 * for an image the attached file, when the new image is too small for it.
	 *
	 * @param array<string, string> $old_urls Old URLs, keyed as urls() keys them.
	 * @param array<string, string> $new_urls New URLs, keyed the same way.
	 * @param bool                  $is_image Whether the attachment is an image.
	 *
	 * @return array<string, string>
	 */
	public static function url_map( array $old_urls, array $new_urls, bool $is_image ): array {
		$map = array();

		foreach ( $old_urls as $key => $url ) {
			$replacement = $new_urls[ $key ] ?? ( 'original' === $key ? $new_urls['file'] : null );
			$replacement = $replacement ?? ( $is_image ? $new_urls['file'] : null );

			if ( null !== $replacement && $replacement !== $url && ! in_array( $url, $new_urls, true ) ) {
				$map[ $url ] = $replacement;
			}
		}

		return $map;
	}

	/**
	 * Points the links in a text at other files.
	 *
	 * Links are matched with a leading slash, so `photo.jpg` does not match
	 * `my-photo.jpg`, both as written and JSON-escaped.
	 *
	 * @param string                $text The text.
	 * @param array<string, string> $map  Old relative URLs mapped to new ones.
	 *
	 * @return string
	 */
	public static function rewrite_urls( string $text, array $map ): string {
		$pairs = array();
		foreach ( $map as $old => $new ) {
			$pairs[ '/' . $old ]                              = '/' . $new;
			$pairs[ '\\/' . str_replace( '/', '\\/', $old ) ] = '\\/' . str_replace( '/', '\\/', $new );
		}

		return $pairs ? strtr( $text, $pairs ) : $text;
	}
}
