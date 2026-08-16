<?php
/**
 * Autosaving the settings document.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Core\Settings_Input;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reviewer_Access;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The write half of the Settings screen, for the path that cannot navigate.
 *
 * The screen saves as you change it, and a form POST reloads the page — so the
 * autosave needs an endpoint. This is that endpoint and nothing more: it shapes
 * the body with `Settings_Input` and hands it to `Settings::save()`, which is
 * the same pair of calls `Settings_Screen::handle_save()` makes. There is no
 * second validator here, and there must never be one; the WCAG contrast gate
 * lives in `Settings_Schema::validate()` and both paths reach it through
 * `Settings::save()`.
 *
 * The document is replaced whole on every call rather than patched field by
 * field. A partial update would need a merge, a merge needs to know which
 * absent key means "unchanged" and which means "off", and getting that wrong on
 * a screen of kill-switches turns a debounced keystroke into a disabled module.
 */
final class Settings_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Settings        $settings Settings document.
	 * @param Reviewer_Access $access   Per-user review access.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Reviewer_Access $access
	) {
	}

	/**
	 * Attaches the route.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the save route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/settings',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/settings/reviewers',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'grant' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'user' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/settings/reviewers/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may administer advertising settings.
	 *
	 * The same capability that gates the screen, checked again here. A screen
	 * gate is a rendering decision; this is the one that stops a request.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::MANAGE_SETTINGS );
	}

	/**
	 * Validates and stores the whole document.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$body = $request->get_json_params();

		if ( ! is_array( $body ) ) {
			return new WP_Error(
				'aggr_settings_body',
				__( 'The settings could not be read.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		$result = $this->settings->save( Settings_Input::shape( $body ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'saved' => true ), 200 );
	}

	/**
	 * Adds one user to the review roster.
	 *
	 * Kept off the settings document deliberately. The roster is not a setting:
	 * folding it into the autosaved payload would mean a screen left open in
	 * another tab could reinstate an access list somebody had already changed,
	 * and it would put granting staff access one debounce behind a keystroke.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function grant( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$user_id = $this->access->find( (string) $request->get_param( 'user' ) );

		if ( 0 === $user_id ) {
			return new WP_Error(
				'aggr_user_not_found',
				__( 'No user was found with that name or email address.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		$result = $this->access->grant( $user_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'roster' => $this->access->roster() ), 200 );
	}

	/**
	 * Removes one user from the review roster.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$result = $this->access->revoke( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( array( 'roster' => $this->access->roster() ), 200 );
	}
}
