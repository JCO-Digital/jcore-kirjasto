<?php
/**
 * Plugin bootstrap.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto;

use Jcore\Update\Config\UpdateConfig;
use Jcore\Update\Hooks\PluginUpdateHooks;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Loads the updater and the features.
 */
final class Plugin {

	/**
	 * The single instance.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Returns the single instance.
	 *
	 * @return Plugin
	 */
	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Registers every hook. Called once on `plugins_loaded`.
	 *
	 * @return void
	 */
	public function boot(): void {
		Database::maybe_upgrade();

		$this->register_updater();

		foreach ( self::features() as $feature ) {
			$feature::register();
		}
	}

	/**
	 * The features to load: classes with a static `register()` method.
	 *
	 * @return array<string, class-string>
	 */
	public static function features(): array {
		$features = array(
			'usage'   => Usage\Feature::class,
			'folders' => Folders\Feature::class,
		);

		/**
		 * Filters the features the plugin loads. Remove a key to turn a feature off.
		 *
		 * @param array<string, class-string> $features Feature classes, keyed by name.
		 */
		return apply_filters( 'jcore_kirjasto_features', $features );
	}

	/**
	 * Hooks the plugin into the J&Co Digital update service.
	 *
	 * The library is vendored into the release; a source checkout without a
	 * `composer install` simply runs without update checks.
	 *
	 * @return void
	 */
	private function register_updater(): void {
		if ( ! class_exists( UpdateConfig::class ) ) {
			return;
		}

		$config = new UpdateConfig(
			pluginFile: JCORE_KIRJASTO_FILE,
			slug: 'jcore-kirjasto',
			version: JCORE_KIRJASTO_VERSION,
			apiBaseUrl: 'https://update.jcore.fi/v1',
		);

		( new PluginUpdateHooks( $config ) )->register();
	}
}
