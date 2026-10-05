<?php
/**
 * Keeps the usage table in step with the content.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Writes the references the scanner finds, and re-indexes whatever changes.
 *
 * Changes are queued and indexed once on `shutdown`, so a save that touches
 * twenty meta keys scans the post once.
 */
final class Indexer {

	/**
	 * Post statuses that are not indexed. Their references are removed.
	 */
	public const EXCLUDED_STATUSES = array( 'auto-draft', 'trash', 'inherit' );

	/**
	 * Post types that are never indexed.
	 */
	private const EXCLUDED_POST_TYPES = array(
		'attachment',
		'revision',
		'customize_changeset',
		'oembed_cache',
		'user_request',
		'wp_font_family',
		'wp_font_face',
		'acf-field',
		'acf-field-group',
		'acf-post-type',
		'acf-taxonomy',
		'acf-ui-options-page',
		'scheduled-action',
	);

	/**
	 * Meta keys that change all the time and never hold an attachment.
	 */
	private const IGNORED_META_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_encloseme',
		'_pingme',
		'_wp_old_slug',
		'_wp_old_date',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
	);

	/**
	 * Objects waiting to be indexed on shutdown.
	 *
	 * @var array{post: array<int, true>, term: array<int, true>, settings: bool, theme: bool}
	 */
	private static array $queue = array(
		'post'     => array(),
		'term'     => array(),
		'settings' => false,
		'theme'    => false,
	);

	/**
	 * Hooks the indexer to every change that can add or remove a reference.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'wp_after_insert_post', array( self::class, 'queue_post' ) );
		add_action( 'deleted_post', array( self::class, 'post_deleted' ), 10, 2 );
		add_action( 'delete_term', array( self::class, 'term_deleted' ) );

		foreach ( array( 'added', 'updated', 'deleted' ) as $action ) {
			add_action( "{$action}_post_meta", array( self::class, 'post_meta_changed' ), 10, 3 );
			add_action( "{$action}_term_meta", array( self::class, 'term_meta_changed' ), 10, 2 );
			add_action( "{$action}_option", array( self::class, 'option_changed' ) );
		}

		add_action( 'switch_theme', array( self::class, 'queue_theme' ) );
		add_action( 'upgrader_process_complete', array( self::class, 'upgrader_finished' ), 10, 2 );

		// Theme files also change through deploys, so the screens showing usage check them.
		foreach ( array( 'load-upload.php', 'load-post.php', 'load-media_page_' . Admin\Page::SLUG ) as $screen ) {
			add_action( $screen, array( Theme_Files::class, 'maybe_reindex' ) );
		}
		add_action( 'wp_ajax_query-attachments', array( Theme_Files::class, 'maybe_reindex' ), 0 );

		add_action( 'shutdown', array( self::class, 'process_queue' ) );
	}

	/**
	 * The post types whose posts are indexed.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		$types = array_values( array_diff( get_post_types(), self::EXCLUDED_POST_TYPES ) );

		/**
		 * Filters the post types whose posts are scanned for attachments.
		 *
		 * @param string[] $types Post type names.
		 */
		return apply_filters( 'jcore_kirjasto_post_types', $types );
	}

	/**
	 * Queues a post for indexing.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function queue_post( int $post_id ): void {
		self::$queue['post'][ $post_id ] = true;
	}

	/**
	 * Queues a post when one of its meta values changes.
	 *
	 * @param int|int[] $meta_id   Meta ID, or IDs on delete.
	 * @param int       $object_id Post ID.
	 * @param string    $meta_key  Meta key.
	 *
	 * @return void
	 */
	public static function post_meta_changed( $meta_id, $object_id, $meta_key ): void {
		if ( ! in_array( $meta_key, self::IGNORED_META_KEYS, true ) ) {
			self::queue_post( (int) $object_id );
		}
	}

	/**
	 * Queues a term when one of its meta values changes.
	 *
	 * @param int|int[] $meta_id   Meta ID, or IDs on delete.
	 * @param int       $object_id Term ID.
	 *
	 * @return void
	 */
	public static function term_meta_changed( $meta_id, $object_id ): void {
		self::$queue['term'][ (int) $object_id ] = true;
	}

	/**
	 * Queues the settings when an option they are read from changes.
	 *
	 * @param string $option Option name.
	 *
	 * @return void
	 */
	public static function option_changed( $option ): void {
		$option = (string) $option;

		if ( self::$queue['settings'] || str_starts_with( $option, '_transient' ) || str_starts_with( $option, '_site_transient' ) ) {
			return;
		}

		if ( Scanner::is_settings_option( $option ) ) {
			self::$queue['settings'] = true;
		}
	}

	/**
	 * Queues the theme for indexing.
	 *
	 * @return void
	 */
	public static function queue_theme(): void {
		self::$queue['theme'] = true;
	}

	/**
	 * Queues the theme after themes were installed or updated.
	 *
	 * @param \WP_Upgrader         $upgrader Upgrader instance.
	 * @param array<string, mixed> $extra    What was upgraded.
	 *
	 * @return void
	 */
	public static function upgrader_finished( $upgrader, $extra ): void {
		if ( 'theme' === ( $extra['type'] ?? '' ) ) {
			self::queue_theme();
		}
	}

	/**
	 * Drops the references a deleted post made, or those made to a deleted
	 * attachment.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    The deleted post.
	 *
	 * @return void
	 */
	public static function post_deleted( $post_id, $post ): void {
		global $wpdb;

		unset( self::$queue['post'][ (int) $post_id ] );

		if ( $post instanceof \WP_Post && 'attachment' === $post->post_type ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$wpdb->delete( Database::table( 'usage' ), array( 'attachment_id' => (int) $post_id ), array( '%d' ) );
			return;
		}

		if ( $post instanceof \WP_Post && in_array( $post->post_type, self::post_types(), true ) ) {
			self::write( 'post', (int) $post_id, array() );
		}
	}

	/**
	 * Drops the references a deleted term made.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return void
	 */
	public static function term_deleted( $term_id ): void {
		unset( self::$queue['term'][ (int) $term_id ] );

		self::write( 'term', (int) $term_id, array() );
	}

	/**
	 * Indexes everything queued during this request.
	 *
	 * @return void
	 */
	public static function process_queue(): void {
		$queue       = self::$queue;
		self::$queue = array(
			'post'     => array(),
			'term'     => array(),
			'settings' => false,
			'theme'    => false,
		);

		foreach ( array_keys( $queue['post'] ) as $post_id ) {
			self::index_post( $post_id );
		}

		foreach ( array_keys( $queue['term'] ) as $term_id ) {
			self::index_term( $term_id );
		}

		if ( $queue['settings'] ) {
			self::index_settings();
		}

		if ( $queue['theme'] ) {
			self::index_theme();
		}
	}

	/**
	 * Re-indexes one post. A post that is no longer indexable loses its references.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return void
	 */
	public static function index_post( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post || ! in_array( $post->post_type, self::post_types(), true ) ) {
			return;
		}

		if ( in_array( $post->post_status, self::EXCLUDED_STATUSES, true ) ) {
			self::write( 'post', $post->ID, array() );
			return;
		}

		self::write( 'post', $post->ID, Scanner::post( $post ) );
	}

	/**
	 * Re-indexes one term.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return void
	 */
	public static function index_term( int $term_id ): void {
		self::write( 'term', $term_id, Scanner::term( $term_id ) );
	}

	/**
	 * Re-indexes the site settings.
	 *
	 * @return void
	 */
	public static function index_settings(): void {
		self::write( 'option', 0, Scanner::settings() );
	}

	/**
	 * Re-indexes the files of the active theme, and records their fingerprint.
	 *
	 * @return void
	 */
	public static function index_theme(): void {
		self::write( 'theme', 0, Scanner::theme() );

		update_option( Theme_Files::FINGERPRINT_OPTION, Theme_Files::fingerprint(), false );
	}

	/**
	 * Replaces the references an object makes.
	 *
	 * IDs that are not attachments are dropped here, in one query, so the
	 * scanner can be generous about what it picks up.
	 *
	 * @param string $object_type `post`, `term`, `option` or `theme`.
	 * @param int    $object_id   Object ID; 0 for the settings and the theme.
	 * @param array  $refs        References: `attachment_id`, `context` and `source`.
	 *
	 * @return void
	 */
	private static function write( string $object_type, int $object_id, array $refs ): void {
		global $wpdb;

		$table = Database::table( 'usage' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete(
			$table,
			array(
				'object_type' => $object_type,
				'object_id'   => $object_id,
			),
			array( '%s', '%d' )
		);

		$attachments = self::existing_attachments( array_column( $refs, 'attachment_id' ) );
		if ( empty( $attachments ) ) {
			return;
		}

		$now          = current_time( 'mysql', true );
		$placeholders = array();
		$values       = array();
		$seen         = array();

		foreach ( $refs as $ref ) {
			$id     = (int) $ref['attachment_id'];
			$source = substr( (string) $ref['source'], 0, 191 );
			$key    = $id . '|' . $ref['context'] . '|' . $source;

			if ( ! isset( $attachments[ $id ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$placeholders[] = '(%d, %s, %d, %s, %s, %s)';
			array_push( $values, $id, $object_type, $object_id, (string) $ref['context'], $source, $now );
		}

		$placeholders = implode( ', ', $placeholders );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of value tuples.
				"INSERT IGNORE INTO %i (attachment_id, object_type, object_id, context, source, indexed_at) VALUES $placeholders",
				$table,
				...$values
			)
		);
	}

	/**
	 * Returns the given IDs that belong to attachments, as array keys.
	 *
	 * @param int[] $ids Candidate IDs.
	 *
	 * @return array<int, true>
	 */
	private static function existing_attachments( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return array();
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$found = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d.
				"SELECT ID FROM %i WHERE post_type = 'attachment' AND ID IN ($placeholders)",
				$wpdb->posts,
				...$ids
			)
		);

		return array_fill_keys( array_map( 'intval', $found ), true );
	}
}
