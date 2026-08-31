<?php
/**
 * Staff routes for server-to-server credentials.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Conversion_Credential;
use Aggressive\Ads\Repository\Conversion_Credential_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Conversion_Credential_Manager;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Issuing, listing and revoking credentials. Staff only, on every verb.
 *
 * There is no update verb and no read-one verb, both deliberately. A credential
 * has nothing editable — changing its scope would silently repoint an
 * integration's reporting at another advertiser — and there is nothing to read
 * back, because the secret exists only in the response that created it.
 */
final class Conversion_Credentials_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Conversion_Credential_Repository $credentials Credential persistence.
	 * @param Conversion_Credential_Manager    $manager     Validation, capability and audit.
	 */
	public function __construct(
		private readonly Conversion_Credential_Repository $credentials,
		private readonly Conversion_Credential_Manager $manager
	) {
	}

	/**
	 * Attaches the routes.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the routes.
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/conversion-credentials',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(),
			)
		);

		Creative_File_Controller::register_route(
			'/conversion-credentials',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'org_id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'label'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& Conversion_Credential::is_valid_label( $value ),
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/conversion-credentials/(?P<id>\d+)',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'revoke' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id' => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
				),
			)
		);
	}

	/**
	 * Staff who may manage settings.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): true|WP_Error {
		if ( ! current_user_can( Capabilities::MANAGE_SETTINGS ) ) {
			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage conversion credentials.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Every credential, without any secret.
	 *
	 * @return WP_REST_Response
	 */
	public function index(): WP_REST_Response {
		return new WP_REST_Response( array( 'credentials' => $this->credentials->all() ), 200 );
	}

	/**
	 * Issues one credential and returns its plaintext, once.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$issued = $this->manager->issue(
			(int) $request->get_param( 'org_id' ),
			(string) $request->get_param( 'label' )
		);

		if ( is_wp_error( $issued ) ) {
			return $issued;
		}

		/*
		 * `no-store`, and it is not decoration. This is the only response in
		 * the plugin whose body is a live secret, and a shared cache or a
		 * browser back-forward cache holding it would put the credential
		 * somewhere neither revocation nor a salt rotation reaches.
		 */
		$response = new WP_REST_Response(
			array(
				'id'    => $issued['id'],
				'token' => $issued['token'],
			),
			201
		);

		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Revokes one credential.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function revoke( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$revoked = $this->manager->revoke( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $revoked ) ) {
			return $revoked;
		}

		return new WP_REST_Response( array( 'ok' => true ), 200 );
	}
}
