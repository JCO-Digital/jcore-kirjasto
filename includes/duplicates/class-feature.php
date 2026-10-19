<?php
/**
 * The duplicates feature: finding and merging duplicate files.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the fingerprints, the scan, the screens, the upload check, the routes
 * and the commands to their hooks.
 */
final class Feature {

	/**
	 * Registers every hook of the feature.
	 *
	 * @return void
	 */
	public static function register(): void {
		Indexer::init();
		Scan::init();

		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\Duplicates_Controller() )->register_routes();
			}
		);

		// Also outside the admin: the media modal can open on the front end.
		Admin\Media_Library::register();

		if ( is_admin() ) {
			Admin\Page::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kirjasto duplicates', Cli\Command::class );
		}
	}
}
