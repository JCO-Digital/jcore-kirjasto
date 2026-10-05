<?php
/**
 * WP-CLI commands.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage\Cli;

use Jcore\Kirjasto\Database;
use Jcore\Kirjasto\Usage\Rebuild;
use Jcore\Kirjasto\Usage\Scanner;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Rebuilds and inspects the media usage index.
 */
final class Command {

	/**
	 * Rebuilds the usage index in the foreground.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kirjasto usage rebuild
	 *
	 * @return void
	 */
	public function rebuild(): void {
		$state    = Rebuild::start( false );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing', $state['total'] );
		$done     = 0;

		while ( $state ) {
			$state = Rebuild::step();
			$now   = $state ? (int) $state['processed'] : (int) $progress->total();

			$progress->tick( max( 0, $now - $done ) );
			$done = $now;
		}

		$progress->finish();

		\WP_CLI::success( 'Usage index rebuilt.' );
	}

	/**
	 * Lists every place an attachment is recorded as used.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>
	 * : The attachment ID.
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
	 *     wp kirjasto usage list 123
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT object_type, object_id, context, source FROM %i WHERE attachment_id = %d ORDER BY object_type, object_id, id', Database::table( 'usage' ), (int) $args[0] ), ARRAY_A );

		foreach ( $rows as &$row ) {
			$row['title'] = $this->title( $row );

			if ( 'field' === $row['context'] ) {
				$field         = Scanner::acf_field( $row['source'] );
				$row['source'] = $field ? $field['name'] . ' (' . $row['source'] . ')' : $row['source'];
			}
		}
		unset( $row );

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'object_type', 'object_id', 'title', 'context', 'source' ) );
	}

	/**
	 * A readable name for the object a row points at.
	 *
	 * @param array<string, string> $row Index row.
	 *
	 * @return string
	 */
	private function title( array $row ): string {
		switch ( $row['object_type'] ) {
			case 'post':
				return (string) get_the_title( (int) $row['object_id'] );

			case 'term':
				$term = get_term( (int) $row['object_id'] );

				return $term instanceof \WP_Term ? $term->name : '';

			case 'theme':
				$theme = wp_get_theme( (string) strtok( $row['source'], '/' ) );

				return $theme->exists() ? (string) $theme->display( 'Name', false ) : '';

			default:
				return 'Settings';
		}
	}
}
