<?php
/**
 * Impression beacon.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Delivery_Request;
use Aggressive\Ads\Workflow\Event_Recorder;
use Aggressive\Ads\Workflow\Fill_Service;
use Aggressive\Ads\Workflow\Fill_Token;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * POST /aggr/v1/i — after paint, never from cached HTML.
 */
final class Beacon_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Fill_Service     $fill     Module gate and live check.
	 * @param Fill_Token       $tokens   Token parser.
	 * @param Rate_Limiter     $limiter  Anonymous beacon bound.
	 * @param Event_Recorder   $recorder Durable event and projection write.
	 * @param Event_Repository $events   Lifecycle ordering checks.
	 */
	public function __construct(
		private readonly Fill_Service $fill,
		private readonly Fill_Token $tokens,
		private readonly Rate_Limiter $limiter,
		private readonly Event_Recorder $recorder,
		private readonly Event_Repository $events
	) {
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
			'/i',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'record' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'token' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static fn ( mixed $value ): bool => is_string( $value )
							&& strlen( $value ) <= Fill_Token::MAX_LENGTH
							&& 1 === preg_match( '/^[0-9a-f.]+$/', $value ),
					),

					/*
					 * Optional, and absent means `served`. A page cached with
					 * the previous script sends no event type and must keep
					 * reporting impressions rather than failing validation.
					 *
					 * Allowlisted to the two a browser may report. The rest of
					 * the lifecycle — request, fill, no_fill, conversion — is
					 * written by the server or by a later phase, and none of it
					 * is a client's to claim.
					 */
					'event' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn ( mixed $value ): bool => in_array(
							$value,
							array( Event_Repository::TYPE_SERVED, Event_Repository::TYPE_VIEWABLE ),
							true
						),
					),
				),
			)
		);
	}

	/**
	 * Public when native delivery is on. Prefetch is refused in the callback,
	 * not here, so the client sees 204 vs 400 rather than a mysterious 401.
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

		if ( Delivery_Request::is_cross_origin() || Delivery_Request::is_cross_site_fetch() ) {
			return new WP_Error(
				'aggr_fill_forbidden',
				__( 'That request is not allowed from this origin.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Consumes one impression token.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function record( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( Delivery_Request::is_prefetch() || Delivery_Request::is_obvious_bot() ) {
			return new WP_Error(
				'aggr_beacon_nonhuman',
				__( 'Automated fetches are not impressions.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		$limited = $this->limiter->attempt_for( Rate_Limiter::ACTION_BEACON, Rate_Limiter::client_subject() );

		if ( is_wp_error( $limited ) ) {
			return $limited;
		}

		$token  = (string) $request->get_param( 'token' );
		$parsed = $this->tokens->parse( $token );

		if ( null === $parsed || ! $this->fill->accepts( $parsed ) ) {
			return new WP_Error(
				'aggr_beacon_invalid',
				__( 'That token is not valid.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		$hash = $this->tokens->hash( $token );
		$ip   = $this->tokens->ip_hash( Delivery_Request::client_ip() );

		$event = (string) ( $request->get_param( 'event' ) ?? Event_Repository::TYPE_SERVED );

		/*
		 * A view has to follow a delivery, checked against this exact token.
		 *
		 * Viewability is client-attested — the browser is the only thing that
		 * knows what was on screen — so the server's job is not to verify the
		 * observation but to bound what a claim can be made about: a fill that
		 * really happened, once. Without this a client could report views for
		 * ads it never painted, and the number would measure nothing.
		 */
		if ( Event_Repository::TYPE_VIEWABLE === $event
			&& ! $this->events->exists( Event_Repository::TYPE_SERVED, $hash ) ) {
			return new WP_Error(
				'aggr_beacon_out_of_order',
				__( 'That token has not been delivered.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		$recorded = $this->recorder->record( $event, $parsed['placement_id'], $parsed['campaign_id'], $parsed['creative_id'], $hash, $ip );

		if ( Event_Recorder::REPLAY === $recorded ) {
			return new WP_Error(
				'aggr_beacon_replay',
				__( 'That token has already been used.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		if ( Event_Recorder::FAILED === $recorded ) {
			return new WP_Error(
				'aggr_beacon_unavailable',
				__( 'That impression could not be recorded. Please try again.', 'aggressive-ads' ),
				array( 'status' => 503 )
			);
		}

		return new WP_REST_Response( null, 204 );
	}
}
