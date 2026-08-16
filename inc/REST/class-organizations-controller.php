<?php
/**
 * Suspending and reactivating organizations.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Admin\Organization_Data;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Organization_Membership;
use Aggressive\Ads\Workflow\Organization_State_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The write half of the Organizations screen.
 *
 * One route, because there is one decision: an organization is active or it is
 * suspended. Modelling that as a state transition rather than as two verbs
 * means the screen cannot invent a third, and the allowlist below is the only
 * place the vocabulary lives.
 *
 * Suspension is the highest-consequence action on this screen — it stops every
 * campaign an organization is running — so the capability is checked here and
 * again inside Organization_State_Manager, which owns the audit trail.
 */
final class Organizations_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Organization_State_Manager $manager Suspension workflow.
	 * @param Organization_Data          $data       List view for the staff screen.
	 * @param Organization_Membership    $membership Roster workflow.
	 */
	public function __construct(
		private readonly Organization_State_Manager $manager,
		private readonly Organization_Data $data,
		private readonly Organization_Membership $membership
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
	 * Registers the state route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/organizations/(?P<id>\d+)/state',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'set_state' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'state' => array(
						'type'              => 'string',
						'required'          => true,
						'enum'              => array(
							Org_Repository::STATE_ACTIVE,
							Org_Repository::STATE_SUSPENDED,
						),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);

		$org = array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
			),
		);

		Creative_File_Controller::register_route(
			'/organizations/(?P<id>\\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'rename' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $org + array(
					'name' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/organizations/(?P<id>\\d+)/owner',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'transfer' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $org + array(
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/organizations/(?P<id>\\d+)/members',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'invite' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $org + array(
					'email' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_email',
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/organizations/(?P<id>\\d+)/members/(?P<user_id>\\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'remove_member' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => $org + array(
					'user_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may administer organizations.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::MANAGE_ORGS );
	}

	/**
	 * Moves one organization between active and suspended.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function set_state( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$org_id = (int) $request->get_param( 'id' );
		$state  = (string) $request->get_param( 'state' );

		$result = $this->manager->set_state( $org_id, $state );

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array(
					'status' => match ( $result->get_error_code() ) {
						'aggr_forbidden'              => 403,
						'aggr_org_not_found' => 404,
						default                       => 400,
					},
				)
			);
		}

		return $this->refreshed();
	}

	/**
	 * Renames one organization.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function rename( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->settle(
			$this->membership->rename(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'name' ),
				get_current_user_id()
			)
		);
	}

	/**
	 * Moves ownership to another member.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function transfer( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->settle(
			$this->membership->transfer(
				(int) $request->get_param( 'id' ),
				(int) $request->get_param( 'user_id' ),
				get_current_user_id()
			)
		);
	}

	/**
	 * Invites one person into the organization.
	 *
	 * Staff invite rather than add. A membership that the person never agreed to
	 * would put another organization's unpublished work in front of them without
	 * their knowledge, and the invitation is also what creates the account when
	 * there is not one yet.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function invite( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->settle(
			$this->membership->invite(
				(int) $request->get_param( 'id' ),
				(string) $request->get_param( 'email' ),
				get_current_user_id()
			)
		);
	}

	/**
	 * Removes one member.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function remove_member( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		return $this->settle(
			$this->membership->remove(
				(int) $request->get_param( 'id' ),
				(int) $request->get_param( 'user_id' ),
				get_current_user_id()
			)
		);
	}

	/**
	 * Turns a workflow result into a response, or an error with a status.
	 *
	 * @param true|WP_Error $result Workflow outcome.
	 * @return WP_REST_Response|WP_Error
	 */
	private function settle( bool|WP_Error $result ): WP_REST_Response|WP_Error {
		return is_wp_error( $result ) ? self::as_response_error( $result ) : $this->refreshed();
	}

	/**
	 * The roster as the server now holds it.
	 *
	 * @return WP_REST_Response
	 */
	private function refreshed(): WP_REST_Response {
		return new WP_REST_Response( array( 'view' => $this->data->view() ), 200 );
	}

	/**
	 * Gives a workflow error an HTTP status without rewording it.
	 *
	 * The workflow's messages are written for the person reading the screen, so
	 * they travel unchanged. Only the status is decided here.
	 *
	 * @param WP_Error $error Workflow error.
	 * @return WP_Error
	 */
	private static function as_response_error( WP_Error $error ): WP_Error {
		$code = $error->get_error_code();

		$status = match ( $code ) {
			'aggr_forbidden', 'aggr_org_access_denied' => 403,
			'aggr_org_not_found' => 404,
			default => 400,
		};

		$message = $error->get_error_message();

		return new WP_Error(
			$code,
			'' !== $message ? $message : __( 'That change could not be made.', 'aggressive-ads' ),
			array( 'status' => $status )
		);
	}
}
