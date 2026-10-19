<?php
/**
 * Database setup and migrations.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Owns the plugin's tables: their names, their schema and their upgrades.
 */
class Database {

	private const DB_VERSION        = '1.1';
	private const DB_VERSION_OPTION = 'jcore_kirjasto_db_version';

	/**
	 * Unprefixed table names, keyed by the short name the rest of the plugin uses.
	 */
	private const TABLES = array(
		'usage'        => 'jcore_kirjasto_usage',
		'fingerprints' => 'jcore_kirjasto_fingerprints',
	);

	/**
	 * Returns the prefixed name of one of the plugin's tables.
	 *
	 * @param string $name `usage` or `fingerprints`.
	 *
	 * @return string
	 */
	public static function table( string $name ): string {
		global $wpdb;

		return $wpdb->prefix . self::TABLES[ $name ];
	}

	/**
	 * Runs install() if the stored DB version is behind the current one.
	 * Called on every plugins_loaded so schema changes deploy without reactivation.
	 *
	 * @return void
	 */
	public static function maybe_upgrade(): void {
		if ( self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::install();
	}

	/**
	 * Creates the tables, or brings them up to date. Run on plugin activation
	 * and upgrade.
	 *
	 * Usage: one row is one reference: attachment X is used by object Y in
	 * context Z. `source` narrows the context down, e.g. to the ACF field key
	 * or meta key. `indexed_at` lets a full rebuild drop the rows it did not
	 * touch.
	 *
	 * Fingerprints: one row per attachment, describing its file. `status` is
	 * `ok` or `missing`; `content_hash` is the SHA-1 of the file as it is now
	 * and `upload_hash` of the file as it was uploaded, before optimisers
	 * rewrote it; `phash` is a perceptual hash of images, as 16 hex digits.
	 * `file_size`, `file_mtime` and `version` tell a scan whether the file
	 * needs hashing again.
	 *
	 * @return void
	 */
	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta(
			'CREATE TABLE ' . self::table( 'usage' ) . " (
			  id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			  attachment_id BIGINT UNSIGNED NOT NULL,
			  object_type   VARCHAR(20)     NOT NULL DEFAULT '',
			  object_id     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  context       VARCHAR(20)     NOT NULL DEFAULT '',
			  source        VARCHAR(191)    NOT NULL DEFAULT '',
			  indexed_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY  (id),
			  UNIQUE KEY reference (attachment_id, object_type, object_id, context, source),
			  KEY object (object_type, object_id),
			  KEY indexed_at (indexed_at)
			) " . $wpdb->get_charset_collate() . ';'
		);

		dbDelta(
			'CREATE TABLE ' . self::table( 'fingerprints' ) . " (
			  attachment_id BIGINT UNSIGNED NOT NULL,
			  file          VARCHAR(255)    NOT NULL DEFAULT '',
			  status        VARCHAR(10)     NOT NULL DEFAULT '',
			  content_hash  CHAR(40)        NOT NULL DEFAULT '',
			  upload_hash   CHAR(40)        NOT NULL DEFAULT '',
			  phash         CHAR(16)        NOT NULL DEFAULT '',
			  width         INT UNSIGNED    NOT NULL DEFAULT 0,
			  height        INT UNSIGNED    NOT NULL DEFAULT 0,
			  file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  file_mtime    BIGINT UNSIGNED NOT NULL DEFAULT 0,
			  version       VARCHAR(10)     NOT NULL DEFAULT '',
			  hashed_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
			  PRIMARY KEY  (attachment_id),
			  KEY content_hash (content_hash),
			  KEY upload_hash (upload_hash),
			  KEY file (file(191)),
			  KEY hashed_at (hashed_at)
			) " . $wpdb->get_charset_collate() . ';'
		);

		update_option( self::DB_VERSION_OPTION, self::DB_VERSION, false );
	}
}
