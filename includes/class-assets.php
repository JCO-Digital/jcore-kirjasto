<?php
/**
 * Enqueues the bundles in `build/`.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads a `@wordpress/scripts` entry: its script with the dependencies the
 * build recorded, its translations and its style.
 *
 * Every entry is enqueued under the handle `jcore-kirjasto-{entry}`.
 */
final class Assets {

	/**
	 * The handle of an entry.
	 *
	 * @param string $entry Build entry, e.g. `folders`.
	 *
	 * @return string
	 */
	public static function handle( string $entry ): string {
		return 'jcore-kirjasto-' . $entry;
	}

	/**
	 * Enqueues the script of an entry, with its translations.
	 *
	 * @param string   $entry Build entry.
	 * @param string[] $deps  Dependencies the build cannot know about, e.g. `media-views`.
	 *
	 * @return bool Whether the entry has been built.
	 */
	public static function enqueue_script( string $entry, array $deps = array() ): bool {
		$asset_file = JCORE_KIRJASTO_PATH . 'build/' . $entry . '.asset.php';
		if ( ! is_readable( $asset_file ) ) {
			return false;
		}
		$asset = require $asset_file;

		wp_enqueue_script(
			self::handle( $entry ),
			JCORE_KIRJASTO_URL . 'build/' . $entry . '.js',
			array_merge( $asset['dependencies'], $deps ),
			$asset['version'],
			true
		);

		wp_set_script_translations( self::handle( $entry ), 'jcore-kirjasto', JCORE_KIRJASTO_PATH . 'languages' );

		return true;
	}

	/**
	 * Enqueues the style of an entry.
	 *
	 * Versioned by file time: the asset hash only changes with the script.
	 *
	 * @param string   $entry Build entry.
	 * @param string[] $deps  Style dependencies.
	 *
	 * @return void
	 */
	public static function enqueue_style( string $entry, array $deps = array() ): void {
		$style_file = JCORE_KIRJASTO_PATH . 'build/style-' . $entry . '.css';

		if ( is_readable( $style_file ) ) {
			wp_enqueue_style(
				self::handle( $entry ),
				JCORE_KIRJASTO_URL . 'build/style-' . $entry . '.css',
				$deps,
				(string) filemtime( $style_file )
			);
		}
	}
}
