<?php
/**
 * The usage feature: where every attachment is used.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires the usage index, its screens, routes and commands to their hooks.
 */
final class Feature {

	/**
	 * Registers every hook of the feature.
	 *
	 * @return void
	 */
	public static function register(): void {
		Indexer::init();
		Rebuild::init();

		add_action(
			'rest_api_init',
			static function (): void {
				( new Rest\Index_Controller() )->register_routes();
			}
		);

		if ( is_admin() ) {
			Admin\Page::register();
			Admin\Media_Library::register();
			add_filter( 'plugin_action_links_' . plugin_basename( JCORE_KIRJASTO_FILE ), array( self::class, 'action_links' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'kirjasto usage', Cli\Command::class );
		}
	}

	/**
	 * Adds a link to the usage screen on the Plugins screen.
	 *
	 * @param string[] $links Existing action links.
	 *
	 * @return string[]
	 */
	public static function action_links( array $links ): array {
		if ( current_user_can( 'manage_options' ) ) {
			array_unshift(
				$links,
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( Admin\Page::url() ),
					esc_html__( 'Usage index', 'jcore-kirjasto' )
				)
			);
		}

		return $links;
	}
}
