<?php
/**
 * REST routes for the usage index.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Usage\Rest;

use Jcore\Kirjasto\Database;
use Jcore\Kirjasto\Rest\Controller;
use Jcore\Kirjasto\Usage\Admin\Media_Library;
use Jcore\Kirjasto\Usage\Rebuild;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports on the index and lets the usage screen drive a rebuild.
 */
final class Index_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'index';

	/**
	 * Registers the index routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, $this->route( \WP_REST_Server::READABLE, 'get_item' ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/rebuild', $this->route( \WP_REST_Server::CREATABLE, 'rebuild' ) );
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/step', $this->route( \WP_REST_Server::CREATABLE, 'step' ) );
	}

	/**
	 * GET /index — when the index was built, rebuild progress and totals.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ): \WP_REST_Response {
		return rest_ensure_response( $this->status() );
	}

	/**
	 * POST /index/rebuild — starts a rebuild from the beginning.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function rebuild( $request ): \WP_REST_Response {
		Rebuild::start();

		return rest_ensure_response( $this->status() );
	}

	/**
	 * POST /index/step — indexes the next batch of the running rebuild.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function step( $request ): \WP_REST_Response {
		Rebuild::step();

		return rest_ensure_response( $this->status() );
	}

	/**
	 * The response every route returns.
	 *
	 * @return array<string, mixed>
	 */
	private function status(): array {
		global $wpdb;

		$table = Database::table( 'usage' );
		$state = Rebuild::state();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attachments = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'attachment'", $wpdb->posts ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$used = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT attachment_id) FROM %i', $table ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$references = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table ) );

		$built_at = Rebuild::built_at();

		return array(
			'built_at' => $built_at ? mysql_to_rfc3339( $built_at ) : null,
			'running'  => null !== $state,
			'progress' => $state
				? array(
					'processed' => (int) $state['processed'],
					'total'     => (int) $state['total'],
				)
				: null,
			'stats'    => array(
				'attachments' => $attachments,
				'used'        => $used,
				'unused'      => max( 0, $attachments - $used ),
				'references'  => $references,
			),
			'links'    => array(
				'used'   => admin_url( 'upload.php?mode=list&' . Media_Library::KEY . '=used' ),
				'unused' => admin_url( 'upload.php?mode=list&' . Media_Library::KEY . '=unused' ),
			),
		);
	}
}
