<?php
/**
 * Usage information inside the media library itself.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage\Admin;

use Jcore\Kirjasto\Assets;
use Jcore\Kirjasto\Database;
use Jcore\Kirjasto\Usage\Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Used in" column, the used/unused filter and the "Used in" field
 * of the attachment details.
 */
final class Media_Library {

	/**
	 * Name of the list column, the attachment field and the filter query var.
	 */
	public const KEY = 'jcore_kirjasto_usage';

	/**
	 * Build entry the media library assets come from.
	 */
	private const ENTRY = 'usage-media';

	/**
	 * Entries the list column shows before linking to the full list.
	 */
	private const COLUMN_LIMIT = 3;

	/**
	 * Hooks the media library screens.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'manage_media_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_media_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'attachment_fields_to_edit', array( self::class, 'add_field' ), 10, 2 );

		add_action( 'restrict_manage_posts', array( self::class, 'render_filter' ) );
		add_action( 'pre_get_posts', array( self::class, 'filter_list_query' ) );
		add_filter( 'ajax_query_attachments_args', array( self::class, 'filter_grid_query' ) );
		add_filter( 'posts_where', array( self::class, 'posts_where' ), 10, 2 );
		add_filter( 'the_posts', array( self::class, 'prime' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_style' ) );
	}

	/**
	 * Adds the "Used in" column after "Uploaded to".
	 *
	 * @param array<string, string> $columns List table columns.
	 *
	 * @return array<string, string>
	 */
	public static function add_column( array $columns ): array {
		$added = array();

		foreach ( $columns as $key => $label ) {
			$added[ $key ] = $label;

			if ( 'parent' === $key ) {
				$added[ self::KEY ] = __( 'Used in', 'jcore-kirjasto' );
			}
		}

		if ( ! isset( $added[ self::KEY ] ) ) {
			$added[ self::KEY ] = __( 'Used in', 'jcore-kirjasto' );
		}

		return $added;
	}

	/**
	 * Renders the "Used in" column.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Attachment ID.
	 *
	 * @return void
	 */
	public static function render_column( $column, $post_id ): void {
		if ( self::KEY === $column ) {
			echo Report::render( (int) $post_id, self::COLUMN_LIMIT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while rendering.
		}
	}

	/**
	 * Adds a read-only "Used in" field to the attachment details, shown in the
	 * media modal and on the attachment edit screen.
	 *
	 * @param array<string, array<string, mixed>> $fields Attachment fields.
	 * @param \WP_Post                            $post   The attachment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_field( $fields, $post ): array {
		$fields = (array) $fields;

		if ( $post instanceof \WP_Post ) {
			$fields[ self::KEY ] = array(
				'label'         => __( 'Used in', 'jcore-kirjasto' ),
				'input'         => 'html',
				'html'          => Report::render( $post->ID ),
				'show_in_edit'  => true,
				'show_in_modal' => true,
			);
		}

		return $fields;
	}

	/**
	 * Outputs the used/unused dropdown in the list mode toolbar.
	 *
	 * @param string $post_type The listed post type.
	 *
	 * @return void
	 */
	public static function render_filter( $post_type ): void {
		if ( 'attachment' !== $post_type ) {
			return;
		}

		$current = self::requested_filter( $_GET[ self::KEY ] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		$options = array(
			''       => __( 'All usage', 'jcore-kirjasto' ),
			'used'   => __( 'Used', 'jcore-kirjasto' ),
			'unused' => __( 'Not used', 'jcore-kirjasto' ),
		);

		printf(
			'<label for="%1$s" class="screen-reader-text">%2$s</label><select name="%3$s" id="%1$s">',
			'jcore-kirjasto-usage-filter',
			esc_html__( 'Filter by usage', 'jcore-kirjasto' ),
			esc_attr( self::KEY )
		);

		foreach ( $options as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $current, $value, false ), esc_html( $label ) );
		}

		echo '</select>';
	}

	/**
	 * Applies the list mode filter to the main query of the Media screen.
	 *
	 * @param \WP_Query $query The query.
	 *
	 * @return void
	 */
	public static function filter_list_query( $query ): void {
		global $pagenow;

		if ( ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}

		$filter = self::requested_filter( $_GET[ self::KEY ] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only list filter.
		if ( $filter ) {
			$query->set( self::KEY, $filter );
		}
	}

	/**
	 * Applies the grid mode filter. Core drops query keys it does not know
	 * before this filter runs, so the value is read from the request.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function filter_grid_query( $args ): array {
		$args = (array) $args;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by core; validated by requested_filter().
		$query  = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array();
		$filter = self::requested_filter( $query[ self::KEY ] ?? '' );

		if ( $filter ) {
			$args[ self::KEY ] = $filter;
		}

		return $args;
	}

	/**
	 * Restricts an attachment query to used or unused attachments.
	 *
	 * @param string    $where WHERE clause.
	 * @param \WP_Query $query The query.
	 *
	 * @return string
	 */
	public static function posts_where( $where, $query ): string {
		global $wpdb;

		$filter = $query instanceof \WP_Query ? $query->get( self::KEY ) : '';
		if ( ! in_array( $filter, array( 'used', 'unused' ), true ) ) {
			return (string) $where;
		}

		$operator = 'used' === $filter ? 'IN' : 'NOT IN';

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $operator is one of two literals.
		return $where . $wpdb->prepare( " AND {$wpdb->posts}.ID {$operator} (SELECT attachment_id FROM %i)", Database::table( 'usage' ) );
	}

	/**
	 * Loads the usage of a whole page of attachments in one query, before the
	 * list table or the grid renders them one by one.
	 *
	 * @param \WP_Post[] $posts Found posts.
	 * @param \WP_Query  $query The query.
	 *
	 * @return \WP_Post[]
	 */
	public static function prime( $posts, $query ): array {
		global $pagenow;

		$posts = (array) $posts;

		$is_list = 'upload.php' === $pagenow && $query instanceof \WP_Query && $query->is_main_query();
		$is_grid = wp_doing_ajax() && 'query-attachments' === ( $_REQUEST['action'] ?? '' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $posts && ( $is_list || $is_grid ) ) {
			Report::prime( wp_list_pluck( $posts, 'ID' ) );
		}

		return $posts;
	}

	/**
	 * Enqueues the style on the Media screen and attachment edit screen, and
	 * the grid filter in grid mode.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		$is_attachment = 'post.php' === $hook && 'attachment' === get_current_screen()?->post_type;

		if ( 'upload.php' !== $hook && ! $is_attachment ) {
			return;
		}

		self::enqueue_style();

		// Core enqueues media-grid before this hook, and only in grid mode.
		if ( wp_script_is( 'media-grid', 'enqueued' ) ) {
			Assets::enqueue_script( self::ENTRY, array( 'media-views' ) );
		}
	}

	/**
	 * Enqueues the style of the usage list, wherever the media modal can open.
	 *
	 * @return void
	 */
	public static function enqueue_style(): void {
		Assets::enqueue_style( self::ENTRY );
	}

	/**
	 * Validates a requested filter value.
	 *
	 * @param mixed $value Raw value.
	 *
	 * @return string `used`, `unused` or an empty string.
	 */
	private static function requested_filter( mixed $value ): string {
		return is_string( $value ) && in_array( $value, array( 'used', 'unused' ), true ) ? $value : '';
	}
}
