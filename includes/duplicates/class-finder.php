<?php
/**
 * Finds duplicates among the fingerprints.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Groups attachments whose files are the same, or look the same.
 *
 * Attachments sharing one file, such as Polylang's translations of an image,
 * are one entry of a group, not duplicates of each other. Entries are
 * identical when a hash of their bytes matches, as they are now or as they
 * were uploaded, and look the same when their perceptual hashes differ in a
 * few bits and their proportions match.
 *
 * Similar images are found without comparing every pair: the 64-bit hash is
 * split into 8 bytes, and two hashes at most 7 bits apart share at least one
 * byte in the same place. Only images sharing a byte are compared.
 */
final class Finder {

	/**
	 * Option holding the pairs of entries marked as not duplicates.
	 */
	public const DISMISSED_OPTION = 'jcore_kirjasto_duplicates_dismissed';

	/**
	 * Transient caching the groups for the current revision.
	 */
	private const CACHE_KEY = 'jcore_kirjasto_duplicate_groups';

	/**
	 * Most bits two perceptual hashes may differ in by default.
	 */
	private const THRESHOLD = 6;

	/**
	 * How far the proportions of two similar images may differ.
	 */
	private const ASPECT_TOLERANCE = 0.03;

	/**
	 * The entries, keyed by file, loaded once per request.
	 *
	 * @var array<string, array<string, mixed>>|null
	 */
	private static ?array $entries = null;

	/**
	 * The groups, loaded once per request.
	 *
	 * @var array<int, array<string, mixed>>|null
	 */
	private static ?array $groups = null;

	/**
	 * The group of each grouped attachment, by position in groups().
	 *
	 * @var array<int, int>|null
	 */
	private static ?array $index = null;

	/**
	 * The entries by hash and by byte of their perceptual hash, for matches().
	 *
	 * @var array{hashes: array<string, string[]>, bands: array<int, array<string, string[]>>}|null
	 */
	private static ?array $lookup = null;

	/**
	 * Every group of duplicates: identical files first, then the larger
	 * groups.
	 *
	 * A group is `kind` (`identical` or `similar`) and `entries`; an entry
	 * is `file`, `ids` (the attachments using the file, lowest first) and
	 * `id`, the first of them.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function groups(): array {
		if ( null !== self::$groups ) {
			return self::$groups;
		}

		$revision = (int) get_option( Indexer::REVISION_OPTION, 0 );
		$cached   = get_transient( self::CACHE_KEY );

		if ( is_array( $cached ) && ( $cached['revision'] ?? null ) === $revision ) {
			self::$groups = $cached['groups'];

			return self::$groups;
		}

		self::$groups = self::find();
		set_transient(
			self::CACHE_KEY,
			array(
				'revision' => $revision,
				'groups'   => self::$groups,
			)
		);

		return self::$groups;
	}

	/**
	 * The group an attachment is in.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function group_of( int $attachment_id ): ?array {
		if ( null === self::$index ) {
			self::$index = array();
			foreach ( self::groups() as $i => $group ) {
				foreach ( $group['entries'] as $entry ) {
					foreach ( $entry['ids'] as $id ) {
						self::$index[ $id ] = $i;
					}
				}
			}
		}

		return isset( self::$index[ $attachment_id ] ) ? self::groups()[ self::$index[ $attachment_id ] ] : null;
	}

	/**
	 * The entries a file about to be uploaded would duplicate.
	 *
	 * @param string $sha1   SHA-1 of the file.
	 * @param string $phash  Its perceptual hash, or an empty string.
	 * @param int    $width  Its width, or 0.
	 * @param int    $height Its height, or 0.
	 *
	 * @return array<int, array<string, mixed>> Entries, with `kind` and for
	 *                                          similar ones `distance`, the
	 *                                          closest first.
	 */
	public static function matches( string $sha1, string $phash, int $width, int $height ): array {
		$entries = self::entries();
		$lookup  = self::lookup();
		$matches = array();

		foreach ( '' !== $sha1 ? ( $lookup['hashes'][ $sha1 ] ?? array() ) : array() as $file ) {
			$matches[ $file ] = array(
				'kind'     => 'identical',
				'distance' => 0,
			) + $entries[ $file ];
		}

		// Only the entries sharing a byte of the hash can be close enough, as in find().
		$candidates = array();
		if ( self::comparable( $phash ) ) {
			foreach ( str_split( $phash, 2 ) as $i => $byte ) {
				foreach ( $lookup['bands'][ $i ][ $byte ] ?? array() as $file ) {
					$candidates[ $file ] = true;
				}
			}
		}

		foreach ( array_keys( array_diff_key( $candidates, $matches ) ) as $file ) {
			$entry = $entries[ $file ];

			if ( self::same_shape( $width, $height, $entry['width'], $entry['height'] ) ) {
				$distance = Fingerprint::distance( $phash, $entry['phash'] );

				if ( $distance <= self::threshold() ) {
					$matches[ $file ] = array(
						'kind'     => 'similar',
						'distance' => $distance,
					) + $entry;
				}
			}
		}

		usort(
			$matches,
			static function ( array $a, array $b ): int {
				return array( $a['distance'], $a['id'] ) <=> array( $b['distance'], $b['id'] );
			}
		);

		return $matches;
	}

	/**
	 * Forgets the entries and groups loaded during this request.
	 *
	 * @return void
	 */
	public static function flush(): void {
		self::$entries = null;
		self::$groups  = null;
		self::$index   = null;
		self::$lookup  = null;
	}

	/**
	 * Marks the entries of the given attachments as not duplicates of each
	 * other.
	 *
	 * @param int[] $attachment_ids Attachments, one per entry.
	 *
	 * @return void
	 */
	public static function dismiss( array $attachment_ids ): void {
		$ids       = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
		$dismissed = self::dismissed();

		foreach ( $ids as $i => $a ) {
			foreach ( array_slice( $ids, $i + 1 ) as $b ) {
				$dismissed[ self::pair( $a, $b ) ] = true;
			}
		}

		update_option( self::DISMISSED_OPTION, array_keys( $dismissed ), false );
		Indexer::bump_revision();
	}

	/**
	 * How many attachments are fingerprinted, and how many files could not
	 * be read.
	 *
	 * @return array{fingerprinted: int, missing: int}
	 */
	public static function counts(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT status, COUNT(*) AS count FROM %i GROUP BY status', Database::table( 'fingerprints' ) ), OBJECT_K );

		return array(
			'fingerprinted' => (int) ( $rows['ok']->count ?? 0 ),
			'missing'       => (int) ( $rows['missing']->count ?? 0 ),
		);
	}

	/**
	 * Builds the groups.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private static function find(): array {
		$entries   = self::entries();
		$dismissed = self::dismissed();
		$parent    = array();
		$exact     = array();

		$find = static function ( string $file ) use ( &$parent, &$find ): string {
			if ( $parent[ $file ] !== $file ) {
				$parent[ $file ] = $find( $parent[ $file ] );
			}

			return $parent[ $file ];
		};

		$union = static function ( string $a, string $b ) use ( &$parent, $find, $entries, $dismissed ): bool {
			if ( isset( $dismissed[ self::pair( $entries[ $a ]['id'], $entries[ $b ]['id'] ) ] ) ) {
				return false;
			}

			$parent[ $find( $a ) ] = $find( $b );

			return true;
		};

		foreach ( array_keys( $entries ) as $file ) {
			$parent[ $file ] = $file;
		}

		// Identical: every pair of entries sharing a hash.
		$by_hash = array();
		foreach ( $entries as $file => $entry ) {
			foreach ( $entry['hashes'] as $hash ) {
				$by_hash[ $hash ][] = $file;
			}
		}

		foreach ( $by_hash as $files ) {
			$files = array_values( array_unique( $files ) );
			foreach ( $files as $i => $a ) {
				foreach ( array_slice( $files, $i + 1 ) as $b ) {
					$union( $a, $b );
				}
			}
		}

		foreach ( array_keys( $entries ) as $file ) {
			$exact[ $file ] = $find( $file );
		}

		// Similar: pairs sharing a byte of their hash, close enough and alike in shape.
		$bands = array();
		foreach ( $entries as $file => $entry ) {
			if ( self::comparable( $entry['phash'] ) ) {
				foreach ( str_split( $entry['phash'], 2 ) as $i => $byte ) {
					$bands[ $i ][ $byte ][] = $file;
				}
			}
		}

		$compared = array();
		foreach ( $bands as $buckets ) {
			foreach ( $buckets as $files ) {
				foreach ( $files as $i => $a ) {
					foreach ( array_slice( $files, $i + 1 ) as $b ) {
						$key = $a < $b ? "$a\0$b" : "$b\0$a";
						if ( isset( $compared[ $key ] ) || $find( $a ) === $find( $b ) ) {
							continue;
						}
						$compared[ $key ] = true;

						$x = $entries[ $a ];
						$y = $entries[ $b ];
						if ( self::same_shape( $x['width'], $x['height'], $y['width'], $y['height'] ) && Fingerprint::distance( $x['phash'], $y['phash'] ) <= self::threshold() ) {
							$union( $a, $b );
						}
					}
				}
			}
		}

		$components = array();
		foreach ( array_keys( $entries ) as $file ) {
			$components[ $find( $file ) ][] = $file;
		}

		$groups = array();
		foreach ( $components as $files ) {
			if ( count( $files ) < 2 ) {
				continue;
			}

			$identical = 1 === count( array_unique( array_intersect_key( $exact, array_flip( $files ) ) ) );
			$members   = array();
			foreach ( $files as $file ) {
				$members[] = array_intersect_key( $entries[ $file ], array_flip( array( 'file', 'id', 'ids' ) ) );
			}

			usort(
				$members,
				static function ( array $a, array $b ): int {
					return $a['id'] <=> $b['id'];
				}
			);

			$groups[] = array(
				'kind'    => $identical ? 'identical' : 'similar',
				'entries' => $members,
			);
		}

		usort(
			$groups,
			static function ( array $a, array $b ): int {
				return array( 'identical' === $b['kind'], count( $b['entries'] ), $a['entries'][0]['id'] )
					<=> array( 'identical' === $a['kind'], count( $a['entries'] ), $b['entries'][0]['id'] );
			}
		);

		return $groups;
	}

	/**
	 * The fingerprinted files that are not in the trash, one entry per file.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function entries(): array {
		global $wpdb;

		if ( null !== self::$entries ) {
			return self::$entries;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT f.attachment_id, f.file, f.content_hash, f.upload_hash, f.phash, f.width, f.height FROM %i f INNER JOIN %i p ON p.ID = f.attachment_id WHERE f.status = 'ok' AND p.post_status <> 'trash' ORDER BY f.attachment_id",
				Database::table( 'fingerprints' ),
				$wpdb->posts
			)
		);

		self::$entries = array();
		foreach ( $rows as $row ) {
			$file = (string) $row->file;

			if ( isset( self::$entries[ $file ] ) ) {
				self::$entries[ $file ]['ids'][] = (int) $row->attachment_id;
				continue;
			}

			self::$entries[ $file ] = array(
				'file'   => $file,
				'id'     => (int) $row->attachment_id,
				'ids'    => array( (int) $row->attachment_id ),
				'hashes' => array_values( array_unique( array_filter( array( (string) $row->content_hash, (string) $row->upload_hash ) ) ) ),
				'phash'  => (string) $row->phash,
				'width'  => (int) $row->width,
				'height' => (int) $row->height,
			);
		}

		return self::$entries;
	}

	/**
	 * The entries by each of their hashes, and by each byte of their
	 * perceptual hash in its place.
	 *
	 * @return array{hashes: array<string, string[]>, bands: array<int, array<string, string[]>>}
	 */
	private static function lookup(): array {
		if ( null !== self::$lookup ) {
			return self::$lookup;
		}

		self::$lookup = array(
			'hashes' => array(),
			'bands'  => array(),
		);

		foreach ( self::entries() as $file => $entry ) {
			foreach ( $entry['hashes'] as $hash ) {
				self::$lookup['hashes'][ $hash ][] = $file;
			}

			if ( self::comparable( $entry['phash'] ) ) {
				foreach ( str_split( $entry['phash'], 2 ) as $i => $byte ) {
					self::$lookup['bands'][ $i ][ $byte ][] = $file;
				}
			}
		}

		return self::$lookup;
	}

	/**
	 * The pairs marked as not duplicates.
	 *
	 * @return array<string, true>
	 */
	private static function dismissed(): array {
		$pairs = get_option( self::DISMISSED_OPTION, array() );

		return is_array( $pairs ) ? array_fill_keys( $pairs, true ) : array();
	}

	/**
	 * The key of a pair of attachments, in either order.
	 *
	 * @param int $a Attachment.
	 * @param int $b Attachment.
	 *
	 * @return string
	 */
	private static function pair( int $a, int $b ): string {
		return min( $a, $b ) . '-' . max( $a, $b );
	}

	/**
	 * Whether a perceptual hash can be compared.
	 *
	 * @param string $hash Hash.
	 *
	 * @return bool
	 */
	private static function comparable( string $hash ): bool {
		return 16 === strlen( $hash ) && ! Fingerprint::is_flat( $hash );
	}

	/**
	 * Whether two images have the same proportions. Sizes that are not known
	 * do not rule a match out.
	 *
	 * @param int $w1 Width.
	 * @param int $h1 Height.
	 * @param int $w2 Width.
	 * @param int $h2 Height.
	 *
	 * @return bool
	 */
	private static function same_shape( int $w1, int $h1, int $w2, int $h2 ): bool {
		if ( ! $w1 || ! $h1 || ! $w2 || ! $h2 ) {
			return true;
		}

		$a = $w1 / $h1;
		$b = $w2 / $h2;

		return abs( $a - $b ) / max( $a, $b ) <= self::ASPECT_TOLERANCE;
	}

	/**
	 * Most bits two perceptual hashes may differ in.
	 *
	 * @return int
	 */
	private static function threshold(): int {
		/**
		 * Filters how many of the 64 bits of their perceptual hashes two images
		 * may differ in to be reported as looking the same. 0 finds only
		 * copies that hash the same; at most 7.
		 *
		 * @param int $threshold Bits, 6 by default.
		 */
		return max( 0, min( 7, (int) apply_filters( 'jcore_kirjasto_duplicate_threshold', self::THRESHOLD ) ) );
	}
}
