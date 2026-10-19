<?php
/**
 * Removes everything the plugin stored when it is deleted.
 *
 * @package Jcore\Kirjasto
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

/**
 * Drops the plugin's tables, options, meta and cron events on the current site.
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
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . 'jcore_kirjasto_fingerprints' ) );

	delete_option( 'jcore_kirjasto_db_version' );
	delete_option( 'jcore_kirjasto_index' );
	delete_option( 'jcore_kirjasto_rebuild' );
	delete_option( 'jcore_kirjasto_theme' );
	delete_option( 'jcore_kirjasto_duplicates' );
	delete_option( 'jcore_kirjasto_duplicates_scan' );
	delete_option( 'jcore_kirjasto_duplicates_dismissed' );
	delete_option( 'jcore_kirjasto_duplicates_revision' );
	delete_transient( 'jcore_kirjasto_duplicate_groups' );
	delete_post_meta_by_key( '_jcore_kirjasto_upload_hash' );
	delete_post_meta_by_key( '_jcore_kirjasto_replaced' );
	wp_clear_scheduled_hook( 'jcore_kirjasto_rebuild' );
	wp_clear_scheduled_hook( 'jcore_kirjasto_duplicates_scan' );
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
