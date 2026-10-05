<?php
/**
 * Imports folders from other media folder plugins.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Copies the folders, and which attachments are in them, from FileBird,
 * HappyFiles or Enhanced Media Library.
 *
 * The data is read straight from the database, so the other plugin does not
 * have to be active, and is left untouched. An import can be run again: every
 * folder remembers the folder it came from, and attachments already in a
 * folder keep it. Attachments are copied in batches, so a large library can
 * be imported over several requests.
 */
final class Importer {

	/**
	 * Term meta recording where an imported folder came from, e.g. `filebird:12`.
	 */
	private const SOURCE_META = '_jcore_kirjasto_source';

	/**
	 * Attachments handled per step.
	 */
	public const BATCH_SIZE = 500;

	/**
	 * The plugins folders can be imported from, keyed by source name.
	 *
	 * @return array<string, array{label: string, taxonomy?: string}>
	 */
	private static function definitions(): array {
		return array(
			'filebird'               => array( 'label' => 'FileBird' ),
			'happyfiles'             => array(
				'label'    => 'HappyFiles',
				'taxonomy' => 'happyfiles_category',
			),
			'enhanced-media-library' => array(
				'label'    => 'Enhanced Media Library',
				'taxonomy' => 'media_category',
			),
		);
	}

	/**
	 * The sources that have folders on this site, with how many folders and
	 * folder assignments each has, and how many of its folders were imported.
	 *
	 * @return array<int, array{source: string, label: string, folders: int, files: int, imported: int}>
	 */
	public static function sources(): array {
		$sources = array();

		foreach ( self::definitions() as $source => $definition ) {
			$folders = self::folders( $source );
			if ( $folders ) {
				$sources[] = array(
					'source'   => $source,
					'label'    => $definition['label'],
					'folders'  => count( $folders ),
					'files'    => self::count_files( $source ),
					'imported' => count( array_intersect_key( self::imported_folders( $source ), $folders ) ),
				);
			}
		}

		return $sources;
	}

	/**
	 * Runs one step of an import. The first step creates the folders; every
	 * step moves a batch of attachments into them.
	 *
	 * @param string $source Source name.
	 * @param int    $offset Assignments handled by earlier steps.
	 *
	 * @return array{source: string, imported: int, offset: int, total: int, moved: int, done: bool}|\WP_Error
	 */
	public static function step( string $source, int $offset = 0 ) {
		if ( ! isset( self::definitions()[ $source ] ) ) {
			return new \WP_Error( 'jcore_kirjasto_import_source', __( 'Folders cannot be imported from this source.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
		}

		$map = 0 === $offset ? self::import_folders( $source ) : self::imported_folders( $source );

		wp_defer_term_counting( true );

		$moved = 0;
		$rows  = self::files( $source, $offset );
		$ids   = array_map( 'intval', wp_list_pluck( $rows, 'attachment_id' ) );

		_prime_post_caches( $ids, false, false );
		update_object_term_cache( $ids, 'attachment' );

		foreach ( $rows as $row ) {
			$folder = $map[ (int) $row->folder_id ] ?? 0;

			// The first folder wins: kirjasto keeps an attachment in one folder.
			if ( $folder && 'attachment' === get_post_type( (int) $row->attachment_id ) && ! Folders::of( (int) $row->attachment_id ) ) {
				wp_set_object_terms( (int) $row->attachment_id, array( $folder ), Folders::TAXONOMY );
				++$moved;
			}
		}

		wp_defer_term_counting( false );

		$total = self::count_files( $source );

		return array(
			'source'   => $source,
			'imported' => count( $map ),
			'offset'   => $offset + count( $rows ),
			'total'    => $total,
			'moved'    => $moved,
			'done'     => count( $rows ) < self::BATCH_SIZE,
		);
	}

	/**
	 * Creates a folder for every source folder that has none yet.
	 *
	 * @param string $source Source name.
	 *
	 * @return array<int, int> Folder IDs, keyed by source folder ID.
	 */
	private static function import_folders( string $source ): array {
		$map     = self::imported_folders( $source );
		$pending = self::folders( $source );

		// Parents first: a folder is created once its parent exists. Folders
		// whose parent is missing from the source end up at the top level.
		while ( $pending ) {
			$created = false;

			foreach ( $pending as $key => $folder ) {
				$parent_id = (int) $folder->parent;
				$known     = 0 === $parent_id || isset( $map[ $parent_id ] ) || ! isset( $pending[ $parent_id ] );

				if ( ! $known ) {
					continue;
				}

				unset( $pending[ $key ] );
				$created = true;

				if ( isset( $map[ (int) $folder->id ] ) ) {
					continue;
				}

				$id = self::find_or_create( (string) $folder->name, $map[ $parent_id ] ?? 0 );
				if ( $id ) {
					update_term_meta( $id, self::SOURCE_META, $source . ':' . (int) $folder->id );
					$map[ (int) $folder->id ] = $id;
				}
			}

			if ( ! $created ) {
				break;
			}
		}

		return $map;
	}

	/**
	 * The folders an earlier import created.
	 *
	 * @param string $source Source name.
	 *
	 * @return array<int, int> Folder IDs, keyed by source folder ID.
	 */
	private static function imported_folders( string $source ): array {
		$terms = get_terms(
			array(
				'taxonomy'   => Folders::TAXONOMY,
				'hide_empty' => false,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Once per import step, on a small table.
				'meta_query' => array(
					array(
						'key'     => self::SOURCE_META,
						'value'   => $source . ':',
						'compare' => 'LIKE',
					),
				),
			)
		);

		$map = array();
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$origin = (string) get_term_meta( $term->term_id, self::SOURCE_META, true );
			if ( str_starts_with( $origin, $source . ':' ) ) {
				$map[ (int) substr( $origin, strlen( $source ) + 1 ) ] = (int) $term->term_id;
			}
		}

		return $map;
	}

	/**
	 * Returns the folder with this name under this parent, creating it if needed.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent_id Parent folder ID.
	 *
	 * @return int Folder ID, or 0 if it could not be created.
	 */
	private static function find_or_create( string $name, int $parent_id ): int {
		$name     = '' !== trim( $name ) ? $name : __( 'Untitled folder', 'jcore-kirjasto' );
		$existing = term_exists( $name, Folders::TAXONOMY, $parent_id );

		if ( is_array( $existing ) ) {
			return (int) $existing['term_id'];
		}

		$id = Folders::create( $name, $parent_id );

		return is_int( $id ) ? $id : 0;
	}

	/**
	 * The folders of a source, keyed by their ID there.
	 *
	 * @param string $source Source name.
	 *
	 * @return array<int, object{id: int, name: string, parent: int}>
	 */
	private static function folders( string $source ): array {
		global $wpdb;

		$taxonomy = self::definitions()[ $source ]['taxonomy'] ?? null;

		if ( $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT t.term_id AS id, t.name, tt.parent FROM %i t INNER JOIN %i tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s', $wpdb->terms, $wpdb->term_taxonomy, $taxonomy ) );
		} elseif ( self::table_exists( $wpdb->prefix . 'fbv' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, parent FROM %i ORDER BY parent, ord, id', $wpdb->prefix . 'fbv' ) );
		} else {
			$rows = array();
		}

		$folders = array();
		foreach ( (array) $rows as $row ) {
			$row->name                 = html_entity_decode( (string) $row->name, ENT_QUOTES );
			$folders[ (int) $row->id ] = $row;
		}

		return $folders;
	}

	/**
	 * A batch of a source's folder assignments, in a stable order.
	 *
	 * @param string $source Source name.
	 * @param int    $offset Assignments to skip.
	 *
	 * @return array<int, object{folder_id: int, attachment_id: int}>
	 */
	private static function files( string $source, int $offset ): array {
		global $wpdb;

		$taxonomy = self::definitions()[ $source ]['taxonomy'] ?? null;

		if ( $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return $wpdb->get_results( $wpdb->prepare( 'SELECT tt.term_id AS folder_id, tr.object_id AS attachment_id FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s ORDER BY tr.object_id, tt.term_id LIMIT %d, %d', $wpdb->term_relationships, $wpdb->term_taxonomy, $taxonomy, $offset, self::BATCH_SIZE ) );
		}

		if ( ! self::table_exists( $wpdb->prefix . 'fbv_attachment_folder' ) ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results( $wpdb->prepare( 'SELECT folder_id, attachment_id FROM %i ORDER BY attachment_id, folder_id LIMIT %d, %d', $wpdb->prefix . 'fbv_attachment_folder', $offset, self::BATCH_SIZE ) );
	}

	/**
	 * How many folder assignments a source has.
	 *
	 * @param string $source Source name.
	 *
	 * @return int
	 */
	private static function count_files( string $source ): int {
		global $wpdb;

		$taxonomy = self::definitions()[ $source ]['taxonomy'] ?? null;

		if ( $taxonomy ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = %s', $wpdb->term_relationships, $wpdb->term_taxonomy, $taxonomy ) );
		}

		if ( ! self::table_exists( $wpdb->prefix . 'fbv_attachment_folder' ) ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $wpdb->prefix . 'fbv_attachment_folder' ) );
	}

	/**
	 * Whether a database table exists.
	 *
	 * @param string $table Table name.
	 *
	 * @return bool
	 */
	private static function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
	}
}
