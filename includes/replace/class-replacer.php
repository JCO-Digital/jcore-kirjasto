<?php
/**
 * Replaces the file of an attachment.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Replace;

use Jcore\Kirjasto\Attachment_Files;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Puts a new file in place of an attachment's file, keeping the attachment.
 *
 * The attachment keeps its ID, title, folder and every other field, and the
 * file keeps its name, so everything that refers to the attachment, by ID or
 * by URL, shows the new file. Only a file of the same MIME type is accepted:
 * a different type would need a different file name.
 *
 * Resized copies are generated again from the new file. When the new file's
 * dimensions differ, so do the names of the copies; post content linking to
 * an old copy is rewritten to link to the new copy of the same size.
 */
final class Replacer {

	/**
	 * Meta key holding the time of the last replacement.
	 */
	public const REPLACED_META = '_jcore_kirjasto_replaced';

	/**
	 * Replaces the file of an attachment with a file on disk.
	 *
	 * The file is moved, not copied: it is gone afterwards, whether the
	 * replacement succeeded or not.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $path          The new file.
	 * @param string $name          The new file's original name, for its type.
	 *
	 * @return true|\WP_Error
	 */
	public static function replace( int $attachment_id, string $path, string $name ): true|\WP_Error {
		$result = self::replace_file( $attachment_id, $path, $name );

		if ( file_exists( $path ) ) {
			wp_delete_file( $path );
		}

		return $result;
	}

	/**
	 * The MIME type a replacement must have, or null for a missing attachment
	 * or one without a file.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return string|null
	 */
	public static function mime_type( int $attachment_id ): ?string {
		$post = get_post( $attachment_id );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || ! get_attached_file( $attachment_id ) ) {
			return null;
		}

		return $post->post_mime_type ? $post->post_mime_type : null;
	}

	/**
	 * When the file of an attachment was last replaced, as a Unix time, or 0.
	 *
	 * The file kept its address, so caches may hold the old file under it;
	 * this tells the addresses apart where the new file must show at once.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return int
	 */
	public static function version( int $attachment_id ): int {
		return (int) get_post_meta( $attachment_id, self::REPLACED_META, true );
	}

	/**
	 * Does the replacing; replace() cleans up after it.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $path          The new file.
	 * @param string $name          The new file's original name.
	 *
	 * @return true|\WP_Error
	 */
	private static function replace_file( int $attachment_id, string $path, string $name ): true|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$mime_type = self::mime_type( $attachment_id );
		if ( null === $mime_type ) {
			return new \WP_Error( 'jcore_kirjasto_replace_attachment', __( 'The file to replace was not found.', 'jcore-kirjasto' ), array( 'status' => 404 ) );
		}

		if ( ! is_file( $path ) || ! filesize( $path ) ) {
			return new \WP_Error( 'jcore_kirjasto_replace_empty', __( 'The new file is empty.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
		}

		$type = wp_check_filetype_and_ext( $path, $name );
		if ( $mime_type !== $type['type'] ) {
			return new \WP_Error(
				'jcore_kirjasto_replace_type',
				sprintf(
					/* translators: %s: file type, e.g. JPG. */
					__( 'The new file must be of the same type as the one it replaces: %s.', 'jcore-kirjasto' ),
					self::type_label( $mime_type )
				),
				array( 'status' => 400 )
			);
		}

		$old_file = (string) get_attached_file( $attachment_id, true );
		$old_meta = wp_get_attachment_metadata( $attachment_id, true );
		$old_meta = is_array( $old_meta ) ? $old_meta : array();
		$old_urls = Attachment_Files::urls( $old_file, $old_meta );
		$sharing  = self::sharing( $attachment_id );

		// The original upload, not the `-scaled` copy WordPress may have made of
		// it: the new file is scaled again if it is just as big.
		$target    = self::original_path( $attachment_id, $old_file );
		$old_files = self::files( $attachment_id, $old_file, $old_meta );

		if ( ! wp_mkdir_p( dirname( $target ) ) || ! @rename( $path, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename -- Reported below.
			return new \WP_Error( 'jcore_kirjasto_replace_move', __( 'The new file could not be moved into place.', 'jcore-kirjasto' ), array( 'status' => 500 ) );
		}

		// Uploads get these permissions in core; a temporary file may not have them.
		$stat = stat( dirname( $target ) );
		chmod( $target, $stat ? $stat['mode'] & 0000666 : 0644 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$uploads = wp_get_upload_dir();
		foreach ( $old_files as $file ) {
			if ( $file !== $target ) {
				wp_delete_file_from_directory( $file, $uploads['basedir'] );
			}

			// Copies optimisation plugins keep beside each file, the kept file's
			// included: they still show the old one.
			foreach ( self::derived_files( $file ) as $derived ) {
				wp_delete_file_from_directory( $derived, $uploads['basedir'] );
			}
		}

		update_attached_file( $attachment_id, $target );
		delete_post_meta( $attachment_id, '_wp_attachment_backup_sizes' );

		$meta = wp_generate_attachment_metadata( $attachment_id, $target );
		wp_update_attachment_metadata( $attachment_id, $meta );

		$new_file = (string) get_attached_file( $attachment_id, true );
		$new_meta = wp_get_attachment_metadata( $attachment_id, true );
		$new_meta = is_array( $new_meta ) ? $new_meta : array();

		// Translations of the attachment share its file, so they share the new one.
		foreach ( $sharing as $id ) {
			update_attached_file( $id, $new_file );
			delete_post_meta( $id, '_wp_attachment_backup_sizes' );
			wp_update_attachment_metadata( $id, $new_meta );
		}

		$ids = array_merge( array( $attachment_id ), $sharing );
		foreach ( $ids as $id ) {
			self::touch( $id );
		}

		$map = Attachment_Files::url_map( $old_urls, Attachment_Files::urls( $new_file, $new_meta ), wp_attachment_is_image( $attachment_id ) );
		self::rewrite_content( $map );

		/**
		 * Fires after the file of an attachment has been replaced.
		 *
		 * @param int                   $attachment_id The attachment.
		 * @param array<string, mixed>  $old_meta      Its metadata before.
		 * @param array<string, string> $map           Old URLs of resized copies that changed, mapped to the new ones.
		 * @param int[]                 $sharing       Other attachments sharing the file, e.g. translations.
		 */
		do_action( 'jcore_kirjasto_replaced', $attachment_id, $old_meta, $map, $sharing );

		return true;
	}

	/**
	 * The path of the original upload, before any `-scaled` copy.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $file          Its attached file.
	 *
	 * @return string
	 */
	private static function original_path( int $attachment_id, string $file ): string {
		$original = wp_get_original_image_path( $attachment_id, true );

		return is_string( $original ) && $original ? $original : $file;
	}

	/**
	 * Every file of an attachment: the attached file, the original upload, its
	 * resized copies and the backups of earlier edits.
	 *
	 * @param int                  $attachment_id The attachment.
	 * @param string               $file          Its attached file.
	 * @param array<string, mixed> $meta          Its metadata.
	 *
	 * @return string[]
	 */
	private static function files( int $attachment_id, string $file, array $meta ): array {
		$dir   = dirname( $file );
		$files = array( $file, self::original_path( $attachment_id, $file ) );
		$sizes = array_merge(
			is_array( $meta['sizes'] ?? null ) ? $meta['sizes'] : array(),
			(array) get_post_meta( $attachment_id, '_wp_attachment_backup_sizes', true )
		);

		foreach ( $sizes as $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$files[] = path_join( $dir, wp_basename( $size['file'] ) );
			}
		}

		return array_unique( $files );
	}

	/**
	 * Copies of a file that other plugins keep beside it, such as the
	 * `photo.jpg.webp` of EWWW Image Optimizer, WebP Express or Converter for
	 * Media, which only exist on disk.
	 *
	 * @param string $file A file of the attachment.
	 *
	 * @return string[]
	 */
	private static function derived_files( string $file ): array {
		$files = array( $file . '.webp', $file . '.avif' );

		/**
		 * Filters the copies of an attachment's file that are deleted with it
		 * when the file is replaced.
		 *
		 * @param string[] $files Paths of the copies; missing ones are skipped.
		 * @param string   $file  The file.
		 */
		$files = (array) apply_filters( 'jcore_kirjasto_replace_derived_files', $files, $file );

		return array_filter( $files, 'is_file' );
	}

	/**
	 * Points post content linking to the old copies at the new ones.
	 *
	 * The content is written directly, without a revision or a new modified
	 * date: nothing about it changed but the file names.
	 *
	 * @param array<string, string> $map Old relative URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_content( array $map ): void {
		global $wpdb;

		if ( ! $map ) {
			return;
		}

		$likes = array();
		foreach ( array_keys( $map ) as $old ) {
			$likes[] = $wpdb->prepare( 'post_content LIKE %s', '%' . $wpdb->esc_like( wp_basename( $old ) ) . '%' );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- Each clause was prepared above.
		$posts = $wpdb->get_results( "SELECT ID, post_content FROM {$wpdb->posts} WHERE post_type <> 'revision' AND (" . implode( ' OR ', $likes ) . ')' );

		foreach ( $posts as $post ) {
			$content = Attachment_Files::rewrite_urls( $post->post_content, $map );

			if ( $content !== $post->post_content ) {
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				clean_post_cache( (int) $post->ID );
			}
		}
	}

	/**
	 * Other attachments with the same file, e.g. Polylang's translations of
	 * the attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return int[]
	 */
	private static function sharing( int $attachment_id ): array {
		global $wpdb;

		$file = get_post_meta( $attachment_id, '_wp_attached_file', true );
		if ( ! is_string( $file ) || '' === $file ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.post_id FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id WHERE m.meta_key = '_wp_attached_file' AND m.meta_value = %s AND p.post_type = 'attachment' AND m.post_id <> %d",
				$file,
				$attachment_id
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Records the replacement and moves the modified date, so caches keyed on
	 * it let go of the old file.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	private static function touch( int $attachment_id ): void {
		global $wpdb;

		update_post_meta( $attachment_id, self::REPLACED_META, time() );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			$wpdb->posts,
			array(
				'post_modified'     => current_time( 'mysql' ),
				'post_modified_gmt' => current_time( 'mysql', true ),
			),
			array( 'ID' => $attachment_id )
		);
		clean_post_cache( $attachment_id );
	}

	/**
	 * A short name for a MIME type, e.g. JPG for `image/jpeg`.
	 *
	 * @param string $mime_type The MIME type.
	 *
	 * @return string
	 */
	public static function type_label( string $mime_type ): string {
		foreach ( wp_get_mime_types() as $extensions => $type ) {
			if ( $type === $mime_type ) {
				return strtoupper( strtok( $extensions, '|' ) );
			}
		}

		return $mime_type;
	}
}
