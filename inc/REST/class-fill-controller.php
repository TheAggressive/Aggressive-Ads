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

					/*
					 * Which fill this is within the page view, zero-based.
					 *
					 * **It partitions a supply metric and gates nothing.** The
					 * endpoint is stateless and sits behind a page cache, so the
					 * server cannot know whether a fill is a page's first — the
					 * client does, and says. That makes it untrusted, and the
					 * containment is what it is wired to: it does not decide
					 * whether an impression counts, which stays on the beacon's
					 * token path, and it credits no campaign and moves no money.
					 *
					 * Optional, because every fill served from a page cached
					 * before this shipped arrives without it. Absent reads as a
					 * page opportunity; see `Domain\Opportunity`.
					 */
					'n'    => array(
						'type'              => 'integer',
						'required'          => false,
						'default'           => 0,
						'sanitize_callback' => 'absint',
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
		$slot     = (string) $request->get_param( 'slot' );
		$sequence = (int) $request->get_param( 'n' );
		$payload  = $this->fill->for_slug( $slot, $sequence );

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
