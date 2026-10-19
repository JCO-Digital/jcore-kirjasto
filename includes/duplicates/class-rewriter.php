<?php
/**
 * Moves references from one attachment to another.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

use Jcore\Kirjasto\Attachment_Files;
use Jcore\Kirjasto\Usage\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rewrites stored values so they refer to another attachment, in every form
 * the usage scanner finds references in: block attributes, ACF values, image
 * classes, gallery shortcodes, `attachment_id` links, plain IDs and links to
 * the files.
 *
 * Every method takes the attachment to move away from, the one to move to,
 * and a map of the old file's URLs to the new file's.
 */
final class Rewriter {

	/**
	 * Pattern of a block comment, as WordPress's block parser reads it.
	 */
	private const BLOCK_COMMENT = '/<!--\s+(?P<closer>\/)?wp:(?P<namespace>[a-z][a-z0-9_-]*\/)?(?P<name>[a-z][a-z0-9_-]*)\s+(?P<attrs>{(?:(?:[^}]+|}+(?=})|(?!}\s+\/?-->).)*+)?}\s+)?(?P<void>\/)?-->/s';

	/**
	 * Rewrites post content: the attributes of its blocks, then its HTML.
	 *
	 * Only blocks whose attributes change are written again, so the rest of
	 * the content stays exactly as it was.
	 *
	 * @param string                $content Post content.
	 * @param int                   $from    Attachment to move away from.
	 * @param int                   $to      Attachment to move to.
	 * @param array<string, string> $map     Old URLs mapped to new ones.
	 *
	 * @return string
	 */
	public static function content( string $content, int $from, int $to, array $map ): string {
		$content = (string) preg_replace_callback(
			self::BLOCK_COMMENT,
			static function ( array $found ) use ( $from, $to, $map ): string {
				if ( '' !== $found['closer'] || empty( $found['attrs'] ) ) {
					return $found[0];
				}

				$attrs = json_decode( $found['attrs'], true );
				if ( ! is_array( $attrs ) ) {
					return $found[0];
				}

				$name    = ( '' !== $found['namespace'] ? $found['namespace'] : 'core/' ) . $found['name'];
				$changed = self::block_attrs( $name, $attrs, $from, $to, $map );

				if ( $changed === $attrs ) {
					return $found[0];
				}

				return sprintf(
					'<!-- wp:%s%s %s %s-->',
					$found['namespace'],
					$found['name'],
					serialize_block_attributes( $changed ),
					isset( $found['void'] ) && '' !== $found['void'] ? '/' : ''
				);
			},
			$content
		);

		return self::text( $content, $from, $to, $map );
	}

	/**
	 * Rewrites HTML and other free text.
	 *
	 * @param string                $text Text.
	 * @param int                   $from Attachment to move away from.
	 * @param int                   $to   Attachment to move to.
	 * @param array<string, string> $map  Old URLs mapped to new ones.
	 *
	 * @return string
	 */
	public static function text( string $text, int $from, int $to, array $map ): string {
		// Image tags: the class, and the data-id galleries add.
		$text = (string) preg_replace_callback(
			'/<img\b[^>]*\bwp-image-' . $from . '\b[^>]*>/i',
			static function ( array $found ) use ( $from, $to ): string {
				return (string) preg_replace(
					array( '/\bwp-image-' . $from . '\b/', '/\bdata-id=(["\']?)' . $from . '\1/' ),
					array( 'wp-image-' . $to, 'data-id=${1}' . $to . '${1}' ),
					$found[0]
				);
			},
			$text
		);

		$text = (string) preg_replace( '/\bwp-image-' . $from . '\b/', 'wp-image-' . $to, $text );
		$text = (string) preg_replace( '/([?&](?:amp;)?attachment_id=)' . $from . '\b/', '${1}' . $to, $text );

		$text = (string) preg_replace_callback(
			'/(\[gallery\b[^\]]*?\b(?:ids|include)=["\']?)([\d,\s]+)/i',
			static function ( array $found ) use ( $from, $to ): string {
				return $found[1] . preg_replace( '/(?<!\d)' . $from . '(?!\d)/', (string) $to, $found[2] );
			},
			$text
		);

		return Attachment_Files::rewrite_urls( $text, $map );
	}

	/**
	 * Replaces an attachment ID in a stored value: an integer, a numeric
	 * string, a comma separated list, or arrays of those.
	 *
	 * @param mixed $value Value.
	 * @param int   $from  Attachment to move away from.
	 * @param int   $to    Attachment to move to.
	 *
	 * @return mixed
	 */
	public static function ids( mixed $value, int $from, int $to ): mixed {
		if ( is_int( $value ) ) {
			return $from === $value ? $to : $value;
		}

		if ( is_string( $value ) && ctype_digit( $value ) ) {
			return (string) $from === $value ? (string) $to : $value;
		}

		if ( is_string( $value ) && preg_match( '/^[\d,\s]+$/', $value ) ) {
			return (string) preg_replace( '/(?<!\d)' . $from . '(?!\d)/', (string) $to, $value );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				if ( is_int( $child ) || is_string( $child ) ) {
					$value[ $key ] = self::ids( $child, $from, $to );
				}
			}
		}

		return $value;
	}

	/**
	 * Runs text() on every string in a value, however deeply nested.
	 *
	 * @param mixed                 $value Value.
	 * @param int                   $from  Attachment to move away from.
	 * @param int                   $to    Attachment to move to.
	 * @param array<string, string> $map   Old URLs mapped to new ones.
	 *
	 * @return mixed
	 */
	public static function deep_text( mixed $value, int $from, int $to, array $map ): mixed {
		if ( is_string( $value ) ) {
			return self::text( $value, $from, $to, $map );
		}

		if ( is_array( $value ) ) {
			foreach ( $value as $key => $child ) {
				$value[ $key ] = self::deep_text( $child, $from, $to, $map );
			}
		}

		return $value;
	}

	/**
	 * Rewrites a value stored for an ACF field: the IDs of media fields, the
	 * text of the others.
	 *
	 * @param string                $field_key Field key.
	 * @param mixed                 $value     Value.
	 * @param int                   $from      Attachment to move away from.
	 * @param int                   $to        Attachment to move to.
	 * @param array<string, string> $map       Old URLs mapped to new ones.
	 *
	 * @return mixed
	 */
	public static function field( string $field_key, mixed $value, int $from, int $to, array $map ): mixed {
		$field = Scanner::acf_field( $field_key );

		if ( $field && Scanner::is_media_field( $field ) ) {
			return self::ids( $value, $from, $to );
		}

		return self::deep_text( $value, $from, $to, $map );
	}

	/**
	 * The names of the ACF values stored for a field, among values stored the
	 * ACF way: `{name}` beside `_{name}` holding the field key. Seamless clone
	 * fields store compound keys ending in the cloned field's.
	 *
	 * @param array<string, mixed> $values    Values keyed by name.
	 * @param string               $field_key Field key.
	 *
	 * @return string[]
	 */
	public static function field_names( array $values, string $field_key ): array {
		$names = array();

		foreach ( $values as $name => $key ) {
			$name = (string) $name;

			if ( str_starts_with( $name, '_' ) && is_string( $key ) && ( $key === $field_key || str_ends_with( $key, '_' . $field_key ) ) && array_key_exists( substr( $name, 1 ), $values ) ) {
				$names[] = substr( $name, 1 );
			}
		}

		return $names;
	}

	/**
	 * Rewrites the attributes of a block.
	 *
	 * @param string                $name Block name.
	 * @param array<string, mixed>  $attrs Attributes.
	 * @param int                   $from Attachment to move away from.
	 * @param int                   $to   Attachment to move to.
	 * @param array<string, string> $map   Old URLs mapped to new ones.
	 *
	 * @return array<string, mixed>
	 */
	private static function block_attrs( string $name, array $attrs, int $from, int $to, array $map ): array {
		foreach ( Scanner::block_attributes()[ $name ] ?? array() as $attr ) {
			if ( array_key_exists( $attr, $attrs ) ) {
				$attrs[ $attr ] = self::ids( $attrs[ $attr ], $from, $to );
			}
		}

		// ACF blocks keep their field values in the `data` attribute.
		if ( isset( $attrs['data'] ) && is_array( $attrs['data'] ) ) {
			$attrs['data'] = self::acf_data( $attrs['data'], $from, $to, $map );
		}

		return self::media_objects( $attrs, $from, $to, $map );
	}

	/**
	 * Rewrites ACF block data, keyed by field name with `_{name}` companions,
	 * or by field key where ACF nests values in repeaters and groups.
	 *
	 * @param array<mixed>          $data Block data.
	 * @param int                   $from Attachment to move away from.
	 * @param int                   $to   Attachment to move to.
	 * @param array<string, string> $map  Old URLs mapped to new ones.
	 *
	 * @return array<mixed>
	 */
	private static function acf_data( array $data, int $from, int $to, array $map ): array {
		foreach ( $data as $key => $value ) {
			$key       = (string) $key;
			$field_key = $data[ '_' . $key ] ?? ( str_starts_with( $key, 'field_' ) ? $key : null );

			if ( str_starts_with( $key, '_' ) ) {
				continue;
			}

			if ( is_string( $field_key ) && str_starts_with( $field_key, 'field_' ) && Scanner::acf_field( $field_key ) ) {
				$field = Scanner::acf_field( $field_key );

				if ( Scanner::is_media_field( $field ) ) {
					$data[ $key ] = self::ids( $value, $from, $to );
					continue;
				}
			}

			$data[ $key ] = is_array( $value ) ? self::acf_data( $value, $from, $to, $map ) : self::deep_text( $value, $from, $to, $map );
		}

		return $data;
	}

	/**
	 * Rewrites media objects nested in block attributes, the `{ id, url }`
	 * shape custom blocks commonly store an image in.
	 *
	 * @param array<mixed>          $value Attribute value.
	 * @param int                   $from  Attachment to move away from.
	 * @param int                   $to    Attachment to move to.
	 * @param array<string, string> $map   Old URLs mapped to new ones.
	 *
	 * @return array<mixed>
	 */
	private static function media_objects( array $value, int $from, int $to, array $map ): array {
		$is_media = isset( $value['id'] ) && is_numeric( $value['id'] ) && (int) $value['id'] === $from && ( isset( $value['url'] ) || isset( $value['sizes'] ) || isset( $value['mime'] ) );

		if ( $is_media ) {
			$value['id'] = is_int( $value['id'] ) ? $to : (string) $to;
			$value       = self::deep_text( $value, $from, $to, $map );
		}

		foreach ( $value as $key => $child ) {
			if ( is_array( $child ) ) {
				$value[ $key ] = self::media_objects( $child, $from, $to, $map );
			}
		}

		return $value;
	}
}
