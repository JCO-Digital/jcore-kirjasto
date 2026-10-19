<?php
/**
 * The replace feature: a new file in place of an attachment's file.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Replace;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires replacing files to the media library, the route and the command.
 */
final class Feature {

	/**
	 * Registers every hook of the feature.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\Replace_Controller() )->register_routes();
			}
		);

		// Also outside the admin: the media modal can open on the front end.
		Admin\Media_Library::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kirjasto replace', Cli\Command::class );
		}
	}
}
