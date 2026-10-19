<?php
/**
 * REST routes for finding and resolving duplicates.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates\Rest;

use Jcore\Kirjasto\Duplicates\Finder;
use Jcore\Kirjasto\Duplicates\Merger;
use Jcore\Kirjasto\Duplicates\Scan;
use Jcore\Kirjasto\Folders\Folders;
use Jcore\Kirjasto\Plugin;
use Jcore\Kirjasto\Rest\Controller;
use Jcore\Kirjasto\Usage\Report;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists the groups of duplicates, drives the scan, merges and dismisses
 * groups, and checks files about to be uploaded against the library.
 */
final class Duplicates_Controller extends Controller {

	/**
	 * Route base.
	 *
	 * @var string
	 */
	protected $rest_base = 'duplicates';

	/**
	 * Files checked per request at most.
	 */
	private const MAX_CHECKED = 100;

	/**
	 * Registers the routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		$base = '/' . $this->rest_base;
		$ids  = array(
			'type'     => 'array',
			'items'    => array( 'type' => 'integer' ),
			'required' => true,
			'minItems' => 1,
		);

		register_rest_route(
			$this->namespace,
			$base,
			$this->route(
				\WP_REST_Server::READABLE,
				'get_items',
				array(
					'kind'     => array(
						'type'    => 'string',
						'enum'    => array( 'all', 'identical', 'similar' ),
						'default' => 'all',
					),
					'page'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'default' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 20,
					),
				)
			)
		);

		register_rest_route( $this->namespace, $base . '/scan', $this->route( \WP_REST_Server::CREATABLE, 'scan' ) );
		register_rest_route( $this->namespace, $base . '/step', $this->route( \WP_REST_Server::CREATABLE, 'step' ) );
		register_rest_route( $this->namespace, $base . '/dismiss', $this->route( \WP_REST_Server::CREATABLE, 'dismiss', array( 'ids' => array_merge( $ids, array( 'minItems' => 2 ) ) ) ) );

		register_rest_route(
			$this->namespace,
			$base . '/merge',
			$this->route(
				\WP_REST_Server::CREATABLE,
				'merge',
				array(
					'keep'   => array(
						'type'     => 'integer',
						'required' => true,
					),
					'remove' => $ids,
				)
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/media/(?P<id>\d+)',
			$this->route(
				\WP_REST_Server::READABLE,
				'media',
				array(
					'id' => array(
						'type'     => 'integer',
						'required' => true,
					),
				),
				'upload_permission'
			)
		);

		register_rest_route(
			$this->namespace,
			$base . '/check',
			$this->route(
				\WP_REST_Server::CREATABLE,
				'check',
				array(
					'files' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => self::MAX_CHECKED,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'sha1'   => array(
									'type'    => 'string',
									'pattern' => '^[0-9a-f]{40}$',
								),
								'phash'  => array(
									'type'    => 'string',
									'pattern' => '^([0-9a-f]{16})?$',
								),
								'width'  => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
								'height' => array(
									'type'    => 'integer',
									'minimum' => 0,
								),
							),
						),
					),
				),
				'upload_permission'
			)
		);
	}

	/**
	 * Permission callback: anyone who may upload files.
	 *
	 * @return bool
	 */
	public function upload_permission(): bool {
		return current_user_can( 'upload_files' );
	}

	/**
	 * GET /duplicates — the scan status and a page of groups.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ): \WP_REST_Response {
		$groups = Finder::groups();
		$kind   = (string) $request['kind'];

		if ( 'all' !== $kind ) {
			$groups = array_values(
				array_filter(
					$groups,
					static function ( array $group ) use ( $kind ): bool {
						return $kind === $group['kind'];
					}
				)
			);
		}

		$per_page = (int) $request['per_page'];
		$page     = array_slice( $groups, ( (int) $request['page'] - 1 ) * $per_page, $per_page );

		$ids = array();
		foreach ( $page as $group ) {
			foreach ( $group['entries'] as $entry ) {
				$ids = array_merge( $ids, $entry['ids'] );
			}
		}

		_prime_post_caches( $ids, false, true );
		if ( $this->has_usage() ) {
			Report::prime( $ids );
		}

		return rest_ensure_response(
			array(
				'status' => $this->status(),
				'total'  => count( $groups ),
				'groups' => array_map( array( $this, 'prepare_group' ), $page ),
			)
		);
	}

	/**
	 * POST /duplicates/scan — starts a scan from the beginning.
	 *
	 * @return \WP_REST_Response
	 */
	public function scan(): \WP_REST_Response {
		Scan::start();

		return rest_ensure_response( $this->status() );
	}

	/**
	 * POST /duplicates/step — fingerprints the next batch of the running scan.
	 *
	 * @return \WP_REST_Response
	 */
	public function step(): \WP_REST_Response {
		Scan::step();

		return rest_ensure_response( $this->status() );
	}

	/**
	 * POST /duplicates/dismiss — marks a group as not duplicates.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function dismiss( $request ): \WP_REST_Response {
		Finder::dismiss( array_map( 'intval', (array) $request['ids'] ) );

		return rest_ensure_response( $this->status() );
	}

	/**
	 * POST /duplicates/merge — merges duplicates into the one to keep. Those
	 * not used anywhere are simply deleted.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function merge( $request ): \WP_REST_Response|\WP_Error {
		$results = Merger::merge( (int) $request['keep'], array_map( 'intval', (array) $request['remove'] ) );
		if ( is_wp_error( $results ) ) {
			return $results;
		}

		return rest_ensure_response(
			array(
				'results' => $results,
				'status'  => $this->status(),
			)
		);
	}

	/**
	 * GET /duplicates/media/{id} — an attachment as an upload to the media
	 * endpoint answers, for the block editor to use in place of uploading.
	 *
	 * `/wp/v2/media/{id}` would answer only those who may edit the attachment,
	 * or read the post it was uploaded to; anyone who may upload files sees
	 * every attachment in the media library, and gets it here. The raw title
	 * and caption the editor reads are given to those who may only view it.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function media( $request ): \WP_REST_Response|\WP_Error {
		$post = get_post( (int) $request['id'] );

		if ( ! $post instanceof \WP_Post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'jcore_kirjasto_not_found', __( 'The file was not found.', 'jcore-kirjasto' ), array( 'status' => 404 ) );
		}

		$inner = new \WP_REST_Request( 'GET', '/wp/v2/media/' . $post->ID );
		$inner->set_param( 'context', current_user_can( 'edit_post', $post->ID ) ? 'edit' : 'view' );

		$data = ( new \WP_REST_Attachments_Controller( 'attachment' ) )->prepare_item_for_response( $post, $inner )->get_data();

		$data['title']['raw']   = $data['title']['raw'] ?? $post->post_title;
		$data['caption']['raw'] = $data['caption']['raw'] ?? $post->post_excerpt;

		return rest_ensure_response( $data );
	}

	/**
	 * POST /duplicates/check — what the library already holds of files about
	 * to be uploaded.
	 *
	 * @param \WP_REST_Request $request The request.
	 *
	 * @return \WP_REST_Response
	 */
	public function check( $request ): \WP_REST_Response {
		$matches = array();

		foreach ( (array) $request['files'] as $file ) {
			$found = Finder::matches(
				(string) ( $file['sha1'] ?? '' ),
				(string) ( $file['phash'] ?? '' ),
				(int) ( $file['width'] ?? 0 ),
				(int) ( $file['height'] ?? 0 )
			);

			$matches[] = array_map(
				function ( array $entry ): array {
					return array( 'kind' => $entry['kind'] ) + $this->prepare_attachment( $entry['id'] );
				},
				array_slice( $found, 0, 3 )
			);
		}

		return rest_ensure_response( array( 'matches' => $matches ) );
	}

	/**
	 * A group as the screen shows it, with the entry suggested to keep: the
	 * one used in most places, then the largest, then the oldest.
	 *
	 * @param array<string, mixed> $group Group.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_group( array $group ): array {
		$entries = array_map( array( $this, 'prepare_entry' ), $group['entries'] );

		$best = null;
		foreach ( $entries as $entry ) {
			$score = array( $entry['usage']['count'], $entry['width'] * $entry['height'], -$entry['id'] );
			if ( null === $best || $score > $best[0] ) {
				$best = array( $score, $entry['id'] );
			}
		}

		return array(
			'kind'    => $group['kind'],
			'suggest' => $best[1],
			'entries' => $entries,
		);
	}

	/**
	 * An entry of a group: the file and every attachment using it.
	 *
	 * @param array<string, mixed> $entry Entry.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_entry( array $entry ): array {
		$data       = $this->prepare_attachment( $entry['id'] );
		$places     = array();
		$hidden     = 0;
		$languages  = array();
		$can_delete = true;

		foreach ( $entry['ids'] as $id ) {
			if ( $this->has_usage() ) {
				$usage   = Report::items( $id );
				$places  = array_merge( $places, $usage['items'] );
				$hidden += $usage['hidden'];
			}

			if ( function_exists( 'pll_get_post_language' ) ) {
				$languages[] = strtoupper( (string) pll_get_post_language( $id ) );
			}

			$can_delete = $can_delete && current_user_can( 'delete_post', $id );
		}

		return $data + array(
			'ids'        => $entry['ids'],
			'languages'  => array_values( array_filter( $languages ) ),
			'can_delete' => $can_delete,
			'usage'      => array(
				'known'  => $this->has_usage(),
				'count'  => count( $places ) + $hidden,
				'places' => $places,
				'hidden' => $hidden,
			),
		);
	}

	/**
	 * What the screen and the upload warning show of an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return array<string, mixed>
	 */
	private function prepare_attachment( int $attachment_id ): array {
		$post = get_post( $attachment_id );
		$meta = wp_get_attachment_metadata( $attachment_id );
		$meta = is_array( $meta ) ? $meta : array();
		$file = (string) get_attached_file( $attachment_id );
		$size = (int) ( $meta['filesize'] ?? ( is_file( $file ) ? filesize( $file ) : 0 ) );

		$thumb = wp_get_attachment_image_src( $attachment_id, 'medium', true );

		$folder = '';
		if ( isset( Plugin::features()['folders'] ) ) {
			$term   = get_term( Folders::of( $attachment_id ), Folders::TAXONOMY );
			$folder = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES ) : '';
		}

		return array(
			'id'       => $attachment_id,
			'title'    => $post ? html_entity_decode( get_the_title( $post ), ENT_QUOTES ) : '',
			'filename' => wp_basename( $file ),
			'url'      => (string) wp_get_attachment_url( $attachment_id ),
			'thumb'    => is_array( $thumb ) ? $thumb[0] : '',
			// The file itself for those who may not edit it, such as authors.
			'edit_url' => (string) ( get_edit_post_link( $attachment_id, 'raw' ) ?? wp_get_attachment_url( $attachment_id ) ),
			'mime'     => $post ? $post->post_mime_type : '',
			'width'    => (int) ( $meta['width'] ?? 0 ),
			'height'   => (int) ( $meta['height'] ?? 0 ),
			'filesize' => $size,
			'date'     => $post ? mysql_to_rfc3339( $post->post_date_gmt ) : null,
			'folder'   => $folder,
		);
	}

	/**
	 * The scan and what it found.
	 *
	 * @return array<string, mixed>
	 */
	private function status(): array {
		global $wpdb;

		$state     = Scan::state();
		$scanned   = Scan::scanned_at();
		$groups    = Finder::groups();
		$available = Merger::available();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$attachments = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE post_type = 'attachment'", $wpdb->posts ) );

		$kinds = array_count_values( wp_list_pluck( $groups, 'kind' ) );

		return array(
			'scanned_at' => $scanned ? mysql_to_rfc3339( $scanned ) : null,
			'running'    => null !== $state,
			'progress'   => $state
				? array(
					'processed' => (int) $state['processed'],
					'total'     => (int) $state['total'],
				)
				: null,
			'counts'     => array( 'attachments' => $attachments ) + Finder::counts(),
			'groups'     => array(
				'identical' => (int) ( $kinds['identical'] ?? 0 ),
				'similar'   => (int) ( $kinds['similar'] ?? 0 ),
			),
			'can_merge'  => true === $available,
			'merge_note' => is_wp_error( $available ) ? $available->get_error_message() : '',
		);
	}

	/**
	 * Whether the usage index is there to say where files are used.
	 *
	 * @return bool
	 */
	private function has_usage(): bool {
		return isset( Plugin::features()['usage'] );
	}
}
