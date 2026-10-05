<?php
/**
 * Finds the attachments a post, term, the site settings or the theme refer to.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns stored content into a list of references.
 *
 * A reference is `array{ attachment_id: int, context: string, source: string }`.
 * The scanner does not check that an ID really is an attachment; the indexer
 * does that once per object, in one query.
 */
final class Scanner {

	/**
	 * Version of the scanning rules. Bumping it rebuilds the index on every
	 * site, so the new rules reach content nobody has edited since.
	 */
	public const VERSION = '2';

	/**
	 * Block attributes that hold attachment IDs, by block name.
	 */
	private const BLOCK_ATTRIBUTES = array(
		'core/image'      => array( 'id' ),
		'core/cover'      => array( 'id' ),
		'core/video'      => array( 'id' ),
		'core/audio'      => array( 'id' ),
		'core/file'       => array( 'id' ),
		'core/media-text' => array( 'mediaId' ),
		'core/gallery'    => array( 'ids' ),
	);

	/**
	 * Array options holding attachment IDs, with a pattern matching the keys
	 * that do. Yoast SEO keeps its default social images this way.
	 */
	private const OPTION_KEYS = array(
		'wpseo_social' => '/_image_id$/',
		'wpseo_titles' => '/(?:_image_id|^social-image-id-.+)$/',
	);

	/**
	 * Meta keys holding attachment IDs, besides the featured image and ACF fields.
	 */
	private const META_KEYS = array(
		'post' => array(
			'_product_image_gallery',
			'_yoast_wpseo_opengraph-image-id',
			'_yoast_wpseo_twitter-image-id',
		),
		'term' => array( 'thumbnail_id' ),
	);

	/**
	 * ACF field types whose stored value is one or more attachment IDs.
	 */
	private const FIELD_TYPES = array( 'image', 'file', 'gallery' );

	/**
	 * Resolved ACF fields, keyed by field key. `false` marks a key ACF does not know.
	 *
	 * @var array<string, array<string, mixed>|false>
	 */
	private static array $fields = array();

	/**
	 * Finds the attachments a post uses: in its content, as its featured image,
	 * in ACF fields and in the meta keys registered through the filter.
	 *
	 * @param \WP_Post $post The post.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	public static function post( \WP_Post $post ): array {
		$meta = self::flatten_meta( get_post_meta( $post->ID ) );

		$refs = array_merge(
			self::wrap( self::content_ids( $post->post_content ), 'content' ),
			self::wrap( self::ids_from_value( $meta['_thumbnail_id'] ?? null ), 'featured' ),
			self::meta_refs( $meta, 'post' ),
			self::field_refs( $meta )
		);

		/**
		 * Filters the attachments a post is recorded as using.
		 *
		 * @param array    $refs References: `attachment_id`, `context` and `source`.
		 * @param \WP_Post $post The post being indexed.
		 */
		return apply_filters( 'jcore_kirjasto_post_references', $refs, $post );
	}

	/**
	 * Finds the attachments a term uses through its meta.
	 *
	 * @param int $term_id Term ID.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	public static function term( int $term_id ): array {
		$meta = self::flatten_meta( get_term_meta( $term_id ) );

		$refs = array_merge(
			self::meta_refs( $meta, 'term' ),
			self::field_refs( $meta )
		);

		/**
		 * Filters the attachments a term is recorded as using.
		 *
		 * @param array $refs    References: `attachment_id`, `context` and `source`.
		 * @param int   $term_id The term being indexed.
		 */
		return apply_filters( 'jcore_kirjasto_term_references', $refs, $term_id );
	}

	/**
	 * Finds the attachments the site settings use: the site icon, the logo and
	 * ACF options pages.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	public static function settings(): array {
		$refs = array_merge(
			self::wrap( self::ids_from_value( get_option( 'site_icon' ) ), 'site_icon' ),
			self::wrap( self::ids_from_value( get_theme_mod( 'custom_logo' ) ), 'custom_logo' )
		);

		foreach ( self::options_prefixes() as $prefix ) {
			$refs = array_merge( $refs, self::field_refs( self::prefixed_options( $prefix ) ) );
		}

		foreach ( self::option_keys() as $option => $pattern ) {
			$value = get_option( $option );

			foreach ( is_array( $value ) ? $value : array() as $key => $ids ) {
				if ( preg_match( $pattern, (string) $key ) ) {
					$refs = array_merge( $refs, self::wrap( self::ids_from_value( $ids ), 'option', $option ) );
				}
			}
		}

		/**
		 * Filters the attachments the site settings are recorded as using.
		 *
		 * @param array $refs References: `attachment_id`, `context` and `source`.
		 */
		return apply_filters( 'jcore_kirjasto_settings_references', $refs );
	}

	/**
	 * Finds the attachments the files of the active theme refer to: blocks in
	 * patterns, templates and parts, links to files in the uploads directory,
	 * and IDs passed as literals to the attachment functions.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	public static function theme(): array {
		$refs = array();

		foreach ( Theme_Files::files() as $path => $source ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- A local theme file.
			$text = (string) file_get_contents( $path );

			$refs = array_merge( $refs, self::wrap( array_merge( self::content_ids( $text ), self::code_ids( $text ) ), 'file', $source ) );
		}

		/**
		 * Filters the attachments the theme is recorded as using.
		 *
		 * @param array $refs References: `attachment_id`, `context` and `source`.
		 */
		return apply_filters( 'jcore_kirjasto_theme_references', $refs );
	}

	/**
	 * Whether a change to this option can change what the settings use.
	 *
	 * @param string $option Option name.
	 *
	 * @return bool
	 */
	public static function is_settings_option( string $option ): bool {
		if ( in_array( $option, array( 'site_icon', 'site_logo' ), true ) || str_starts_with( $option, 'theme_mods_' ) || isset( self::option_keys()[ $option ] ) ) {
			return true;
		}

		foreach ( self::options_prefixes() as $prefix ) {
			if ( str_starts_with( $option, $prefix . '_' ) || str_starts_with( $option, '_' . $prefix . '_' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Array options holding attachment IDs, with a pattern for the keys that do.
	 *
	 * @return array<string, string>
	 */
	public static function option_keys(): array {
		/**
		 * Filters the array options scanned for attachment IDs.
		 *
		 * @param array<string, string> $options Regular expressions matching the keys
		 *                                       holding IDs, keyed by option name.
		 */
		return apply_filters( 'jcore_kirjasto_option_keys', self::OPTION_KEYS );
	}

	/**
	 * The `post_id` values ACF stores options pages under. Their fields are
	 * saved as `{prefix}_{name}` options.
	 *
	 * @return string[]
	 */
	public static function options_prefixes(): array {
		$prefixes = array( 'options' );

		if ( function_exists( 'acf_get_options_pages' ) ) {
			foreach ( (array) acf_get_options_pages() as $page ) {
				if ( isset( $page['post_id'] ) && is_string( $page['post_id'] ) && ! is_numeric( $page['post_id'] ) ) {
					$prefixes[] = $page['post_id'];
				}
			}
		}

		/**
		 * Filters the option name prefixes scanned for ACF fields.
		 *
		 * @param string[] $prefixes Prefixes, without the trailing underscore.
		 */
		return array_values( array_unique( apply_filters( 'jcore_kirjasto_options_prefixes', $prefixes ) ) );
	}

	/**
	 * Returns a resolved ACF field, or null when ACF is missing or does not know
	 * the key.
	 *
	 * Seamless clone fields are stored under a compound key such as
	 * `field_clone_field_image`; the last segment is the cloned field.
	 *
	 * @param string $key Field key.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function acf_field( string $key ): ?array {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return null;
		}

		if ( ! array_key_exists( $key, self::$fields ) ) {
			$field = acf_get_field( $key );

			if ( ! $field && preg_match_all( '/field_[a-z0-9]+/i', $key, $matches ) && count( $matches[0] ) > 1 ) {
				$field = acf_get_field( end( $matches[0] ) );
			}

			self::$fields[ $key ] = is_array( $field ) ? $field : false;
		}

		return self::$fields[ $key ] ? self::$fields[ $key ] : null;
	}

	/**
	 * Finds attachment IDs in post content: block attributes, image classes,
	 * gallery shortcodes and links to files in the uploads directory.
	 *
	 * @param string $content Post content.
	 *
	 * @return int[]
	 */
	private static function content_ids( string $content ): array {
		if ( '' === trim( $content ) ) {
			return array();
		}

		$ids = has_blocks( $content ) ? self::block_ids( parse_blocks( $content ) ) : array();

		return array_merge( $ids, self::text_ids( $content ) );
	}

	/**
	 * Walks a block tree and collects the IDs its attributes hold.
	 *
	 * @param array<int, array<string, mixed>> $blocks Parsed blocks.
	 *
	 * @return int[]
	 */
	private static function block_ids( array $blocks ): array {
		/**
		 * Filters the block attributes that hold attachment IDs.
		 *
		 * @param array<string, string[]> $attributes Attribute names, keyed by block name.
		 */
		$map = apply_filters( 'jcore_kirjasto_block_attributes', self::BLOCK_ATTRIBUTES );
		$ids = array();

		foreach ( $blocks as $block ) {
			$attrs = is_array( $block['attrs'] ?? null ) ? $block['attrs'] : array();

			foreach ( $map[ $block['blockName'] ?? '' ] ?? array() as $attr ) {
				$ids = array_merge( $ids, self::ids_from_value( $attrs[ $attr ] ?? null ) );
			}

			// ACF blocks keep their field values in the `data` attribute.
			if ( isset( $attrs['data'] ) && is_array( $attrs['data'] ) ) {
				$ids = array_merge(
					$ids,
					wp_list_pluck( self::field_refs( $attrs['data'] ), 'attachment_id' ),
					self::keyed_field_ids( $attrs['data'] )
				);
			}

			$ids = array_merge( $ids, self::media_object_ids( $attrs ) );

			if ( ! empty( $block['innerBlocks'] ) ) {
				$ids = array_merge( $ids, self::block_ids( $block['innerBlocks'] ) );
			}
		}

		return $ids;
	}

	/**
	 * Collects IDs from media objects nested in block attributes, the
	 * `{ id, url }` shape custom blocks commonly store an image in.
	 *
	 * @param array<mixed> $value Attribute value.
	 *
	 * @return int[]
	 */
	private static function media_object_ids( array $value ): array {
		$ids = array();

		if ( isset( $value['id'] ) && is_numeric( $value['id'] ) && ( isset( $value['url'] ) || isset( $value['sizes'] ) || isset( $value['mime'] ) ) ) {
			$ids[] = (int) $value['id'];
		}

		foreach ( $value as $child ) {
			if ( is_array( $child ) ) {
				$ids = array_merge( $ids, self::media_object_ids( $child ) );
			}
		}

		return $ids;
	}

	/**
	 * Collects IDs from ACF block data keyed by field key rather than by name,
	 * which is how ACF stores values nested in repeaters and groups there.
	 *
	 * @param array<mixed> $data Block data.
	 *
	 * @return int[]
	 */
	private static function keyed_field_ids( array $data ): array {
		$ids = array();

		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && str_starts_with( $key, 'field_' ) ) {
				$field = self::acf_field( $key );
				if ( $field && self::is_media_field( $field ) ) {
					$ids = array_merge( $ids, self::ids_from_value( $value ) );
					continue;
				}
			}

			if ( is_array( $value ) ) {
				$ids = array_merge( $ids, self::keyed_field_ids( $value ) );
			}
		}

		return $ids;
	}

	/**
	 * Finds attachment IDs in free text: HTML, shortcodes and serialised values.
	 *
	 * @param string $text The text.
	 *
	 * @return int[]
	 */
	private static function text_ids( string $text ): array {
		// JSON in block attributes and meta may escape its slashes.
		$text = str_replace( '\\/', '/', $text );
		$ids  = array();

		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $text, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
		}

		if ( preg_match_all( '/[?&](?:amp;)?attachment_id=(\d+)/', $text, $matches ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
		}

		if ( preg_match_all( '/\[gallery\b[^\]]*?\b(?:ids|include)=["\']?([\d,\s]+)/i', $text, $matches ) ) {
			foreach ( $matches[1] as $list ) {
				$ids = array_merge( $ids, self::ids_from_value( $list ) );
			}
		}

		return array_merge( $ids, self::url_ids( $text ) );
	}

	/**
	 * Finds attachment IDs written as literals in theme code: the first argument
	 * of `wp_get_attachment_*()` and `get_attached_file()` in PHP, and of the
	 * same functions and Timber's `get_image()` in Twig.
	 *
	 * @param string $code PHP or Twig source.
	 *
	 * @return int[]
	 */
	private static function code_ids( string $code ): array {
		$functions = '(?:wp_get_attachment_\w+|get_attached_file)';
		$patterns  = array(
			'/\b' . $functions . '\s*\(\s*(\d+)\s*[,)]/',
			'/\bfunction\(\s*[\'"]' . $functions . '[\'"]\s*,\s*(\d+)\s*[,)]/',
			'/\bget_image\(\s*(\d+)\s*[,)]/',
		);

		$ids = array();
		foreach ( $patterns as $pattern ) {
			if ( preg_match_all( $pattern, $code, $matches ) ) {
				$ids = array_merge( $ids, array_map( 'intval', $matches[1] ) );
			}
		}

		return $ids;
	}

	/**
	 * Runs text_ids() on every string in a value, however deeply nested.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int[]
	 */
	private static function deep_text_ids( mixed $value ): array {
		if ( is_string( $value ) ) {
			return self::text_ids( $value );
		}

		$ids = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				$ids = array_merge( $ids, self::deep_text_ids( $child ) );
			}
		}

		return $ids;
	}

	/**
	 * Resolves links into the uploads directory to the attachments they show.
	 *
	 * Only the path is matched, so links with an old or staging domain still
	 * count. Resized copies (`-300x200`), the `-scaled` original and `.webp`
	 * copies made next to the original all map back to the attachment.
	 *
	 * @param string $text The text.
	 *
	 * @return int[]
	 */
	private static function url_ids( string $text ): array {
		$path = self::uploads_path();
		if ( ! str_contains( $text, $path . '/' ) ) {
			return array();
		}

		$pattern = '#' . preg_quote( $path . '/', '#' ) . '([^\s"\'<>()\[\]{}?\#,&|\\\\]+?\.[a-z0-9]{2,5})(?![a-z0-9.])#i';
		if ( ! preg_match_all( $pattern, $text, $matches ) ) {
			return array();
		}

		$candidates = array();
		foreach ( array_unique( $matches[1] ) as $file ) {
			$file = preg_replace( '/(\.[a-z0-9]{2,5})\.(?:webp|avif)$/i', '$1', rawurldecode( $file ) );
			$base = preg_replace( '/-\d+x\d+(?=\.[a-z0-9]+$)/i', '', $file );

			$candidates[] = $file;
			$candidates[] = $base;
			$candidates[] = preg_replace( '/(\.[a-z0-9]+)$/i', '-scaled$1', $base );
		}

		return self::ids_for_files( array_values( array_unique( $candidates ) ) );
	}

	/**
	 * Looks up attachments by their file path relative to the uploads directory.
	 *
	 * @param string[] $files Relative paths, e.g. `2026/10/photo.jpg`.
	 *
	 * @return int[]
	 */
	private static function ids_for_files( array $files ): array {
		global $wpdb;

		$ids = array();
		foreach ( array_chunk( $files, 200 ) as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$found = $wpdb->get_col(
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $placeholders is a generated list of %s.
					"SELECT post_id FROM %i WHERE meta_key = '_wp_attached_file' AND meta_value IN ($placeholders)",
					$wpdb->postmeta,
					...$chunk
				)
			);

			$ids = array_merge( $ids, array_map( 'intval', $found ) );
		}

		return $ids;
	}

	/**
	 * The path part of the uploads URL, e.g. `/wp-content/uploads`.
	 *
	 * @return string
	 */
	private static function uploads_path(): string {
		static $path = null;

		if ( null === $path ) {
			$path = untrailingslashit( (string) wp_parse_url( wp_get_upload_dir()['baseurl'], PHP_URL_PATH ) );
		}

		return $path;
	}

	/**
	 * Finds attachments in ACF fields.
	 *
	 * ACF stores every value as `{name}` next to a `_{name}` holding the field
	 * key, in post meta, term meta, options and block data alike. Repeater and
	 * flexible content rows get their own `{parent}_{row}_{name}` entries, so a
	 * flat walk reaches them too.
	 *
	 * Media fields contribute their IDs; any other field is searched as text,
	 * which covers images placed in WYSIWYG fields and links to files.
	 *
	 * @param array<string, mixed> $values Values keyed by name.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	private static function field_refs( array $values ): array {
		if ( ! function_exists( 'acf_get_field' ) ) {
			return array();
		}

		$refs = array();
		foreach ( $values as $name => $value ) {
			$name = (string) $name;
			$key  = $values[ '_' . $name ] ?? null;

			if ( '' === $name || '_' === $name[0] || ! is_string( $key ) || ! str_starts_with( $key, 'field_' ) ) {
				continue;
			}

			$field = self::acf_field( $key );
			if ( ! $field ) {
				continue;
			}

			$ids  = self::is_media_field( $field ) ? self::ids_from_value( $value ) : self::deep_text_ids( $value );
			$refs = array_merge( $refs, self::wrap( $ids, 'field', $field['key'] ) );
		}

		return $refs;
	}

	/**
	 * Whether an ACF field stores attachment IDs.
	 *
	 * @param array<string, mixed> $field ACF field.
	 *
	 * @return bool
	 */
	private static function is_media_field( array $field ): bool {
		/**
		 * Filters the ACF field types whose value is one or more attachment IDs.
		 *
		 * @param string[] $types Field types.
		 */
		$types = apply_filters( 'jcore_kirjasto_field_types', self::FIELD_TYPES );

		return in_array( $field['type'] ?? '', $types, true );
	}

	/**
	 * Finds attachments in the plain meta keys registered through the filter.
	 *
	 * @param array<string, mixed> $meta        Flattened meta.
	 * @param string               $object_type `post` or `term`.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	private static function meta_refs( array $meta, string $object_type ): array {
		/**
		 * Filters the meta keys whose value is one or more attachment IDs.
		 *
		 * The featured image and ACF fields are found without being listed here.
		 *
		 * @param string[] $keys        Meta keys.
		 * @param string   $object_type `post` or `term`.
		 */
		$keys = apply_filters( 'jcore_kirjasto_meta_keys', self::META_KEYS[ $object_type ] ?? array(), $object_type );

		$refs = array();
		foreach ( $keys as $key ) {
			if ( isset( $meta[ $key ] ) ) {
				$refs = array_merge( $refs, self::wrap( self::ids_from_value( $meta[ $key ] ), 'meta', $key ) );
			}
		}

		return $refs;
	}

	/**
	 * Reads every option stored under an ACF options prefix, with its key
	 * companion, e.g. `options_logo` and `_options_logo`.
	 *
	 * @param string $prefix Options prefix.
	 *
	 * @return array<string, mixed>
	 */
	private static function prefixed_options( string $prefix ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT option_name, option_value FROM %i WHERE option_name LIKE %s OR option_name LIKE %s',
				$wpdb->options,
				$wpdb->esc_like( $prefix . '_' ) . '%',
				$wpdb->esc_like( '_' . $prefix . '_' ) . '%'
			)
		);

		$values = array();
		foreach ( $rows as $row ) {
			$values[ $row->option_name ] = maybe_unserialize( $row->option_value );
		}

		return $values;
	}

	/**
	 * Reduces `get_*_meta()` output to the first value of each key, unserialised.
	 *
	 * @param array<string, array<int, string>>|mixed $meta All meta of an object.
	 *
	 * @return array<string, mixed>
	 */
	private static function flatten_meta( mixed $meta ): array {
		$flat = array();

		foreach ( is_array( $meta ) ? $meta : array() as $key => $values ) {
			$flat[ (string) $key ] = maybe_unserialize( $values[0] ?? '' );
		}

		return $flat;
	}

	/**
	 * Reads attachment IDs from a stored value: an integer, a numeric string, a
	 * comma separated list or an array of any of those.
	 *
	 * @param mixed $value The value.
	 *
	 * @return int[]
	 */
	private static function ids_from_value( mixed $value ): array {
		if ( is_int( $value ) || ( is_string( $value ) && ctype_digit( $value ) ) ) {
			return (int) $value > 0 ? array( (int) $value ) : array();
		}

		if ( is_string( $value ) && preg_match( '/^[\d,\s]+$/', $value ) ) {
			return array_values( array_filter( array_map( 'intval', preg_split( '/[\s,]+/', $value ) ) ) );
		}

		$ids = array();
		if ( is_array( $value ) ) {
			foreach ( $value as $child ) {
				if ( is_int( $child ) || is_string( $child ) ) {
					$ids = array_merge( $ids, self::ids_from_value( $child ) );
				}
			}
		}

		return $ids;
	}

	/**
	 * Turns a list of IDs into references with the given context and source.
	 *
	 * @param int[]  $ids     Attachment IDs.
	 * @param string $context Where on the object they are used.
	 * @param string $source  Narrower location, e.g. the ACF field key.
	 *
	 * @return array<int, array{attachment_id: int, context: string, source: string}>
	 */
	private static function wrap( array $ids, string $context, string $source = '' ): array {
		return array_map(
			static function ( int $id ) use ( $context, $source ): array {
				return array(
					'attachment_id' => $id,
					'context'       => $context,
					'source'        => $source,
				);
			},
			array_values( array_unique( array_filter( $ids ) ) )
		);
	}
}
