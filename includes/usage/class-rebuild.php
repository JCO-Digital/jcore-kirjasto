<?php
/**
 * Full rebuilds of the usage index.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

use Jcore\Kirjasto\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Re-indexes every post and term, the theme and the settings, a batch at a time.
 *
 * The cursor lives in an option, so WP-Cron, the usage screen and WP-CLI can
 * all advance the same rebuild, and an interrupted one resumes where it was.
 * References the rebuild did not touch belong to objects that are gone, and are
 * dropped when it finishes.
 */
final class Rebuild {

	/**
	 * Option holding the running rebuild: phase, cursor and progress.
	 */
	public const STATE_OPTION = 'jcore_kirjasto_rebuild';

	/**
	 * Option holding the last finished rebuild: when and with which scanner.
	 */
	public const STATUS_OPTION = 'jcore_kirjasto_index';

	/**
	 * Cron hook that advances the rebuild in the background.
	 */
	private const CRON_HOOK = 'jcore_kirjasto_rebuild';

	/**
	 * Objects indexed per step.
	 */
	private const BATCH_SIZE = 50;

	/**
	 * Seconds one cron run spends on steps before handing over to the next.
	 */
	private const TIME_BUDGET = 20;

	/**
	 * Hooks the cron runner, and starts a rebuild on first install and whenever
	 * the scanning rules change.
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( self::CRON_HOOK, array( self::class, 'run_cron' ) );

		// Late on `init`: the post count needs every post type registered.
		add_action( 'init', array( self::class, 'maybe_start' ), 99 );
	}

	/**
	 * Starts a rebuild if the index is missing or was built by older rules,
	 * and makes sure a running one keeps going in the background.
	 *
	 * @return void
	 */
	public static function maybe_start(): void {
		if ( self::is_running() ) {
			self::schedule();
		} elseif ( Scanner::VERSION !== ( get_option( self::STATUS_OPTION )['version'] ?? null ) ) {
			self::start();
		}
	}

	/**
	 * Starts a rebuild from the beginning, replacing one that is running.
	 *
	 * @param bool $in_background Whether WP-Cron should advance it.
	 *
	 * @return array<string, mixed> The new state.
	 */
	public static function start( bool $in_background = true ): array {
		$state = array(
			'started_at' => current_time( 'mysql', true ),
			'phase'      => 'posts',
			'after'      => 0,
			'processed'  => 0,
			'total'      => self::count_posts() + self::count_terms() + 2,
		);

		update_option( self::STATE_OPTION, $state, true );

		if ( $in_background ) {
			self::schedule();
		}

		return $state;
	}

	/**
	 * Indexes the next batch. Finishes the rebuild after the last one.
	 *
	 * @return array<string, mixed>|null The state after the step, or null when
	 *                                   no rebuild is running any more.
	 */
	public static function step(): ?array {
		$state = self::state();
		if ( ! $state ) {
			return null;
		}

		switch ( $state['phase'] ) {
			case 'posts':
				$ids = self::next_posts( (int) $state['after'] );
				_prime_post_caches( $ids, false, true );
				array_map( array( Indexer::class, 'index_post' ), $ids );
				$state = self::advance( $state, $ids, 'terms' );
				break;

			case 'terms':
				$ids = self::next_terms( (int) $state['after'] );
				update_termmeta_cache( $ids );
				array_map( array( Indexer::class, 'index_term' ), $ids );
				$state = self::advance( $state, $ids, 'theme' );
				break;

			case 'theme':
				Indexer::index_theme();
				++$state['processed'];
				$state['phase'] = 'settings';
				break;

			default:
				Indexer::index_settings();
				self::finish( $state );
				return null;
		}

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
	 * The running rebuild, if any.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function state(): ?array {
		$state = get_option( self::STATE_OPTION );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Whether a rebuild is running.
	 *
	 * @return bool
	 */
	public static function is_running(): bool {
		return null !== self::state();
	}

	/**
	 * Whether a rebuild has ever finished, i.e. whether "not used" can be trusted.
	 *
	 * @return bool
	 */
	public static function is_built(): bool {
		return ! empty( get_option( self::STATUS_OPTION )['built_at'] );
	}

	/**
	 * When the last rebuild finished, as a GMT MySQL datetime.
	 *
	 * @return string|null
	 */
	public static function built_at(): ?string {
		return get_option( self::STATUS_OPTION )['built_at'] ?? null;
	}

	/**
	 * Moves the cursor past a batch, or on to the next phase after the last one.
	 *
	 * @param array<string, mixed> $state Current state.
	 * @param int[]                $ids   IDs just indexed.
	 * @param string               $next  Phase that follows.
	 *
	 * @return array<string, mixed>
	 */
	private static function advance( array $state, array $ids, string $next ): array {
		$state['processed'] += count( $ids );

		if ( count( $ids ) < self::BATCH_SIZE ) {
			$state['phase'] = $next;
			$state['after'] = 0;
		} else {
			$state['after'] = (int) end( $ids );
		}

		return $state;
	}

	/**
	 * Drops the references no step touched and records the finished rebuild.
	 *
	 * @param array<string, mixed> $state Final state.
	 *
	 * @return void
	 */
	private static function finish( array $state ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE indexed_at < %s', Database::table( 'usage' ), $state['started_at'] ) );

		update_option(
			self::STATUS_OPTION,
			array(
				'version'  => Scanner::VERSION,
				'built_at' => current_time( 'mysql', true ),
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

	/**
	 * The next batch of indexable post IDs.
	 *
	 * @param int $after Last ID already indexed.
	 *
	 * @return int[]
	 */
	private static function next_posts( int $after ): array {
		global $wpdb;

		list( $where, $args ) = self::posts_where();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds generated placeholders.
				"SELECT ID FROM %i WHERE ID > %d AND $where ORDER BY ID ASC LIMIT %d",
				$wpdb->posts,
				$after,
				...array_merge( $args, array( self::BATCH_SIZE ) )
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * How many posts a rebuild indexes.
	 *
	 * @return int
	 */
	private static function count_posts(): int {
		global $wpdb;

		list( $where, $args ) = self::posts_where();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Spread arguments.
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $where holds generated placeholders.
				"SELECT COUNT(*) FROM %i WHERE $where",
				$wpdb->posts,
				...$args
			)
		);
	}

	/**
	 * The condition selecting indexable posts, with its placeholder values.
	 *
	 * @return array{0: string, 1: string[]}
	 */
	private static function posts_where(): array {
		$types    = Indexer::post_types();
		$statuses = Indexer::EXCLUDED_STATUSES;

		$where = sprintf(
			'post_type IN (%s) AND post_status NOT IN (%s)',
			implode( ',', array_fill( 0, count( $types ), '%s' ) ),
			implode( ',', array_fill( 0, count( $statuses ), '%s' ) )
		);

		return array( $where, array_merge( $types, $statuses ) );
	}

	/**
	 * The next batch of IDs of terms that have meta. Terms without meta cannot
	 * refer to anything.
	 *
	 * @param int $after Last ID already indexed.
	 *
	 * @return int[]
	 */
	private static function next_terms( int $after ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT term_id FROM %i WHERE term_id > %d ORDER BY term_id ASC LIMIT %d', $wpdb->termmeta, $after, self::BATCH_SIZE ) );

		return array_map( 'intval', $ids );
	}

	/**
	 * How many terms a rebuild indexes.
	 *
	 * @return int
	 */
	private static function count_terms(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT term_id) FROM %i', $wpdb->termmeta ) );
	}
}
