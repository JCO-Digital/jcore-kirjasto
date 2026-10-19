<?php
/**
 * REST route for replacing the file of an attachment.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Replace\Rest;

use Jcore\Kirjasto\Replace\Replacer;
use Jcore\Kirjasto\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Takes an uploaded file and puts it in place of an attachment's file.
 */
final class Replace_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'replace';

	/**
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			$this->route(
				\WP_REST_Server::CREATABLE,
				'replace',
				array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
				'replace_permission'
			)
		);
	}

	/**
	 * Permission callback: anyone who may upload files and edit the attachment.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return bool
	 */
	public function replace_permission( \WP_REST_Request $request ): bool {
		$id = (int) $request['id'];

		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $id );
	}

	/**
	 * Replaces the file with the one uploaded as `file`, and answers with the
	 * attachment as the media library knows it.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function replace( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		require_once ABSPATH . 'wp-admin/includes/file.php';

		$id    = (int) $request['id'];
		$files = $request->get_file_params();

		if ( null === Replacer::mime_type( $id ) ) {
			return new \WP_Error( 'jcore_kirjasto_replace_attachment', __( 'The file to replace was not found.', 'jcore-kirjasto' ), array( 'status' => 404 ) );
		}

		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new \WP_Error( 'jcore_kirjasto_replace_file', __( 'No file was uploaded.', 'jcore-kirjasto' ), array( 'status' => 400 ) );
		}

		// Checks the upload as any other: its size, type and the upload filters.
		$upload = wp_handle_upload( $files['file'], array( 'test_form' => false ) );
		if ( isset( $upload['error'] ) ) {
			return new \WP_Error( 'jcore_kirjasto_replace_upload', $upload['error'], array( 'status' => 400 ) );
		}

		$result = Replacer::replace( $id, $upload['file'], wp_basename( $upload['file'] ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response( wp_prepare_attachment_for_js( $id ) );
	}
}
