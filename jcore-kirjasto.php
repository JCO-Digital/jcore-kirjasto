<?php
/**
 * Plugin Name:       JCORE Kirjasto
 * Plugin URI:        https://github.com/JCO-Digital/jcore-kirjasto
 * Description:       Media library tools: folders for organising attachments, and where each attachment is used.
 * Version:           0.1.0
 * Requires at least: 6.7
 * Tested up to:      7.1
 * Requires PHP:      8.2
 * Author:            J&Co Digital Oy
 * Author URI:        https://jco.fi
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       jcore-kirjasto
 * Domain Path:       /languages
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'JCORE_KIRJASTO_VERSION', '0.1.0' );
define( 'JCORE_KIRJASTO_FILE', __FILE__ );
define( 'JCORE_KIRJASTO_PATH', plugin_dir_path( __FILE__ ) );
define( 'JCORE_KIRJASTO_URL', plugin_dir_url( __FILE__ ) );

// The update library is vendored into the release, but a source checkout has
// no vendor directory until `composer install` has run.
if ( is_readable( JCORE_KIRJASTO_PATH . 'vendor/autoload.php' ) ) {
	require_once JCORE_KIRJASTO_PATH . 'vendor/autoload.php';
}

/**
 * Autoloads classes from the Jcore\Kirjasto namespace.
 *
 * Maps `Jcore\Kirjasto\Foo\Bar_Baz` to `includes/foo/class-bar-baz.php`, the
 * file naming the WordPress coding standards ask for.
 *
 * @param string $class_name Fully qualified class name.
 *
 * @return void
 */
spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = __NAMESPACE__ . '\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$parts = explode( '\\', substr( $class_name, strlen( $prefix ) ) );
		$parts = array_map(
			static function ( string $part ): string {
				return strtolower( str_replace( '_', '-', $part ) );
			},
			$parts
		);

		$parts[ array_key_last( $parts ) ] = 'class-' . end( $parts );

		$file = JCORE_KIRJASTO_PATH . 'includes/' . implode( '/', $parts ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

register_activation_hook( __FILE__, array( Database::class, 'install' ) );
register_deactivation_hook( __FILE__, array( Usage\Rebuild::class, 'unschedule' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);

// Registered at file scope, not in Plugin::boot(): other JCORE components read
// this list while plugins are still loading.
add_filter(
	'jcore_plugins_loaded',
	static function ( array $plugins ): array {
		$plugins['jcore-kirjasto'] = __DIR__;

		return $plugins;
	}
);
