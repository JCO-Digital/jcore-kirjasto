<?php
/**
 * WP-CLI commands for media folders.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders\Cli;

use Jcore\Kirjasto\Folders\Folders;
use Jcore\Kirjasto\Folders\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists media folders and imports them from other plugins.
 */
final class Command {

	/**
	 * Lists the folders as a tree, with the number of files in each.
	 *
	 * ## OPTIONS
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
	 *     wp kirjasto folders list
	 *
	 * @subcommand list
	 *
	 * @param string[]              $args       Positional arguments.
	 * @param array<string, string> $assoc_args Named arguments.
	 *
	 * @return void
	 */
	public function list_( array $args, array $assoc_args ): void {
		$tree     = Folders::tree();
		$children = array();
		foreach ( $tree['folders'] as $folder ) {
			$children[ $folder['parent'] ][] = $folder;
		}

		$rows = array();
		$walk = static function ( int $parent_id, int $depth ) use ( &$walk, &$rows, $children ): void {
			$folders = $children[ $parent_id ] ?? array();
			usort(
				$folders,
				static function ( array $a, array $b ): int {
					return strnatcasecmp( $a['name'], $b['name'] );
				}
			);

			foreach ( $folders as $folder ) {
				$rows[] = array(
					'id'     => $folder['id'],
					'folder' => str_repeat( '  ', $depth ) . $folder['name'],
					'parent' => $folder['parent'],
					'files'  => $folder['count'],
				);
				$walk( $folder['id'], $depth + 1 );
			}
		};
		$walk( 0, 0 );

		\WP_CLI\Utils\format_items( $assoc_args['format'] ?? 'table', $rows, array( 'id', 'folder', 'parent', 'files' ) );
		\WP_CLI::log( sprintf( '%d files, %d without a folder.', $tree['total'], $tree['unassigned'] ) );
	}

	/**
	 * Imports folders from another media folder plugin. The plugin does not
	 * need to be active; its data is left as it is.
	 *
	 * ## OPTIONS
	 *
	 * [<source>]
	 * : Where to import from. Lists the sources with folders when left out.
	 * ---
	 * options:
	 *   - filebird
	 *   - happyfiles
	 *   - enhanced-media-library
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp kirjasto folders import
	 *     wp kirjasto folders import filebird
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function import( array $args ): void {
		if ( empty( $args[0] ) ) {
			$sources = Importer::sources();

			if ( ! $sources ) {
				\WP_CLI::log( 'No folders to import were found.' );
				return;
			}

			\WP_CLI\Utils\format_items( 'table', $sources, array( 'source', 'label', 'folders', 'files' ) );
			return;
		}

		$offset   = 0;
		$moved    = 0;
		$progress = null;

		do {
			$step = Importer::step( $args[0], $offset );
			if ( is_wp_error( $step ) ) {
				\WP_CLI::error( $step );
			}

			$progress ??= \WP_CLI\Utils\make_progress_bar( 'Importing', $step['total'] );
			$progress->tick( $step['offset'] - $offset );

			$offset = $step['offset'];
			$moved += $step['moved'];
		} while ( ! $step['done'] );

		$progress->finish();

		\WP_CLI::success( sprintf( 'Imported %d folders and moved %d files into them.', $step['imported'], $moved ) );
	}
}
