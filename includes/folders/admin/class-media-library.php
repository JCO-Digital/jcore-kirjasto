<?php
/**
 * Folders inside the media library.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Folders\Admin;

use Jcore\Kirjasto\Assets;
use Jcore\Kirjasto\Folders\Folders;
use Jcore\Kirjasto\Folders\Importer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Filters the media library by folder, adds the folder field to the
 * attachment details, puts uploads into the open folder, adds the "Move to
 * folder" bulk action and loads the folder list.
 *
 * The folder list itself is the `folders` script: on the Media screen it sits
 * beside the library, in grid and list mode; in the media modal it sits
 * inside the library tab.
 */
final class Media_Library {

	/**
	 * Build entry of the folder list.
	 */
	private const ENTRY = 'folders';

	/**
	 * Name of the "Folder" attachment field. It must differ from the taxonomy
	 * name: core saves an attachment field named after a taxonomy itself,
	 * reading the value as term names.
	 */
	private const FIELD = 'jcore_kirjasto_folder_id';

	/**
	 * The list mode bulk action.
	 */
	private const BULK_ACTION = 'jcore_kirjasto_move';

	/**
	 * Query arg reporting how many files a bulk move moved.
	 */
	private const MOVED_ARG = 'jcore_kirjasto_moved';

	/**
	 * Hooks the media library screens.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_action( 'pre_get_posts', array( self::class, 'filter_list_query' ) );
		add_filter( 'ajax_query_attachments_args', array( self::class, 'filter_grid_query' ) );

		add_filter( 'attachment_fields_to_edit', array( self::class, 'add_field' ), 10, 2 );
		add_filter( 'attachment_fields_to_save', array( self::class, 'save_field' ), 10, 2 );
		add_action( 'add_attachment', array( self::class, 'assign_upload' ) );

		add_filter( 'bulk_actions-upload', array( self::class, 'add_bulk_action' ) );
		add_filter( 'handle_bulk_actions-upload', array( self::class, 'handle_bulk_action' ), 10, 3 );
		add_filter( 'removable_query_args', array( self::class, 'removable_query_args' ) );
		add_action( 'admin_notices', array( self::class, 'bulk_notice' ) );

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_screen_assets' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_modal_assets' ) );
		add_action( 'admin_footer', array( self::class, 'add_settings' ) );
		add_action( 'wp_footer', array( self::class, 'add_settings' ) );
	}

	/**
	 * Applies the folder filter to the main query of the Media screen in list mode.
	 *
	 * @param \WP_Query $query The query.
	 *
	 * @return void
	 */
	public static function filter_list_query( $query ): void {
		global $pagenow;

		if ( ! $query->is_main_query() || 'upload.php' !== $pagenow ) {
			return;
		}

		$folder = self::list_folder();
		if ( null !== $folder ) {
			self::add_tax_query( $query, $folder );
		}
	}

	/**
	 * Applies the folder filter to the grid and the media modal. Core drops
	 * query keys it does not know before this filter runs, so the value is
	 * read from the request.
	 *
	 * @param array<string, mixed> $args Query arguments.
	 *
	 * @return array<string, mixed>
	 */
	public static function filter_grid_query( $args ): array {
		$args = (array) $args;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by core; validated by Folders::requested().
		$query  = isset( $_REQUEST['query'] ) && is_array( $_REQUEST['query'] ) ? wp_unslash( $_REQUEST['query'] ) : array();
		$folder = Folders::requested( $query[ Folders::KEY ] ?? null );

		if ( null !== $folder ) {
			$args['tax_query']   = isset( $args['tax_query'] ) && is_array( $args['tax_query'] ) ? $args['tax_query'] : array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
			$args['tax_query'][] = Folders::tax_query( $folder );
		}

		return $args;
	}

	/**
	 * Adds the "Folder" field to the attachment details.
	 *
	 * The field holds only the current folder; the folder list script fills
	 * in the others, so a page of the grid does not repeat the whole tree for
	 * every attachment.
	 *
	 * @param array<string, array<string, mixed>> $fields Attachment fields.
	 * @param \WP_Post                            $post   The attachment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_field( $fields, $post ): array {
		$fields = (array) $fields;

		if ( ! $post instanceof \WP_Post || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $fields;
		}

		$folder = Folders::of( $post->ID );
		$term   = $folder ? get_term( $folder, Folders::TAXONOMY ) : null;
		$name   = $term instanceof \WP_Term ? html_entity_decode( $term->name, ENT_QUOTES ) : __( 'No folder', 'jcore-kirjasto' );
		$id     = 'attachments-' . $post->ID . '-' . self::FIELD;

		$fields[ self::FIELD ] = array(
			'label'         => __( 'Folder', 'jcore-kirjasto' ),
			'input'         => 'html',
			'html'          => sprintf(
				'<select class="jcore-kirjasto-folder-field" id="%1$s" name="attachments[%2$d][%3$s]" data-folder="%4$d"><option value="%4$d" selected>%5$s</option></select>',
				esc_attr( $id ),
				$post->ID,
				esc_attr( self::FIELD ),
				$folder,
				esc_html( $name )
			),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/**
	 * Saves the "Folder" field, from the media modal and the edit screen.
	 *
	 * @param array<string, mixed> $post       Attachment post data.
	 * @param array<string, mixed> $attachment Submitted attachment fields.
	 *
	 * @return array<string, mixed>
	 */
	public static function save_field( $post, $attachment ): array {
		$post   = (array) $post;
		$folder = Folders::requested( ( (array) $attachment )[ self::FIELD ] ?? null );
		$id     = (int) ( $post['ID'] ?? 0 );

		if ( null !== $folder && $id && $folder !== Folders::of( $id ) ) {
			Folders::move( array( $id ), $folder );
		}

		return $post;
	}

	/**
	 * Puts a new upload into the folder that was open when it was uploaded.
	 *
	 * @param int $attachment_id The new attachment.
	 *
	 * @return void
	 */
	public static function assign_upload( $attachment_id ): void {
		// phpcs:ignore WordPress.Security.NonceVerification -- Core verified the upload nonce before creating the attachment.
		$folder = Folders::requested( isset( $_REQUEST[ Folders::KEY ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ Folders::KEY ] ) ) : null );

		if ( $folder && current_user_can( 'upload_files' ) && Folders::exists( $folder ) ) {
			Folders::move( array( (int) $attachment_id ), $folder );
		}
	}

	/**
	 * Adds "Move to folder" to the list mode bulk actions.
	 *
	 * @param array<string, string> $actions Bulk actions.
	 *
	 * @return array<string, string>
	 */
	public static function add_bulk_action( $actions ): array {
		$actions                      = (array) $actions;
		$actions[ self::BULK_ACTION ] = __( 'Move to folder', 'jcore-kirjasto' );

		return $actions;
	}

	/**
	 * Moves the checked attachments into the folder picked next to the bulk
	 * action. The folder list script adds that picker, one per bulk action
	 * dropdown, like core's `action` and `action2`.
	 *
	 * @param string $location Where to redirect afterwards.
	 * @param string $action   The bulk action.
	 * @param int[]  $post_ids Checked attachments.
	 *
	 * @return string
	 */
	public static function handle_bulk_action( $location, $action, $post_ids ): string {
		if ( self::BULK_ACTION !== $action ) {
			return (string) $location;
		}

		// Core checked the bulk-media nonce before applying this filter.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$from_top = self::BULK_ACTION === ( $_REQUEST['action'] ?? '' );
		$field    = self::BULK_ACTION . '_to' . ( $from_top ? '' : '2' );
		$folder   = Folders::requested( isset( $_REQUEST[ $field ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $field ] ) ) : null );
		// phpcs:enable

		$ids = array_filter(
			array_map( 'intval', (array) $post_ids ),
			static function ( int $id ): bool {
				return current_user_can( 'edit_post', $id );
			}
		);

		$moved = null !== $folder && $ids ? Folders::move( $ids, $folder ) : array();

		return add_query_arg( self::MOVED_ARG, is_array( $moved ) ? count( array_intersect( $ids, $moved ) ) : 0, (string) $location );
	}

	/**
	 * Drops the bulk move report from the URL once it has been shown.
	 *
	 * @param string[] $args Removable query args.
	 *
	 * @return string[]
	 */
	public static function removable_query_args( $args ): array {
		$args   = (array) $args;
		$args[] = self::MOVED_ARG;

		return $args;
	}

	/**
	 * Reports a finished bulk move.
	 *
	 * @return void
	 */
	public static function bulk_notice(): void {
		global $pagenow;

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only report.
		if ( 'upload.php' !== $pagenow || ! isset( $_GET[ self::MOVED_ARG ] ) ) {
			return;
		}

		$moved = absint( $_GET[ self::MOVED_ARG ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_admin_notice(
			sprintf(
				/* translators: %d: number of files moved. */
				_n( '%d file moved.', '%d files moved.', $moved, 'jcore-kirjasto' ),
				$moved
			),
			array(
				'type'        => $moved ? 'success' : 'warning',
				'dismissible' => true,
			)
		);
	}

	/**
	 * Loads the folder list on the Media screen in list mode, and the folder
	 * field on the attachment edit screen. Grid mode loads it through
	 * enqueue_modal_assets().
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_screen_assets( string $hook ): void {
		$is_attachment = 'post.php' === $hook && 'attachment' === get_current_screen()?->post_type;

		if ( ( 'upload.php' === $hook || $is_attachment ) && current_user_can( 'upload_files' ) ) {
			self::enqueue();
		}
	}

	/**
	 * Loads the folder list wherever the media modal can open.
	 *
	 * @return void
	 */
	public static function enqueue_modal_assets(): void {
		if ( current_user_can( 'upload_files' ) ) {
			self::enqueue( array( 'media-views' ) );
		}
	}

	/**
	 * Hands the folder tree and the screen to the script. Runs in the footer,
	 * after core has decided between grid and list mode.
	 *
	 * @return void
	 */
	public static function add_settings(): void {
		$handle = Assets::handle( self::ENTRY );
		if ( ! wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		$tree       = Folders::tree();
		$screen     = self::screen();
		$can_manage = current_user_can( Folders::capability() );
		$imports    = array();

		// Offered on the Media screen, until everything has been imported.
		if ( $can_manage && in_array( $screen, array( 'grid', 'list' ), true ) ) {
			$imports = array_values(
				array_filter(
					Importer::sources(),
					static function ( array $source ): bool {
						return $source['imported'] < $source['folders'];
					}
				)
			);
		}

		$settings = array(
			'tree'       => $tree,
			'canManage'  => $can_manage,
			'screen'     => $screen,
			'current'    => self::list_folder(),
			'bulkAction' => self::BULK_ACTION,
			'key'        => Folders::KEY,
			'field'      => self::FIELD,
			'imports'    => $imports,
		);

		wp_add_inline_script( $handle, 'window.jcoreKirjastoFolders = ' . wp_json_encode( $settings ) . ';', 'before' );
	}

	/**
	 * Enqueues the folder list script and style.
	 *
	 * @param string[] $deps Extra script dependencies.
	 *
	 * @return void
	 */
	private static function enqueue( array $deps = array() ): void {
		if ( wp_script_is( Assets::handle( self::ENTRY ), 'enqueued' ) ) {
			return;
		}

		Assets::enqueue_script( self::ENTRY, $deps );
		Assets::enqueue_style( self::ENTRY, array( 'wp-components' ) );
	}

	/**
	 * Where the script runs: `grid` and `list` on the Media screen, `edit` on
	 * the attachment edit screen and `modal` anywhere else.
	 *
	 * @return string
	 */
	private static function screen(): string {
		global $pagenow;

		if ( is_admin() && 'upload.php' === $pagenow && ! isset( $_GET['page'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return wp_script_is( 'media-grid', 'enqueued' ) ? 'grid' : 'list';
		}

		$is_attachment = is_admin() && 'post.php' === $pagenow && 'attachment' === get_current_screen()?->post_type;

		return $is_attachment ? 'edit' : 'modal';
	}

	/**
	 * The folder the Media screen is filtered by, from the URL.
	 *
	 * @return int|null
	 */
	private static function list_folder(): ?int {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Validated by Folders::requested().
		return Folders::requested( isset( $_GET[ Folders::KEY ] ) ? wp_unslash( $_GET[ Folders::KEY ] ) : null );
	}

	/**
	 * Adds a folder clause to a query's `tax_query`.
	 *
	 * @param \WP_Query $query  The query.
	 * @param int       $folder Folder ID, or 0 for attachments without a folder.
	 *
	 * @return void
	 */
	private static function add_tax_query( \WP_Query $query, int $folder ): void {
		$tax_query   = is_array( $query->get( 'tax_query' ) ) ? $query->get( 'tax_query' ) : array();
		$tax_query[] = Folders::tax_query( $folder );

		$query->set( 'tax_query', $tax_query );
	}
}
