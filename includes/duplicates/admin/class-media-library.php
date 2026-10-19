<?php
/**
 * Duplicates inside the media library and its uploaders.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Duplicates\Admin;

use Jcore\Kirjasto\Assets;
use Jcore\Kirjasto\Duplicates\Finder;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds the "Duplicates" field to the attachment details, and loads the
 * upload check wherever files are uploaded: the media modal, the Media
 * screen, Media > Add New and the block editor.
 */
final class Media_Library {

	/**
	 * Build entry of the upload check.
	 */
	private const ENTRY = 'duplicates-upload';

	/**
	 * Name of the attachment field.
	 */
	private const FIELD = 'jcore_kirjasto_duplicates';

	/**
	 * Hooks the media library and the uploaders.
	 *
	 * @return void
	 */
	public static function register(): void {
		add_filter( 'attachment_fields_to_edit', array( self::class, 'add_field' ), 10, 2 );

		add_action( 'wp_enqueue_media', array( self::class, 'enqueue_modal_assets' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_screen_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( self::class, 'enqueue_editor_assets' ) );
	}

	/**
	 * Lists the duplicates of an attachment in its details, in the media
	 * modal and on the attachment edit screen. Nothing is shown for one
	 * without duplicates.
	 *
	 * @param array<string, array<string, mixed>> $fields Attachment fields.
	 * @param \WP_Post                            $post   The attachment.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function add_field( $fields, $post ): array {
		$fields = (array) $fields;
		$group  = $post instanceof \WP_Post ? Finder::group_of( $post->ID ) : null;

		if ( ! $group ) {
			return $fields;
		}

		$links = array();
		foreach ( $group['entries'] as $entry ) {
			if ( in_array( $post->ID, $entry['ids'], true ) ) {
				continue;
			}

			$title   = html_entity_decode( get_the_title( $entry['id'] ), ENT_QUOTES );
			$title   = '' !== trim( $title ) ? $title : wp_basename( $entry['file'] );
			$url     = get_edit_post_link( $entry['id'] ) ?? wp_get_attachment_url( $entry['id'] );
			$links[] = sprintf( '<a href="%s">%s</a>', esc_url( (string) $url ), esc_html( $title ) );
		}

		$text = 'identical' === $group['kind']
			? _n( 'The same file is also in the library as:', 'The same file is also in the library as:', count( $links ), 'jcore-kirjasto' )
			: _n( 'An image that looks the same is also in the library:', 'Images that look the same are also in the library:', count( $links ), 'jcore-kirjasto' );

		$html = '<p class="jcore-kirjasto-duplicates__text">' . esc_html( $text ) . '</p><ul class="jcore-kirjasto-duplicates__list"><li>' . implode( '</li><li>', $links ) . '</li></ul>';

		if ( current_user_can( Page::CAPABILITY ) ) {
			$html .= sprintf( '<a class="jcore-kirjasto-duplicates__review" href="%s">%s</a>', esc_url( Page::url() ), esc_html__( 'Review duplicates', 'jcore-kirjasto' ) );
		}

		$fields[ self::FIELD ] = array(
			'label'         => __( 'Duplicates', 'jcore-kirjasto' ),
			'input'         => 'html',
			'html'          => $html,
			'show_in_edit'  => true,
			'show_in_modal' => true,
		);

		return $fields;
	}

	/**
	 * Loads the upload check wherever the media modal can open, after the
	 * uploader it hooks into.
	 *
	 * @return void
	 */
	public static function enqueue_modal_assets(): void {
		self::enqueue( array( 'media-views' ) );
	}

	/**
	 * Loads the upload check on Media > Add New, and the style of the
	 * attachment field on the Media screen and the attachment edit screen.
	 *
	 * @param string $hook The current admin page hook.
	 *
	 * @return void
	 */
	public static function enqueue_screen_assets( string $hook ): void {
		if ( 'media-new.php' === $hook ) {
			self::enqueue( array( 'plupload-handlers' ) );
		}
	}

	/**
	 * Loads the upload check in the block editor, which uploads through the
	 * REST API rather than the media modal's uploader.
	 *
	 * @return void
	 */
	public static function enqueue_editor_assets(): void {
		self::enqueue();
	}

	/**
	 * Enqueues the upload check and its settings.
	 *
	 * @param string[] $deps Extra script dependencies.
	 *
	 * @return void
	 */
	private static function enqueue( array $deps = array() ): void {
		$handle = Assets::handle( self::ENTRY );

		if ( ! current_user_can( 'upload_files' ) || wp_script_is( $handle, 'enqueued' ) ) {
			return;
		}

		if ( ! Assets::enqueue_script( self::ENTRY, $deps ) ) {
			return;
		}

		Assets::enqueue_style( self::ENTRY, array( 'wp-components' ) );

		$settings = array(
			'reviewUrl' => current_user_can( Page::CAPABILITY ) ? Page::url() : '',
		);

		wp_add_inline_script( $handle, 'window.jcoreKirjastoDuplicates = ' . wp_json_encode( $settings ) . ';', 'before' );
	}
}
