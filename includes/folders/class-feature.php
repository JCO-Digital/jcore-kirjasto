<?php
/**
 * The folders feature: a folder tree for the media library.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the folder taxonomy, the media library integration, the routes and
 * the commands to their hooks.
 */
final class Feature {

	/**
	 * Registers every hook of the feature.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'init', array( Folders::class, 'register_taxonomy' ) );
		add_filter( 'pll_copy_taxonomies', array( Folders::class, 'copy_to_translations' ) );

		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\Folders_Controller() )->register_routes();
			}
		);

		// Also outside the admin: the media modal can open on the front end.
		Admin\Media_Library::register();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kirjasto folders', Cli\Command::class );
		}
	}
}
