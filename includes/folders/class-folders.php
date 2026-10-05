<?php
/**
 * Media folders: the taxonomy behind them and every change made to them.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Folders are terms of a hidden, hierarchical attachment taxonomy. An
 * attachment is in one folder at most; one without a term has no folder.
 *
 * With Polylang translating media, the translations of an attachment are
 * separate attachments. They are kept in the same folder.
 */
final class Folders {

	/**
	 * The taxonomy.
	 */
	public const TAXONOMY = 'jcore_kirjasto_folder';

	/**
	 * The query var, request parameter and attachment field holding a folder:
	 * a term ID, or 0 for attachments without a folder.
	 */
	public const KEY = 'jcore_kirjasto_folder';

	/**
	 * Registers the taxonomy.
	 *
	 * @return void
	 */
	public static function register_taxonomy(): void {
		register_taxonomy(
			self::TAXONOMY,
			'attachment',
			array(
				'labels'                => array(
					'name'          => __( 'Folders', 'jcore-kirjasto' ),
					'singular_name' => __( 'Folder', 'jcore-kirjasto' ),
				),
				'hierarchical'          => true,
				'public'                => false,
				'show_ui'               => false,
				'show_in_rest'          => false,
				'query_var'             => false,
				'rewrite'               => false,
				'update_count_callback' => '_update_generic_term_count',
			)
		);
	}

	/**
	 * The capability needed to create, rename, move and delete folders.
	 * Moving an attachment needs the right to edit it instead.
	 *
	 * @return string
	 */
	public static function capability(): string {
		/**
		 * Filters the capability needed to manage media folders.
		 *
		 * @param string $capability Capability. Default `upload_files`.
		 */
		return (string) apply_filters( 'jcore_kirjasto_folders_capability', 'upload_files' );
	}

	/**
	 * Every folder with the number of attachments directly in it, and the
	 * totals the folder list shows above them.
	 *
	 * @return array{folders: array<int, array{id: int, name: string, parent: int, count: int}>, total: int, unassigned: int}
	 */
	public static function tree(): array {
		global $wpdb;

		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'orderby'    => 'none',
			)
		);

		$language = self::language_clause();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$counts = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $language is built by language_clause().
				"SELECT tt.term_id, COUNT(*) AS count FROM %i tr INNER JOIN %i tt ON tt.term_taxonomy_id = tr.term_taxonomy_id INNER JOIN %i p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_type = 'attachment' AND p.post_status <> 'trash' $language GROUP BY tt.term_id",
				$wpdb->term_relationships,
				$wpdb->term_taxonomy,
				$wpdb->posts,
				self::TAXONOMY
			),
			OBJECT_K
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $language is built by language_clause().
				"SELECT COUNT(*) FROM %i p WHERE p.post_type = 'attachment' AND p.post_status <> 'trash' $language",
				$wpdb->posts
			)
		);

		$folders  = array();
		$assigned = 0;
		foreach ( is_array( $terms ) ? $terms : array() as $term ) {
			$count     = isset( $counts[ $term->term_id ] ) ? (int) $counts[ $term->term_id ]->count : 0;
			$assigned += $count;
			$folders[] = array(
				'id'     => (int) $term->term_id,
				'name'   => html_entity_decode( $term->name, ENT_QUOTES ),
				'parent' => (int) $term->parent,
				'count'  => $count,
			);
		}

		return array(
			'folders'    => $folders,
			'total'      => $total,
			'unassigned' => max( 0, $total - $assigned ),
		);
	}

	/**
	 * Creates a folder.
	 *
	 * @param string $name   Folder name.
	 * @param int    $parent_id Parent folder, or 0 for the top level.
	 *
	 * @return int|\WP_Error The new folder's ID.
	 */
	public static function create( string $name, int $parent_id = 0 ) {
		$name = trim( $name );
		if ( '' === $name ) {
			return new \WP_Error( 'jcore_kirjasto_folder_name', __( 'A folder needs a name.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
		}

		if ( $parent_id && ! self::exists( $parent_id ) ) {
			return self::not_found();
		}

		$term = wp_insert_term( $name, self::TAXONOMY, array( 'parent' => $parent_id ) );

		return is_wp_error( $term ) ? self::with_status( $term ) : (int) $term['term_id'];
	}

	/**
	 * Renames a folder, moves it under another one, or both.
	 *
	 * @param int         $id     Folder ID.
	 * @param string|null $name   New name, or null to keep it.
	 * @param int|null    $parent_id New parent, 0 for the top level, or null to keep it.
	 *
	 * @return true|\WP_Error
	 */
	public static function update( int $id, ?string $name = null, ?int $parent_id = null ) {
		if ( ! self::exists( $id ) ) {
			return self::not_found();
		}

		$args = array();

		if ( null !== $name ) {
			$name = trim( $name );
			if ( '' === $name ) {
				return new \WP_Error( 'jcore_kirjasto_folder_name', __( 'A folder needs a name.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
			}
			$args['name'] = $name;
		}

		if ( null !== $parent_id ) {
			if ( $parent_id && ! self::exists( $parent_id ) ) {
				return self::not_found();
			}

			if ( $parent_id === $id || in_array( $parent_id, self::descendants( $id ), true ) ) {
				return new \WP_Error( 'jcore_kirjasto_folder_loop', __( 'A folder cannot be moved into itself.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
			}
			$args['parent'] = $parent_id;
		}

		$result = wp_update_term( $id, self::TAXONOMY, $args );

		return is_wp_error( $result ) ? self::with_status( $result ) : true;
	}

	/**
	 * Deletes a folder. Its attachments and subfolders move to its parent, so
	 * nothing loses its place in the tree altogether.
	 *
	 * @param int $id Folder ID.
	 *
	 * @return true|\WP_Error
	 */
	public static function delete( int $id ) {
		$term = get_term( $id, self::TAXONOMY );
		if ( ! $term instanceof \WP_Term ) {
			return self::not_found();
		}

		// Core moves the subfolders up; `default` moves the attachments.
		$args   = $term->parent ? array( 'default' => (int) $term->parent ) : array();
		$result = wp_delete_term( $id, self::TAXONOMY, $args );

		return is_wp_error( $result ) ? self::with_status( $result ) : true;
	}

	/**
	 * Moves attachments into a folder, together with their translations.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 * @param int   $folder         Folder ID, or 0 to take them out of their folder.
	 *
	 * @return int[]|\WP_Error The IDs that were moved.
	 */
	public static function move( array $attachment_ids, int $folder ) {
		if ( $folder && ! self::exists( $folder ) ) {
			return self::not_found();
		}

		$moved = array();
		foreach ( self::with_translations( $attachment_ids ) as $id ) {
			$result = wp_set_object_terms( $id, $folder ? array( $folder ) : array(), self::TAXONOMY );

			if ( ! is_wp_error( $result ) ) {
				$moved[] = $id;
			}
		}

		return $moved;
	}

	/**
	 * The folder an attachment is in.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return int Folder ID, or 0.
	 */
	public static function of( int $attachment_id ): int {
		$terms = get_the_terms( $attachment_id, self::TAXONOMY );

		return is_array( $terms ) && $terms ? (int) $terms[0]->term_id : 0;
	}

	/**
	 * Whether a folder exists.
	 *
	 * @param int $id Folder ID.
	 *
	 * @return bool
	 */
	public static function exists( int $id ): bool {
		return $id > 0 && get_term( $id, self::TAXONOMY ) instanceof \WP_Term;
	}

	/**
	 * The `tax_query` clause selecting the attachments directly in a folder.
	 *
	 * @param int $folder Folder ID, or 0 for attachments without a folder.
	 *
	 * @return array<string, mixed>
	 */
	public static function tax_query( int $folder ): array {
		if ( 0 === $folder ) {
			return array(
				'taxonomy' => self::TAXONOMY,
				'operator' => 'NOT EXISTS',
			);
		}

		return array(
			'taxonomy'         => self::TAXONOMY,
			'terms'            => array( $folder ),
			'include_children' => false,
		);
	}

	/**
	 * Reads a requested folder: a folder ID, 0 for "no folder", or null for
	 * no folder filter at all.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return int|null
	 */
	public static function requested( mixed $value ): ?int {
		if ( ( is_string( $value ) && ctype_digit( $value ) ) || ( is_int( $value ) && $value >= 0 ) ) {
			return (int) $value;
		}

		return null;
	}

	/**
	 * Adds the folder taxonomy to the ones Polylang copies to a new translation.
	 *
	 * @param string[] $taxonomies Taxonomies to copy.
	 *
	 * @return string[]
	 */
	public static function copy_to_translations( $taxonomies ): array {
		$taxonomies   = (array) $taxonomies;
		$taxonomies[] = self::TAXONOMY;

		return $taxonomies;
	}

	/**
	 * The IDs of all subfolders of a folder, however deep.
	 *
	 * @param int $id Folder ID.
	 *
	 * @return int[]
	 */
	private static function descendants( int $id ): array {
		$children = get_term_children( $id, self::TAXONOMY );

		return is_array( $children ) ? array_map( 'intval', $children ) : array();
	}

	/**
	 * Adds the translations of the given attachments, when Polylang translates media.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 *
	 * @return int[]
	 */
	private static function with_translations( array $attachment_ids ): array {
		$ids = array_map( 'intval', $attachment_ids );

		if ( function_exists( 'pll_get_post_translations' ) ) {
			foreach ( $attachment_ids as $id ) {
				$ids = array_merge( $ids, array_map( 'intval', pll_get_post_translations( (int) $id ) ) );
			}
		}

		return array_values( array_unique( array_filter( $ids ) ) );
	}

	/**
	 * Limits counts to the language the media library is filtered by, when
	 * Polylang translates media: the translations are separate attachments,
	 * and the library shows one language at a time.
	 *
	 * @return string SQL to append to a WHERE clause on `p`, or an empty string.
	 */
	private static function language_clause(): string {
		global $wpdb;

		if ( ! function_exists( 'pll_is_translated_post_type' ) || ! pll_is_translated_post_type( 'attachment' ) ) {
			return '';
		}

		$slug = (string) get_user_meta( get_current_user_id(), 'pll_filter_content', true );
		$term = '' !== $slug ? get_term_by( 'slug', $slug, 'language' ) : false;

		if ( ! $term instanceof \WP_Term ) {
			return '';
		}

		return $wpdb->prepare(
			' AND p.ID IN (SELECT object_id FROM %i WHERE term_taxonomy_id = %d)',
			$wpdb->term_relationships,
			$term->term_taxonomy_id
		);
	}

	/**
	 * The error for a folder that does not exist.
	 *
	 * @return \WP_Error
	 */
	private static function not_found(): \WP_Error {
		return new \WP_Error( 'jcore_kirjasto_folder_not_found', __( 'The folder does not exist.', 'jcore-kirjasto' ), array( 'status' => 404 ) );
	}

	/**
	 * Gives a core term error an HTTP status, so REST responses carry it, and
	 * says "folder" where core says "term".
	 *
	 * @param \WP_Error $error Error from the term API.
	 *
	 * @return \WP_Error
	 */
	private static function with_status( \WP_Error $error ): \WP_Error {
		if ( in_array( $error->get_error_code(), array( 'term_exists', 'duplicate_term_slug' ), true ) ) {
			return new \WP_Error( 'jcore_kirjasto_folder_exists', __( 'There is already a folder with this name here.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
		}

		$error->add_data( array( 'status' => 400 ) );

		return $error;
	}
}
