<?php
/**
 * WP-CLI command for replacing files.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Replace\Cli;

use Jcore\Kirjasto\Replace\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the file of an attachment.
 */
final class Command {

	/**
	 * Replaces the file of an attachment with another file of the same type.
	 *
	 * The attachment keeps its ID and its file name, so everything that uses
	 * it shows the new file.
	 *
	 * ## OPTIONS
	 *
	 * <attachment-id>
	 * : The attachment.
	 *
	 * <file>
	 * : The new file. It is copied, and left where it is.
	 *
	 * ## EXAMPLES
	 *
	 *     wp kirjasto replace 123 ~/new-logo.png
	 *
	 * @param string[] $args Positional arguments.
	 *
	 * @return void
	 */
	public function __invoke( array $args ): void {
		[ $attachment_id, $file ] = $args;

		if ( ! is_readable( $file ) ) {
			\WP_CLI::error( sprintf( 'Cannot read %s.', $file ) );
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';

		// Replacer moves the file it is given; it gets a copy instead.
		$copy = wp_tempnam( $file );
		if ( ! $copy || ! copy( $file, $copy ) ) {
			\WP_CLI::error( 'Could not copy the file.' );
		}

		$result = Replacer::replace( (int) $attachment_id, $copy, wp_basename( $file ) );

		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}

		\WP_CLI::success( sprintf( 'Replaced the file of attachment %d.', $attachment_id ) );
	}
}
