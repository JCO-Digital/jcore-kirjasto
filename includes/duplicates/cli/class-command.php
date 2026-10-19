<?php
/**
 * WP-CLI commands for duplicates.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates\Cli;

use Jcore\Kirjasto\Duplicates\Finder;
use Jcore\Kirjasto\Duplicates\Merger;
use Jcore\Kirjasto\Duplicates\Scan;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scans the media library for duplicates, lists them and merges them.
 */
final class Command {

	/**
	 * Fingerprints every attachment. Files that did not change since they
	 * were last hashed are not read again.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kirjasto duplicates scan
	 *
	 * @return void
	 */
	public function scan(): void {
		$total    = (int) Scan::start( false )['total'];
		$progress = \WP_CLI\Utils\make_progress_bar( 'Scanning the media library', $total );
		$done     = 0;

		do {
			$state = Scan::step();
			$now   = $state ? (int) $state['processed'] : $total;
			$progress->tick( max( 0, $now - $done ) );
			$done = $now;
		} while ( $state );

		$progress->finish();

		$counts = Finder::counts();
		$kinds  = array_count_values( wp_list_pluck( Finder::groups(), 'kind' ) );

		\WP_CLI::success(
			sprintf(
				'%d files fingerprinted, %d missing. %d groups of identical files, %d of images that look the same.',
				$counts['fingerprinted'],
				$counts['missing'],
				$kinds['identical'] ?? 0,
				$kinds['similar'] ?? 0
			)
		);
	}

	/**
	 * Lists the groups of duplicates.
	 *
	 * ## OPTIONS
	 *
	 * [--kind=<kind>]
	 * : Which groups to list.
	 * ---
	 * default: all
	 * options:
	 *   - all
	 *   - identical
	 *   - similar
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kirjasto duplicates list --kind=identical
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$kind = $assoc_args['kind'] ?? 'all';
		$rows = array();

		foreach ( Finder::groups() as $i => $group ) {
			if ( 'all' !== $kind && $kind !== $group['kind'] ) {
				continue;
			}

			foreach ( $group['entries'] as $entry ) {
				$rows[] = array(
					'group'       => $i + 1,
					'kind'        => $group['kind'],
					'attachments' => implode( ',', $entry['ids'] ),
					'file'        => $entry['file'],
				);
			}
		}

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'group', 'kind', 'attachments', 'file' ) );
	}

	/**
	 * Merges duplicates into the attachment to keep: everything using them
	 * is pointed at it, and they are deleted once nothing uses them.
	 *
	 * ## OPTIONS
	 *
	 * <keep>
	 * : The attachment to keep.
	 *
	 * <remove>...
	 * : Duplicates of it to merge into it.
	 *
	 * ## EXAMPLES
	 *
	 *     wp --user=admin kirjasto duplicates merge 123 456 789
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function merge( array $args ): void {
		if ( ! current_user_can( 'delete_posts' ) ) {
			\WP_CLI::error( 'Run this as a user who may delete media, e.g. with --user=admin.' );
		}

		$keep    = (int) array_shift( $args );
		$results = Merger::merge( $keep, array_map( 'intval', $args ) );

		if ( is_wp_error( $results ) ) {
			\WP_CLI::error( $results->get_error_message() );
		}

		foreach ( $results as $result ) {
			if ( $result['deleted'] ) {
				\WP_CLI::log( sprintf( 'Merged %d into %d and deleted it.', $result['id'], $result['into'] ) );
			} else {
				\WP_CLI::warning( sprintf( 'Merged %d into %d, but %d places still use it, so it was kept.', $result['id'], $result['into'], $result['used'] ) );
			}
		}

		\WP_CLI::success( 'Done.' );
	}
}
