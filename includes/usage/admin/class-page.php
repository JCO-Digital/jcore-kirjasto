<?php
/**
 * The Media > Usage screen and the React app it hosts.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage\Admin;

use Jcore\Kirjasto\Assets;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the usage screen and enqueues its assets.
 */
final class Page {

	/**
	 * Page slug.
	 */
	public const SLUG = 'jcore-kirjasto';

	/**
	 * Hook suffix `add_media_page()` returns for this screen.
	 */
	private const HOOK = 'media_page_' . self::SLUG;

	/**
	 * Build entry the screen runs on.
	 */
	private const ENTRY = 'usage-page';

	/**
	 * Hooks the page and its assets.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'add_page' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	/**
	 * Returns the admin URL of the usage screen.
	 *
	 * @return string
	 */
	public static function url(): string {
		return admin_url( 'upload.php?page=' . self::SLUG );
	}

	/**
	 * Registers the Media > Usage sub-page.
	 *
	 * @return void
	 */
	public static function add_page(): void {
		add_media_page(
			__( 'Media Usage', 'jcore-kirjasto' ),
			__( 'Usage', 'jcore-kirjasto' ),
			'manage_options',
			self::SLUG,
			array( self::class, 'render_page' )
		);
	}

	/**
	 * Outputs the mount point the React app renders into.
	 *
	 * @return void
	 */
	public static function render_page(): void {
		require JCORE_KIRJASTO_PATH . 'views/usage/page.php';
	}

	/**
	 * Enqueues the React app, on this screen only.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_assets( string $hook ): void {
		if ( self::HOOK !== $hook ) {
			return;
		}

		Assets::enqueue_script( self::ENTRY );
		Assets::enqueue_style( self::ENTRY, array( 'wp-components' ) );
	}
}
