<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * @package Jcore\Kirjasto
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drops the plugin's table, options and cron event on the current site.
 *
 * Names are spelled out rather than read from the classes so this file stands
 * alone, as uninstall.php runs without the plugin loaded.
 *
 * @return void
 */
function jcore_kirjasto_uninstall_site(): void {
	global $wpdb;

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'jcore_kirjasto_usage' ) );

	delete_option( 'jcore_kirjasto_db_version' );
	delete_option( 'jcore_kirjasto_index' );
	delete_option( 'jcore_kirjasto_rebuild' );
	delete_option( 'jcore_kirjasto_theme' );
	wp_clear_scheduled_hook( 'jcore_kirjasto_rebuild' );
}

if ( is_multisite() ) {
	$jcore_kirjasto_site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $jcore_kirjasto_site_ids as $jcore_kirjasto_site_id ) {
		switch_to_blog( (int) $jcore_kirjasto_site_id );
		jcore_kirjasto_uninstall_site();
		restore_current_blog();
	}
} else {
	jcore_kirjasto_uninstall_site();
}
