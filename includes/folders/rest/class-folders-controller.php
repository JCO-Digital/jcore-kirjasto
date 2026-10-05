<?php
/**
 * REST routes for media folders.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders\Rest;

use Jcore\Kirjasto\Folders\Folders;
use Jcore\Kirjasto\Folders\Importer;
use Jcore\Kirjasto\Rest\Controller;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists, creates, renames, moves and deletes folders, moves attachments
 * between them and imports folders from other plugins.
 *
 * Every response that changes folders returns the whole tree, so the folder
 * list can redraw from it without another request.
 */
final class Folders_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'folders';

	/**
	 * Registers the folder routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base   = '/' . $this->rest_base;
		$id     = array(
			'type'     => 'integer',
			'required' => true,
		);
		$name   = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 200,
		);
		$parent = array(
			'type'    => 'integer',
			'minimum' => 0,
		);

		register_rest_route(
			$this->namespace,
			$base,
			array(
				$this->route( \WP_REST_Server::READABLE, 'get_items', array(), 'view_permission' ),
				$this->route(
					\WP_REST_Server::CREATABLE,
					'create_item',
					array(
						'name'   => array_merge( $name, array( 'required' => true ) ),
						'parent' => array_merge( $parent, array( 'default' => 0 ) ),
					),
					'manage_permission'
				),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/(?P<id>\d+)',
			array(
				$this->route(
					\WP_REST_Server::EDITABLE,
					'update_item',
					array(
						'id'     => $id,
						'name'   => $name,
						'parent' => $parent,
					),
					'manage_permission'
				),
				$this->route( \WP_REST_Server::DELETABLE, 'delete_item', array( 'id' => $id ), 'manage_permission' ),
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/move',
			$this->route(
				\WP_REST_Server::CREATABLE,
				'move',
				array(
					'attachments' => array(
						'type'     => 'array',
						'items'    => array( 'type' => 'integer' ),
						'required' => true,
						'minItems' => 1,
					),
					'folder'      => array_merge( $parent, array( 'required' => true ) ),
				),
				'view_permission'
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/import',
			array(
				$this->route( \WP_REST_Server::READABLE, 'import_sources', array(), 'manage_permission' ),
				$this->route(
					\WP_REST_Server::CREATABLE,
					'import',
					array(
						'source' => array(
							'type'     => 'string',
							'required' => true,
						),
						'offset' => array(
							'type'    => 'integer',
							'minimum' => 0,
							'default' => 0,
						),
					),
					'manage_permission'
				),
			)
		);
	}

	/**
	 * Permission callback: anyone who may use the media library.
	 *
	 * @return bool
	 */
	public function view_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * Permission callback: who may create, rename, move and delete folders.
	 *
	 * @return bool
	 */
	public function manage_permission(): bool {
		return current_user_can( Folders::capability() );
	}

	/**
	 * GET /folders — every folder with its count, and the totals.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		return rest_ensure_response( Folders::tree() );
	}

	/**
	 * POST /folders — creates a folder.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function create_item( $request ) {
		$id = Folders::create( (string) $request['name'], (int) $request['parent'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return rest_ensure_response( array_merge( array( 'id' => $id ), Folders::tree() ) );
	}

	/**
	 * POST|PUT|PATCH /folders/{id} — renames a folder or moves it.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$result = Folders::update(
			(int) $request['id'],
			isset( $request['name'] ) ? (string) $request['name'] : null,
			isset( $request['parent'] ) ? (int) $request['parent'] : null
		);

		return is_wp_error( $result ) ? $result : rest_ensure_response( Folders::tree() );
	}

	/**
	 * DELETE /folders/{id} — deletes a folder; its contents move to its parent.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_item( $request ) {
		$result = Folders::delete( (int) $request['id'] );

		return is_wp_error( $result ) ? $result : rest_ensure_response( Folders::tree() );
	}

	/**
	 * POST /folders/move — moves attachments into a folder, or out of their
	 * folder with `folder: 0`. Attachments the user may not edit are skipped.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function move( $request ) {
		$ids = array_filter(
			array_unique( array_map( 'intval', (array) $request['attachments'] ) ),
			static function ( int $id ): bool {
				return 'attachment' === get_post_type( $id ) && current_user_can( 'edit_post', $id );
			}
		);

		if ( ! $ids ) {
			return new \WP_Error( 'jcore_kirjasto_move_forbidden', __( 'You are not allowed to move these files.', 'jcore-kirjasto' ), array( 'status' => 403 ) );
		}

		$moved = Folders::move( $ids, (int) $request['folder'] );
		if ( is_wp_error( $moved ) ) {
			return $moved;
		}

		return rest_ensure_response( array_merge( array( 'moved' => $moved ), Folders::tree() ) );
	}

	/**
	 * GET /folders/import — the plugins folders can be imported from.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response
	 */
	public function import_sources( $request ): \WP_REST_Response {
		return rest_ensure_response( Importer::sources() );
	}

	/**
	 * POST /folders/import — runs one step of an import.
	 *
	 * @param \WP_REST_Request $request The REST request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function import( $request ) {
		$step = Importer::step( (string) $request['source'], (int) $request['offset'] );
		if ( is_wp_error( $step ) ) {
			return $step;
		}

		return rest_ensure_response( array_merge( $step, Folders::tree() ) );
	}
}
