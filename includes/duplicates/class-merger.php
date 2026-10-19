<?php
/**
 * Merges duplicates into one attachment.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

use Jcore\Kirjasto\Attachment_Files;
use Jcore\Kirjasto\Database;
use Jcore\Kirjasto\Plugin;
use Jcore\Kirjasto\Usage\Indexer as Usage_Indexer;
use Jcore\Kirjasto\Usage\Rebuild;
use Jcore\Kirjasto\Usage\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points everything that uses a duplicate at the attachment that is kept,
 * then deletes the duplicate.
 *
 * The usage index says where each duplicate is used. Each of those places is
 * rewritten and indexed again, and a duplicate is deleted only once the index
 * no longer lists it anywhere: whatever could not be rewritten, such as an ID
 * written into theme code, keeps it in place.
 *
 * Where Polylang translates media, each duplicate is merged into the kept
 * attachment of its own language.
 */
final class Merger {

	/**
	 * Whether merging can be trusted: the usage feature is on and its index
	 * is complete.
	 *
	 * @return true|\WP_Error
	 */
	public static function available(): true|\WP_Error {
		if ( ! isset( Plugin::features()['usage'] ) ) {
			return new \WP_Error( 'jcore_kirjasto_no_usage', __( 'Duplicates can only be merged or deleted with the usage index turned on.', 'jcore-kirjasto' ), array( 'status' => 409 ) );
		}

		if ( ! Rebuild::is_built() || Rebuild::is_running() ) {
			return new \WP_Error( 'jcore_kirjasto_usage_building', __( 'The usage index is being built. Duplicates can be merged or deleted once it is done.', 'jcore-kirjasto' ), array( 'status' => 409 ) );
		}

		return true;
	}

	/**
	 * Merges attachments into one they duplicate.
	 *
	 * @param int   $keep   The attachment to keep.
	 * @param int[] $remove Attachments of the same group to merge into it. The
	 *                      other attachments sharing their files, such as
	 *                      their translations, are merged too.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error Per merged attachment:
	 *         `id`, `into`, `deleted` and `used`, how many places still use it.
	 */
	public static function merge( int $keep, array $remove ): array|\WP_Error {
		$available = self::available();
		if ( is_wp_error( $available ) ) {
			return $available;
		}

		$group = Finder::group_of( $keep );
		if ( ! $group ) {
			return new \WP_Error( 'jcore_kirjasto_not_duplicate', __( 'These files are no longer duplicates of each other.', 'jcore-kirjasto' ), array( 'status' => 409 ) );
		}

		$kept     = null;
		$removing = array();
		foreach ( $group['entries'] as $entry ) {
			if ( in_array( $keep, $entry['ids'], true ) ) {
				$kept = $entry;
			} elseif ( array_intersect( array_map( 'intval', $remove ), $entry['ids'] ) ) {
				$removing[] = $entry;
			}
		}

		if ( ! $kept || count( $removing ) !== count( array_unique( array_map( array( self::class, 'entry_of' ), array_map( 'intval', $remove ) ) ) ) ) {
			return new \WP_Error( 'jcore_kirjasto_not_duplicate', __( 'These files are no longer duplicates of each other.', 'jcore-kirjasto' ), array( 'status' => 409 ) );
		}

		$results = array();
		foreach ( $removing as $entry ) {
			foreach ( $entry['ids'] as $id ) {
				$results[] = self::merge_one( $id, self::counterpart( $id, $kept['ids'], $keep ) );
			}
		}

		Indexer::bump_revision();

		return $results;
	}

	/**
	 * Merges one attachment into another.
	 *
	 * @param int $from The duplicate.
	 * @param int $to   The attachment to keep.
	 *
	 * @return array<string, mixed>
	 */
	private static function merge_one( int $from, int $to ): array {
		$map = Attachment_Files::url_map( Attachment_Files::attachment_urls( $from ), Attachment_Files::attachment_urls( $to ), wp_attachment_is_image( $from ) );

		// Copies optimisers keep beside a file, e.g. `photo.jpg.webp`, point at the kept file itself.
		foreach ( Attachment_Files::attachment_urls( $from ) as $url ) {
			$target = $map[ $url ] ?? null;
			if ( null !== $target ) {
				$map[ $url . '.webp' ] = $target;
				$map[ $url . '.avif' ] = $target;
			}
		}

		$objects = array();
		foreach ( self::references( $from ) as $row ) {
			$objects[ $row->object_type . ':' . $row->object_id ][] = $row;
		}

		foreach ( $objects as $rows ) {
			switch ( $rows[0]->object_type ) {
				case 'post':
					self::rewrite_post( (int) $rows[0]->object_id, $rows, $from, $to, $map );
					Usage_Indexer::index_post( (int) $rows[0]->object_id );
					break;

				case 'term':
					self::rewrite_term( (int) $rows[0]->object_id, $rows, $from, $to, $map );
					Usage_Indexer::index_term( (int) $rows[0]->object_id );
					break;

				case 'option':
					self::rewrite_settings( $rows, $from, $to, $map );
					Usage_Indexer::index_settings();
					break;
			}
			// Theme files are code: they are not rewritten.
		}

		$used    = count( self::references( $from ) );
		$deleted = false;

		if ( 0 === $used && current_user_can( 'delete_post', $from ) ) {
			$deleted = (bool) wp_delete_attachment( $from );
		}

		return array(
			'id'      => $from,
			'into'    => $to,
			'deleted' => $deleted,
			'used'    => $used,
		);
	}

	/**
	 * Rewrites a post: its content, featured image, meta and ACF fields.
	 *
	 * Content is written directly, without a revision or a new modified date,
	 * and without the filters a save would run it through: only references
	 * to the attachment change.
	 *
	 * @param int                   $post_id Post.
	 * @param object[]              $rows    Usage rows of the duplicate on it.
	 * @param int                   $from    The duplicate.
	 * @param int                   $to      The attachment to keep.
	 * @param array<string, string> $map     Old URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_post( int $post_id, array $rows, int $from, int $to, array $map ): void {
		global $wpdb;

		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}

		if ( self::has_context( $rows, 'content' ) ) {
			$content = Rewriter::content( $post->post_content, $from, $to, $map );

			if ( $content !== $post->post_content ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery
				$wpdb->update( $wpdb->posts, array( 'post_content' => $content ), array( 'ID' => $post_id ) );
				clean_post_cache( $post_id );
			}
		}

		if ( self::has_context( $rows, 'featured' ) && $from === (int) get_post_meta( $post_id, '_thumbnail_id', true ) ) {
			update_post_meta( $post_id, '_thumbnail_id', $to );
		}

		self::rewrite_meta( 'post', $post_id, $rows, $from, $to, $map );
	}

	/**
	 * Rewrites a term: its meta and ACF fields.
	 *
	 * @param int                   $term_id Term.
	 * @param object[]              $rows    Usage rows of the duplicate on it.
	 * @param int                   $from    The duplicate.
	 * @param int                   $to      The attachment to keep.
	 * @param array<string, string> $map     Old URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_term( int $term_id, array $rows, int $from, int $to, array $map ): void {
		self::rewrite_meta( 'term', $term_id, $rows, $from, $to, $map );
	}

	/**
	 * Rewrites the meta keys and ACF fields of a post or term that the usage
	 * rows name.
	 *
	 * @param string                $type      `post` or `term`.
	 * @param int                   $object_id Post or term.
	 * @param object[]              $rows      Usage rows of the duplicate on it.
	 * @param int                   $from      The duplicate.
	 * @param int                   $to        The attachment to keep.
	 * @param array<string, string> $map       Old URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_meta( string $type, int $object_id, array $rows, int $from, int $to, array $map ): void {
		$values = array();
		foreach ( (array) get_metadata( $type, $object_id ) as $key => $stored ) {
			$values[ (string) $key ] = maybe_unserialize( $stored[0] ?? '' );
		}

		foreach ( $rows as $row ) {
			$changes = array();

			if ( 'meta' === $row->context && array_key_exists( $row->source, $values ) ) {
				$changes[ $row->source ] = Rewriter::ids( $values[ $row->source ], $from, $to );
			}

			if ( 'field' === $row->context ) {
				foreach ( Rewriter::field_names( $values, $row->source ) as $name ) {
					$changes[ $name ] = Rewriter::field( $row->source, $values[ $name ], $from, $to, $map );
				}
			}

			foreach ( $changes as $key => $value ) {
				if ( $value !== $values[ $key ] ) {
					update_metadata( $type, $object_id, $key, wp_slash( $value ) );
					$values[ $key ] = $value;
				}
			}
		}
	}

	/**
	 * Rewrites the site settings the usage rows name: the site icon, the
	 * logo, array options and ACF options pages.
	 *
	 * @param object[]              $rows Usage rows of the duplicate in the settings.
	 * @param int                   $from The duplicate.
	 * @param int                   $to   The attachment to keep.
	 * @param array<string, string> $map  Old URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_settings( array $rows, int $from, int $to, array $map ): void {
		foreach ( $rows as $row ) {
			switch ( $row->context ) {
				case 'site_icon':
					if ( $from === (int) get_option( 'site_icon' ) ) {
						update_option( 'site_icon', $to );
					}
					break;

				case 'custom_logo':
					if ( $from === (int) get_theme_mod( 'custom_logo' ) ) {
						set_theme_mod( 'custom_logo', $to );
					}
					break;

				case 'option':
					$pattern = Scanner::option_keys()[ $row->source ] ?? null;
					$value   = get_option( $row->source );

					if ( null !== $pattern && is_array( $value ) ) {
						$changed = $value;
						foreach ( $value as $key => $ids ) {
							if ( preg_match( $pattern, (string) $key ) ) {
								$changed[ $key ] = Rewriter::ids( $ids, $from, $to );
							}
						}

						if ( $changed !== $value ) {
							update_option( $row->source, $changed );
						}
					}
					break;

				case 'field':
					self::rewrite_options_field( $row->source, $from, $to, $map );
					break;
			}
		}
	}

	/**
	 * Rewrites an ACF field on the options pages, stored as `{prefix}_{name}`
	 * options beside `_{prefix}_{name}` holding the field key.
	 *
	 * @param string                $field_key Field key.
	 * @param int                   $from      The duplicate.
	 * @param int                   $to        The attachment to keep.
	 * @param array<string, string> $map       Old URLs mapped to new ones.
	 *
	 * @return void
	 */
	private static function rewrite_options_field( string $field_key, int $from, int $to, array $map ): void {
		global $wpdb;

		foreach ( Scanner::options_prefixes() as $prefix ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$names = $wpdb->get_col(
				$wpdb->prepare(
					'SELECT option_name FROM %i WHERE option_name LIKE %s AND ( option_value = %s OR option_value LIKE %s )',
					$wpdb->options,
					$wpdb->esc_like( '_' . $prefix . '_' ) . '%',
					$field_key,
					'%' . $wpdb->esc_like( '_' . $field_key )
				)
			);

			foreach ( $names as $name ) {
				$option  = substr( $name, 1 );
				$value   = get_option( $option );
				$changed = Rewriter::field( $field_key, $value, $from, $to, $map );

				if ( false !== $value && $changed !== $value ) {
					update_option( $option, $changed );
				}
			}
		}
	}

	/**
	 * The usage rows of an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return object[]
	 */
	private static function references( int $attachment_id ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return $wpdb->get_results(
			$wpdb->prepare(
				'SELECT object_type, object_id, context, source FROM %i WHERE attachment_id = %d ORDER BY object_type, object_id',
				Database::table( 'usage' ),
				$attachment_id
			)
		);
	}

	/**
	 * Whether a context is among the usage rows.
	 *
	 * @param object[] $rows    Usage rows.
	 * @param string   $context Context.
	 *
	 * @return bool
	 */
	private static function has_context( array $rows, string $context ): bool {
		return in_array( $context, wp_list_pluck( $rows, 'context' ), true );
	}

	/**
	 * The attachment a duplicate merges into: the kept file's attachment in
	 * the duplicate's language, where Polylang translates media.
	 *
	 * @param int   $id       The duplicate.
	 * @param int[] $kept     The attachments of the kept file.
	 * @param int   $fallback The attachment chosen to keep.
	 *
	 * @return int
	 */
	private static function counterpart( int $id, array $kept, int $fallback ): int {
		if ( count( $kept ) > 1 && function_exists( 'pll_get_post_language' ) ) {
			$language = pll_get_post_language( $id );

			foreach ( $kept as $candidate ) {
				if ( $language && pll_get_post_language( $candidate ) === $language ) {
					return $candidate;
				}
			}
		}

		return $fallback;
	}

	/**
	 * The file an attachment uses, which identifies its entry in a group.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return string
	 */
	private static function entry_of( int $attachment_id ): string {
		return (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
	}
}
