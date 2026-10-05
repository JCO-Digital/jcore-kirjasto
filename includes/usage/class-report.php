<?php
/**
 * Reads the usage index for display.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Answers "where is this attachment used?" for the current user.
 *
 * References are grouped per object and resolved to a title, a link and the
 * places on the object the attachment appears. Objects the user may neither
 * edit nor view are counted but not named.
 */
final class Report {

	/**
	 * Index rows per attachment, filled by prime() and rows().
	 *
	 * @var array<int, object[]>
	 */
	private static array $rows = array();

	/**
	 * Loads the rows of many attachments in one query, e.g. for a list screen.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 *
	 * @return void
	 */
	public static function prime( array $attachment_ids ): void {
		global $wpdb;

		$ids = array_diff( array_unique( array_map( 'intval', $attachment_ids ) ), array_keys( self::$rows ) );
		if ( empty( $ids ) ) {
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %d.
				"SELECT attachment_id, object_type, object_id, context, source FROM %i WHERE attachment_id IN ($placeholders) ORDER BY object_type, object_id, id",
				Database::table( 'usage' ),
				...$ids
			)
		);

		foreach ( $ids as $id ) {
			self::$rows[ $id ] = array();
		}

		$post_ids = array();
		foreach ( $rows as $row ) {
			self::$rows[ (int) $row->attachment_id ][] = $row;

			if ( 'post' === $row->object_type ) {
				$post_ids[] = (int) $row->object_id;
			}
		}

		if ( $post_ids ) {
			_prime_post_caches( array_unique( $post_ids ), false, false );
		}
	}

	/**
	 * The index rows of one attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return object[]
	 */
	public static function rows( int $attachment_id ): array {
		self::prime( array( $attachment_id ) );

		return self::$rows[ $attachment_id ];
	}

	/**
	 * Where an attachment is used, as far as the current user may know.
	 *
	 * @param int $attachment_id Attachment ID.
	 *
	 * @return array{items: array<int, array<string, mixed>>, hidden: int}
	 */
	public static function items( int $attachment_id ): array {
		$groups = array();
		foreach ( self::rows( $attachment_id ) as $row ) {
			$groups[ self::group_key( $row ) ][] = $row;
		}

		$items  = array();
		$hidden = 0;
		foreach ( $groups as $rows ) {
			$item = self::resolve( $rows );

			if ( null === $item ) {
				++$hidden;
				continue;
			}

			$item['contexts'] = array_values( array_unique( array_map( array( self::class, 'context_label' ), $rows ) ) );
			$items[]          = $item;
		}

		return array(
			'items'  => $items,
			'hidden' => $hidden,
		);
	}

	/**
	 * Renders the usage of an attachment as an HTML list.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $limit         Items to show before "and N more"; 0 for all.
	 *
	 * @return string
	 */
	public static function render( int $attachment_id, int $limit = 0 ): string {
		$usage = self::items( $attachment_id );
		$items = $usage['items'];
		$html  = '';

		if ( ! Rebuild::is_built() ) {
			$html .= '<p class="jcore-kirjasto-usage__notice">' . esc_html__( 'The usage index is still being built, so this list may be incomplete.', 'jcore-kirjasto' ) . '</p>';
		}

		if ( empty( $items ) && 0 === $usage['hidden'] ) {
			return $html . '<span class="jcore-kirjasto-usage__none">' . esc_html__( 'Not used anywhere', 'jcore-kirjasto' ) . '</span>';
		}

		$shown = $limit > 0 ? array_slice( $items, 0, $limit ) : $items;

		if ( $shown ) {
			$html .= '<ul class="jcore-kirjasto-usage">';
			foreach ( $shown as $item ) {
				$html .= '<li class="jcore-kirjasto-usage__item">' . self::render_item( $item ) . '</li>';
			}
			$html .= '</ul>';
		}

		$more = count( $items ) - count( $shown );
		if ( $more > 0 ) {
			$html .= sprintf(
				'<a class="jcore-kirjasto-usage__more" href="%s">%s</a>',
				esc_url( (string) get_edit_post_link( $attachment_id ) ),
				esc_html(
					sprintf(
						/* translators: %d: number of further places the attachment is used in. */
						_n( 'and %d more', 'and %d more', $more, 'jcore-kirjasto' ),
						$more
					)
				)
			);
		}

		if ( $usage['hidden'] > 0 ) {
			$html .= '<p class="jcore-kirjasto-usage__hidden">' . esc_html(
				sprintf(
					/* translators: %d: number of places the current user may not see. */
					_n(
						'Also used in %d item you do not have access to.',
						'Also used in %d items you do not have access to.',
						$usage['hidden'],
						'jcore-kirjasto'
					),
					$usage['hidden']
				)
			) . '</p>';
		}

		return $html;
	}

	/**
	 * Renders one used-in entry: title, badges and where on the object.
	 *
	 * @param array<string, mixed> $item Resolved item.
	 *
	 * @return string
	 */
	private static function render_item( array $item ): string {
		$title = esc_html( $item['title'] );
		if ( $item['url'] ) {
			$title = sprintf( '<a href="%s">%s</a>', esc_url( $item['url'] ), $title );
		}

		$badges = '';
		foreach ( array( $item['status'], $item['language'] ) as $badge ) {
			if ( $badge ) {
				$badges .= ' <span class="jcore-kirjasto-usage__badge">' . esc_html( $badge ) . '</span>';
			}
		}

		$meta = implode( ' · ', array_filter( array( $item['type'], implode( ', ', $item['contexts'] ) ) ) );

		return $title . $badges . '<span class="jcore-kirjasto-usage__meta">' . esc_html( $meta ) . '</span>';
	}

	/**
	 * Which rows belong to the same entry. Settings are split per options
	 * page, so each gets its own title and link, and theme files per theme.
	 *
	 * @param object $row Index row.
	 *
	 * @return string
	 */
	private static function group_key( object $row ): string {
		if ( 'theme' === $row->object_type ) {
			return 'theme:' . strtok( $row->source, '/' );
		}

		if ( 'option' !== $row->object_type ) {
			return $row->object_type . ':' . $row->object_id;
		}

		if ( 'field' === $row->context ) {
			$page = self::options_page( $row->source );

			return 'option:' . ( $page['menu_slug'] ?? 'fields' );
		}

		return 'option:' . $row->context . ':' . $row->source;
	}

	/**
	 * Resolves a group of rows to the object they point at.
	 *
	 * @param object[] $rows Rows of one object.
	 *
	 * @return array<string, mixed>|null Null when the user may not see the object.
	 */
	private static function resolve( array $rows ): ?array {
		$row  = $rows[0];
		$item = array(
			'title'    => '',
			'url'      => '',
			'type'     => '',
			'status'   => '',
			'language' => '',
		);

		switch ( $row->object_type ) {
			case 'post':
				return self::resolve_post( (int) $row->object_id, $item );

			case 'term':
				return self::resolve_term( (int) $row->object_id, $item );

			case 'theme':
				return self::resolve_theme( $row, $item );

			default:
				return self::resolve_setting( $row, $item );
		}
	}

	/**
	 * Resolves a post.
	 *
	 * @param int                  $post_id Post ID.
	 * @param array<string, mixed> $item    Item defaults.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolve_post( int $post_id, array $item ): ?array {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		$can_edit = current_user_can( 'edit_post', $post->ID );
		$is_open  = 'publish' === $post->post_status && is_post_type_viewable( $post->post_type );

		if ( ! $can_edit && ! $is_open ) {
			return null;
		}

		$type   = get_post_type_object( $post->post_type );
		$status = get_post_status_object( $post->post_status );

		// The raw title: get_the_title() would prefix "Private:", which the badge already says.
		$item['title']  = '' !== trim( $post->post_title ) ? $post->post_title : __( '(no title)', 'jcore-kirjasto' );
		$item['url']    = $can_edit ? (string) get_edit_post_link( $post->ID, 'raw' ) : (string) get_permalink( $post );
		$item['type']   = $type ? $type->labels->singular_name : $post->post_type;
		$item['status'] = 'publish' !== $post->post_status && $status ? $status->label : '';

		if ( function_exists( 'pll_get_post_language' ) ) {
			$item['language'] = strtoupper( (string) pll_get_post_language( $post->ID ) );
		}

		return $item;
	}

	/**
	 * Resolves a term.
	 *
	 * @param int                  $term_id Term ID.
	 * @param array<string, mixed> $item    Item defaults.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function resolve_term( int $term_id, array $item ): ?array {
		$term = get_term( $term_id );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}

		$can_edit = current_user_can( 'edit_term', $term->term_id );
		if ( ! $can_edit && ! is_taxonomy_viewable( $term->taxonomy ) ) {
			return null;
		}

		$taxonomy = get_taxonomy( $term->taxonomy );
		$link     = $can_edit ? get_edit_term_link( $term ) : get_term_link( $term );

		$item['title'] = $term->name;
		$item['url']   = is_string( $link ) ? $link : '';
		$item['type']  = $taxonomy ? $taxonomy->labels->singular_name : $term->taxonomy;

		if ( function_exists( 'pll_get_term_language' ) ) {
			$item['language'] = strtoupper( (string) pll_get_term_language( $term->term_id ) );
		}

		return $item;
	}

	/**
	 * Resolves a theme. Like settings, themes are always listed.
	 *
	 * @param object               $row  First row of the group.
	 * @param array<string, mixed> $item Item defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_theme( object $row, array $item ): array {
		$slug  = (string) strtok( $row->source, '/' );
		$theme = wp_get_theme( $slug );

		$item['title'] = $theme->exists() ? $theme->display( 'Name', false ) : $slug;
		$item['url']   = current_user_can( 'switch_themes' ) ? admin_url( 'themes.php?theme=' . rawurlencode( $slug ) ) : '';
		$item['type']  = __( 'Theme', 'jcore-kirjasto' );

		return $item;
	}

	/**
	 * Resolves a site setting. Settings are public knowledge, so they are
	 * always listed, only without a link for users who cannot change them.
	 *
	 * @param object               $row  First row of the group.
	 * @param array<string, mixed> $item Item defaults.
	 *
	 * @return array<string, mixed>
	 */
	private static function resolve_setting( object $row, array $item ): array {
		$item['type'] = __( 'Settings', 'jcore-kirjasto' );

		switch ( $row->context ) {
			case 'option':
				$is_yoast      = str_starts_with( $row->source, 'wpseo' );
				$item['title'] = $is_yoast ? __( 'Yoast SEO', 'jcore-kirjasto' ) : $row->source;
				$item['url']   = $is_yoast && current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=wpseo_page_settings' ) : '';
				break;

			case 'site_icon':
				$item['title'] = __( 'Site icon', 'jcore-kirjasto' );
				$item['url']   = current_user_can( 'manage_options' ) ? admin_url( 'options-general.php' ) : '';
				break;

			case 'custom_logo':
				$item['title'] = __( 'Site logo', 'jcore-kirjasto' );
				if ( wp_is_block_theme() ) {
					$item['url'] = current_user_can( 'edit_theme_options' ) ? admin_url( 'site-editor.php' ) : '';
				} else {
					$item['url'] = current_user_can( 'customize' ) ? admin_url( 'customize.php?autofocus[control]=custom_logo' ) : '';
				}
				break;

			default:
				$page          = self::options_page( $row->source );
				$item['title'] = $page['page_title'] ?? __( 'Options', 'jcore-kirjasto' );
				$item['url']   = $page && current_user_can( $page['capability'] ?? 'manage_options' )
					? admin_url( 'admin.php?page=' . $page['menu_slug'] )
					: '';
		}

		return $item;
	}

	/**
	 * Describes where on the object a row's reference sits.
	 *
	 * @param object $row Index row.
	 *
	 * @return string
	 */
	private static function context_label( object $row ): string {
		switch ( $row->context ) {
			case 'content':
				return __( 'Content', 'jcore-kirjasto' );

			case 'featured':
				return __( 'Featured image', 'jcore-kirjasto' );

			case 'site_icon':
			case 'custom_logo':
				return '';

			case 'option':
				return __( 'Default social image', 'jcore-kirjasto' );

			case 'meta':
				return self::meta_label( $row->source );

			case 'file':
				// The path within the theme; the theme is the entry's title.
				return (string) substr( $row->source, strpos( $row->source, '/' ) + 1 );

			case 'field':
				$field = Scanner::acf_field( $row->source );

				if ( ! $field ) {
					return $row->source;
				}

				return '' !== (string) $field['label'] ? $field['label'] : $field['name'];

			default:
				return $row->source;
		}
	}

	/**
	 * A readable name for a meta key.
	 *
	 * @param string $key Meta key.
	 *
	 * @return string
	 */
	private static function meta_label( string $key ): string {
		$labels = array(
			'_product_image_gallery'          => __( 'Product gallery', 'jcore-kirjasto' ),
			'_yoast_wpseo_opengraph-image-id' => __( 'Social image', 'jcore-kirjasto' ),
			'_yoast_wpseo_twitter-image-id'   => __( 'X image', 'jcore-kirjasto' ),
			'thumbnail_id'                    => __( 'Thumbnail', 'jcore-kirjasto' ),
		);

		/**
		 * Filters the labels shown for meta keys registered through
		 * `jcore_kirjasto_meta_keys`.
		 *
		 * @param array<string, string> $labels Labels, keyed by meta key.
		 */
		$labels = apply_filters( 'jcore_kirjasto_meta_labels', $labels );

		return $labels[ $key ] ?? $key;
	}

	/**
	 * The ACF options page a field is shown on, found through the location
	 * rules of the field group it, or the field it is nested in, belongs to.
	 *
	 * @param string $field_key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	private static function options_page( string $field_key ): ?array {
		static $pages = array();

		if ( array_key_exists( $field_key, $pages ) ) {
			return $pages[ $field_key ];
		}

		$pages[ $field_key ] = null;

		if ( ! function_exists( 'acf_get_field_group' ) || ! function_exists( 'acf_get_options_page' ) ) {
			return null;
		}

		$field = Scanner::acf_field( $field_key );
		$group = null;

		for ( $depth = 0; $field && ! $group && $depth < 10; $depth++ ) {
			$parent = $field['parent'] ?? 0;
			$group  = $parent ? acf_get_field_group( $parent ) : null;
			$field  = ! $group && $parent ? acf_get_field( $parent ) : null;
		}

		foreach ( $group['location'] ?? array() as $rules ) {
			foreach ( $rules as $rule ) {
				if ( 'options_page' === ( $rule['param'] ?? '' ) && '==' === ( $rule['operator'] ?? '' ) ) {
					$page = acf_get_options_page( $rule['value'] );
					if ( $page ) {
						$pages[ $field_key ] = $page;

						return $page;
					}
				}
			}
		}

		return null;
	}
}
