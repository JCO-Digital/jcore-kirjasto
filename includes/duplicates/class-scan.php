<?php
/**
 * Full scans of the media library for fingerprints.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Fingerprints every attachment, a batch at a time.
 *
 * The cursor lives in an option, so WP-Cron, the duplicates screen and WP-CLI
 * can all advance the same scan. Files that did not change since they were
 * last hashed are not read again. Fingerprints the scan did not touch belong
 * to attachments that are gone, and are dropped when it finishes.
 */
final class Scan {

	/**
	 * Option holding the running scan: cursor and progress.
	 */
	public const STATE_OPTION = 'jcore_kirjasto_duplicates_scan';

	/**
	 * Option holding the last finished scan: when and with which rules.
	 */
	public const STATUS_OPTION = 'jcore_kirjasto_duplicates';

	/**
	 * Cron hook that advances the scan in the background.
	 */
	private const CRON_HOOK = 'jcore_kirjasto_duplicates_scan';

	/**
	 * Attachments fingerprinted per step.
	 */
	private const BATCH_SIZE = 25;

	/**
	 * Seconds one cron run spends on steps before handing over to the next.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Hooks the cron runner, and starts a scan on first install and whenever
	 * the hashing rules change.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_cron' ) );
		add_action( 'init', array( self::class, 'maybe_start' ), 99 );
	}

	/**
	 * Starts a scan if there is none or it used older rules, and makes sure a
	 * running one keeps going in the background.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( self::is_running() ) {
			self::schedule();
		} elseif ( Fingerprint::VERSION !== ( get_option( self::STATUS_OPTION )['version'] ?? null ) ) {
			self::start();
		}
	}

	/**
	 * Starts a scan from the beginning, replacing one that is running.
	 *
	 * @param bool $in_background Whether WP-Cron should advance it.
	 *
	 * @return array<string, mixed> The new state.
	 */
	public static function start( bool $in_background = true ): array {
		$state = array(
			'started_at' => current_time( 'mysql', true ),
			'after'      => 0,
			'processed'  => 0,
			'total'      => self::count(),
		);

		update_option( self::STATE_OPTION, $state, true );

		if ( $in_background ) {
			self::schedule();
		}

		return $state;
	}

	/**
	 * Fingerprints the next batch. Finishes the scan after the last one.
	 *
	 * @return array<string, mixed>|null The state after the step, or null when
	 *                                   no scan is running any more.
	 */
	public static function step(): ?array {
		$state = self::state();
		if ( ! $state ) {
			return null;
		}

		$ids = self::next( (int) $state['after'] );
		_prime_post_caches( $ids, false, true );
		array_map( array( Indexer::class, 'index' ), $ids );

		$state['processed'] += count( $ids );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			self::finish( $state );
			return null;
		}

		$state['after'] = (int) end( $ids );
		update_option( self::STATE_OPTION, $state, true );

		return $state;
	}

	/**
	 * Runs steps for one cron event, and queues another if work is left.
	 *
	 * @return void
	 */
	public static function run_cron(): void {
		$deadline = microtime( true ) + self::TIME_BUDGET;

		do {
			$state = self::step();
		} while ( $state && microtime( true ) < $deadline );

		if ( $state ) {
			self::schedule();
		}
	}

	/**
	 * Removes the cron event. Called on deactivation.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * The running scan, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::STATE_OPTION );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Whether a scan is running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		return null !== self::state();
	}

	/**
	 * When the last scan finished, as a GMT MySQL datetime.
	 *
	 * @return string|null
	 */
	public static function scanned_at(): ?string {
		return get_option( self::STATUS_OPTION )['scanned_at'] ?? null;
	}

	/**
	 * The next batch of attachment IDs.
	 *
	 * @param int $after Last ID already fingerprinted.
	 *
	 * @return int[]
	 */
	private static function next( int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM %i WHERE post_type = 'attachment' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$wpdb->posts,
				$after,
				self::BATCH_SIZE
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * How many attachments a scan fingerprints.
	 *
	 * @return int
	 */
	private static function count(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'attachment'", $wpdb->posts ) );
	}

	/**
	 * Drops the fingerprints no step touched and records the finished scan.
	 *
	 * @param array<string, mixed> $state Final state.
	 *
	 * @return void
	 */
	private static function finish( array $state ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		if ( $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE hashed_at < %s', Database::table( 'fingerprints' ), $state['started_at'] ) ) ) {
			Indexer::bump_revision();
		}

		update_option(
			self::STATUS_OPTION,
			array(
				'version'    => Fingerprint::VERSION,
				'scanned_at' => current_time( 'mysql', true ),
			),
			true
		);

		delete_option( self::STATE_OPTION );
		self::unschedule();
	}

	/**
	 * Queues the cron event unless it is already queued.
	 *
	 * @return void
	 */
	private static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}
}
