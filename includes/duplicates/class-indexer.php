<?php
/**
 * Keeps the fingerprints table in step with the media library.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fingerprints attachments as they are uploaded, edited or replaced, and
 * forgets the ones that are deleted.
 *
 * Changes are queued and fingerprinted once on `shutdown`, after the
 * metadata of a new upload has been generated.
 */
final class Indexer {

	/**
	 * Option counting the changes to the fingerprints and dismissals, so
	 * cached groups know when they are stale.
	 */
	public const REVISION_OPTION = 'jcore_kirjasto_duplicates_revision';

	/**
	 * Attachments waiting to be fingerprinted on shutdown.
	 *
	 * @var array<int, true>
	 */
	private static array $queue = array();

	/**
	 * SHA-1 of files just uploaded, by path, until their attachment exists.
	 *
	 * @var array<string, string>
	 */
	private static array $uploads = array();

	/**
	 * Hooks the indexer to every change of an attachment's file.
	 *
	 * @return void
	 */
	public static function init(): void {
		// Early, before image optimisers rewrite the uploaded file. Sideloads
		// pass through the same filter.
		add_filter( 'wp_handle_upload', array( self::class, 'remember_upload' ), -1000 );
		add_action( 'add_attachment', array( self::class, 'attachment_added' ) );

		foreach ( array( 'added', 'updated' ) as $action ) {
			add_action( "{$action}_post_meta", array( self::class, 'meta_changed' ), 10, 3 );
		}

		add_action( 'jcore_kirjasto_replaced', array( self::class, 'file_replaced' ) );
		add_action( 'delete_attachment', array( self::class, 'delete' ) );
		add_action( 'shutdown', array( self::class, 'process_queue' ) );
	}

	/**
	 * Hashes an uploaded file before anything else touches it.
	 *
	 * @param array<string, mixed> $upload The upload: `file`, `url` and `type`.
	 *
	 * @return array<string, mixed>
	 */
	public static function remember_upload( $upload ): array {
		$upload = (array) $upload;

		if ( empty( $upload['error'] ) && isset( $upload['file'] ) && is_file( $upload['file'] ) ) {
			self::$uploads[ wp_normalize_path( $upload['file'] ) ] = (string) sha1_file( $upload['file'] );
		}

		return $upload;
	}

	/**
	 * Stores the hash of the uploaded file with its new attachment, and
	 * queues the attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	public static function attachment_added( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		$path          = wp_normalize_path( (string) get_attached_file( $attachment_id, true ) );

		if ( isset( self::$uploads[ $path ] ) ) {
			update_post_meta( $attachment_id, Fingerprint::UPLOAD_HASH_META, self::$uploads[ $path ] );
			unset( self::$uploads[ $path ] );
		}

		self::queue( $attachment_id );
	}

	/**
	 * Queues an attachment when its file or metadata changes.
	 *
	 * @param int|int[] $meta_id   Meta ID.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 *
	 * @return void
	 */
	public static function meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( '_wp_attached_file' === $meta_key || '_wp_attachment_metadata' === $meta_key ) {
			self::queue( (int) $object_id );
		}
	}

	/**
	 * A replaced file is no longer the one that was uploaded.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	public static function file_replaced( $attachment_id ): void {
		delete_post_meta( (int) $attachment_id, Fingerprint::UPLOAD_HASH_META );
		self::queue( (int) $attachment_id );
	}

	/**
	 * Queues an attachment for fingerprinting.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	public static function queue( int $attachment_id ): void {
		self::$queue[ $attachment_id ] = true;
	}

	/**
	 * Fingerprints everything queued during this request.
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		$ids         = array_keys( self::$queue );
		self::$queue = array();

		foreach ( $ids as $id ) {
			self::index( $id );
		}
	}

	/**
	 * Fingerprints one attachment. A file that has not changed is not read.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	public static function index( int $attachment_id ): void {
		$previous = self::row( $attachment_id );
		$row      = Fingerprint::of( $attachment_id, $previous );

		if ( null === $row ) {
			self::delete( $attachment_id );
			return;
		}

		self::write( $row, $previous );
	}

	/**
	 * Forgets an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return void
	 */
	public static function delete( $attachment_id ): void {
		global $wpdb;

		unset( self::$queue[ (int) $attachment_id ] );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->delete( Database::table( 'fingerprints' ), array( 'attachment_id' => (int) $attachment_id ), array( '%d' ) ) ) {
			self::bump_revision();
		}
	}

	/**
	 * The stored fingerprint of an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function row( int $attachment_id ): ?array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE attachment_id = %d', Database::table( 'fingerprints' ), $attachment_id ), ARRAY_A );

		return is_array( $row ) ? $row : null;
	}

	/**
	 * Marks the fingerprints as changed.
	 *
	 * @return void
	 */
	public static function bump_revision(): void {
		update_option( self::REVISION_OPTION, (int) get_option( self::REVISION_OPTION, 0 ) + 1, false );
		Finder::flush();
	}

	/**
	 * Stores a fingerprint, and marks the fingerprints as changed if it
	 * changed what duplicates are found by.
	 *
	 * @param array<string, mixed>      $row      The fingerprint.
	 * @param array<string, mixed>|null $previous The stored one.
	 *
	 * @return void
	 */
	private static function write( array $row, ?array $previous ): void {
		global $wpdb;

		$row['hashed_at'] = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->replace( Database::table( 'fingerprints' ), $row );

		$compared = array( 'file', 'status', 'content_hash', 'upload_hash', 'phash', 'width', 'height' );
		foreach ( $compared as $key ) {
			if ( ! $previous || (string) $previous[ $key ] !== (string) $row[ $key ] ) {
				self::bump_revision();
				return;
			}
		}
	}
}
