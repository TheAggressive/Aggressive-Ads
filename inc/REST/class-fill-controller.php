<?php
/**
 * Public fill endpoint.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Workflow\Delivery_Request;
use Aggressive\Ads\Workflow\Fill_Service;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /aggr/v1/fill/{slot} — uncached, module-gated.
 */
final class Fill_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Fill_Service $fill Native fill.
	 */
	public function __construct( private readonly Fill_Service $fill ) {
	}

	/**
	 * Attaches the route.
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers the route.
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/fill/(?P<slot>[a-z0-9-]+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'slot' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_title',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value ) && 1 === preg_match( '/^[a-z0-9-]+$/', $value ),
					),
				),
			)
		);
	}

	/**
	 * Public when the native module is on. Off is a 404 so a stale rewrite
	 * cannot look like an auth prompt.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): true|WP_Error {
		if ( ! $this->fill->is_enabled() ) {
			return new WP_Error(
				'aggr_fill_disabled',
				__( 'Native delivery is off.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		if ( Delivery_Request::is_cross_origin() ) {
			return new WP_Error(
				'aggr_fill_forbidden',
				__( 'That request is not allowed from this origin.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * One slot's chosen creative or house. Candidates stay server-side.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slot    = (string) $request->get_param( 'slot' );
		$payload = $this->fill->for_slug( $slot );

		if ( null === $payload ) {
			return new WP_Error(
				'aggr_fill_missing',
				__( 'No such placement.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		$response = new WP_REST_Response( $payload, 200 );
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}
}
