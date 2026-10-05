<?php
/**
 * Shared base for the plugin's REST controllers.
 *
 * @package Jcore\Kirjasto
 */

namespace Jcore\Kirjasto\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Holds the namespace and the capability check most routes share.
 */
abstract class Controller extends \WP_REST_Controller {

	/**
	 * Route namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'jcore-kirjasto/v1';

	/**
	 * Permission callback for the administrator-only routes.
	 *
	 * @return bool
	 */
	public function admin_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Builds a route definition, by default for administrators only.
	 *
	 * @param string               $method     One of the `WP_REST_Server` method constants.
	 * @param string               $callback   Method name on this controller.
	 * @param array<string, mixed> $args       Argument schema.
	 * @param string               $permission Method name of the permission callback.
	 *
	 * @return array<string, mixed>
	 */
	protected function route( string $method, string $callback, array $args = array(), string $permission = 'admin_permission' ): array {
		return array(
			'methods'             => $method,
			'callback'            => array( $this, $callback ),
			'permission_callback' => array( $this, $permission ),
			'args'                => $args,
		);
	}
}
