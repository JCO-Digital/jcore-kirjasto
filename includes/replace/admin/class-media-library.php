<?php
/**
 * Replacing files from the media library.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Replace\Admin;

use Jcore\Kirjasto\Assets;
use Jcore\Kirjasto\Replace\Replacer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds "Replace file" to the attachment details and to the list mode row
 * actions. The `replace` script uploads the new file when one is picked.
 */
final class Media_Library {

	/**
	 * Build entry of the script.
	 */
	private const ENTRY = 'replace';

	/**
	 * Name of the attachment field.
	 */
	private const FIELD = 'jcore_kirjasto_replace';

	/**
	 * Key of the replacement time in the media library's attachment data.
	 */
	private const VERSION = 'jcoreKirjastoVersion';

	/**
	 * Query arg carrying the replacement time.
	 */
	private const VERSION_ARG = 'jcore-kirjasto-v';

	/**
	 * Hooks the media library screens.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'add_field' ), 10, 2 );
		add_filter( 'media_row_actions', array( self::class, 'add_row_action' ), 10, 2 );

		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_screen_assets' ) );
		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_modal_assets' ) );

		add_filter( 'wp_prepare_attachment_for_js', array( self::class, 'add_version' ), 10, 2 );
		add_filter( 'wp_get_attachment_image_src', array( self::class, 'version_image_src' ), 10, 2 );
		add_filter( 'wp_calculate_image_srcset', array( self::class, 'version_srcset' ), 10, 5 );
	}

	/**
	 * Hands the media library the time the file was replaced, for the script
	 * to add to the addresses of the images it shows.
	 *
	 * The addresses themselves stay as they are: they are what gets inserted
	 * into content and copied with "Copy URL".
	 *
	 * @param array<string, mixed> $response   Attachment data for the media library.
	 * @param \WP_Post             $attachment The attachment.
	 *
	 * @return array<string, mixed>
	 */
	public static function add_version( $response, $attachment ): array {
		$response = (array) $response;

		if ( $attachment instanceof \WP_Post ) {
			$response[ self::VERSION ] = Replacer::version( $attachment->ID );
		}

		return $response;
	}

	/**
	 * Adds the replacement time to the images admin screens show, such as the
	 * list mode thumbnails and the preview on the attachment edit screen.
	 *
	 * The browser may keep the old file under its unchanged address for a long
	 * time, and shows it even after an ordinary reload.
	 *
	 * @param array|false $image         Image URL, width and height.
	 * @param int         $attachment_id The attachment.
	 *
	 * @return array|false
	 */
	public static function version_image_src( $image, $attachment_id ) {
		if ( is_array( $image ) && isset( $image[0] ) && self::versions_urls() ) {
			$image[0] = self::versioned( (string) $image[0], (int) $attachment_id );
		}

		return $image;
	}

	/**
	 * Adds the replacement time to the `srcset` of those images.
	 *
	 * @param array<int, array<string, mixed>> $sources       Sources, by width.
	 * @param int[]                            $size_array    Width and height of the image.
	 * @param string                           $image_src     The `src` of the image.
	 * @param array<string, mixed>             $image_meta    Attachment metadata.
	 * @param int                              $attachment_id The attachment.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function version_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ): array {
		$sources = (array) $sources;

		if ( self::versions_urls() ) {
			foreach ( $sources as $width => $source ) {
				if ( is_array( $source ) && isset( $source['url'] ) ) {
					$sources[ $width ]['url'] = self::versioned( (string) $source['url'], (int) $attachment_id );
				}
			}
		}

		return $sources;
	}

	/**
	 * Adds the "Replace file" field to the attachment details, in the media
	 * modal and on the attachment edit screen.
	 *
	 * @param array<string, array<string, mixed>> $fields Attachment fields.
	 * @param \WP_Post                            $post   The attachment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_field( $fields, $post ): array {
		$fields = (array) $fields;

		if ( ! $post instanceof \WP_Post || ! self::can_replace( $post->ID ) ) {
			return $fields;
		}

		$mime_type = (string) Replacer::mime_type( $post->ID );

		$fields[ self::FIELD ] = array(
			'label'         => __( 'Replace file', 'jcore-kirjasto' ),
			'input'         => 'html',
			'html'          => self::button( $post->ID, 'button' ) . sprintf(
				'<p class="description">%s</p><p class="jcore-kirjasto-replace-status" role="status"></p>',
				esc_html(
					sprintf(
						/* translators: %s: file type, e.g. JPG. */
						__( 'Upload a new %s file in place of this one. It keeps the address of this file, so it changes everywhere this file is used.', 'jcore-kirjasto' ),
						Replacer::type_label( $mime_type )
					)
				)
			),
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/**
	 * Adds "Replace file" to the row actions of the list mode.
	 *
	 * @param array<string, string> $actions Row actions.
	 * @param \WP_Post              $post    The attachment.
	 *
	 * @return array<string, string>
	 */
	public static function add_row_action( $actions, $post ): array {
		$actions = (array) $actions;

		if ( $post instanceof \WP_Post && self::can_replace( $post->ID ) ) {
			$actions[ self::FIELD ] = self::button( $post->ID, 'button-link' );
		}

		return $actions;
	}

	/**
	 * Loads the script on the Media screen in list mode and the attachment
	 * edit screen. Grid mode loads it through enqueue_modal_assets().
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_screen_assets( string $hook ): void {
		$is_attachment = 'post.php' === $hook && 'attachment' === get_current_screen()?->post_type;

		if ( 'upload.php' === $hook || $is_attachment ) {
			self::enqueue();
		}
	}

	/**
	 * Loads the script wherever the media modal can open, after the media
	 * views it extends.
	 *
	 * @return void
	 */
	public static function enqueue_modal_assets(): void {
		self::enqueue( array( 'media-views' ) );
	}

	/**
	 * Enqueues the script and style.
	 *
	 * @param string[] $deps Extra script dependencies.
	 *
	 * @return void
	 */
	private static function enqueue( array $deps = array() ): void {
		if ( current_user_can( 'upload_files' ) && ! wp_script_is( Assets::handle( self::ENTRY ), 'enqueued' ) ) {
			Assets::enqueue_script( self::ENTRY, $deps );
			Assets::enqueue_style( self::ENTRY );
		}
	}

	/**
	 * The button that opens the file picker. The script finds it by its class.
	 *
	 * @param int    $attachment_id The attachment.
	 * @param string $class_name    `button` or `button-link`.
	 *
	 * @return string
	 */
	private static function button( int $attachment_id, string $class_name ): string {
		$mime_type = (string) Replacer::mime_type( $attachment_id );
		$accept    = array( $mime_type );

		foreach ( wp_get_mime_types() as $extensions => $type ) {
			if ( $type === $mime_type ) {
				foreach ( explode( '|', $extensions ) as $extension ) {
					$accept[] = '.' . $extension;
				}
			}
		}

		return sprintf(
			'<button type="button" class="%1$s jcore-kirjasto-replace" data-id="%2$d" data-accept="%3$s">%4$s</button>',
			esc_attr( $class_name ),
			$attachment_id,
			esc_attr( implode( ',', $accept ) ),
			esc_html__( 'Replace file', 'jcore-kirjasto' )
		);
	}

	/**
	 * Whether image addresses get the replacement time: only on admin screens
	 * being viewed, so none is saved into content or sent to an editor.
	 *
	 * @return bool
	 */
	private static function versions_urls(): bool {
		return is_admin() && ! wp_doing_ajax() && 'GET' === ( $_SERVER['REQUEST_METHOD'] ?? '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
	}

	/**
	 * Adds the replacement time of an attachment to the address of one of its
	 * files, if its file was ever replaced.
	 *
	 * @param string $url           The address.
	 * @param int    $attachment_id The attachment.
	 *
	 * @return string
	 */
	private static function versioned( string $url, int $attachment_id ): string {
		$version = Replacer::version( $attachment_id );

		return $version ? add_query_arg( self::VERSION_ARG, $version, $url ) : $url;
	}

	/**
	 * Whether the current user may replace the file of an attachment.
	 *
	 * @param int $attachment_id The attachment.
	 *
	 * @return bool
	 */
	private static function can_replace( int $attachment_id ): bool {
		return current_user_can( 'upload_files' ) && current_user_can( 'edit_post', $attachment_id ) && null !== Replacer::mime_type( $attachment_id );
	}
}
