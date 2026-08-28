<?php
/**
 * Public batch page-decisions endpoint.
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
 * POST /aggr/v1/decisions — coordinated batch decisions across multiple slots on a page.
 */
final class Decisions_Controller implements Service {

	private const MAX_SLOTS = 20;

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
			'/decisions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'create' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'slots' => array(
						'type'              => 'array',
						'required'          => true,
						'items'             => array(
							'type' => 'string',
						),
						'validate_callback' => static function ( mixed $value ): bool {
							if ( ! is_array( $value ) || array() === $value || count( $value ) > self::MAX_SLOTS ) {
								return false;
							}
							foreach ( $value as $item ) {
								if ( ! is_string( $item ) || 1 !== preg_match( '/^[a-z0-9-]+$/', $item ) ) {
									return false;
								}
							}
							return true;
						},
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
	 * Resolves coordinated batch decisions for the requested page slots.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function create( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$slots = $request->get_param( 'slots' );

		if ( ! is_array( $slots ) ) {
			return new WP_Error(
				'aggr_invalid_slots',
				__( 'A valid array of slot slugs is required.', 'aggressive-ads' ),
				array( 'status' => 400 )
			);
		}

		$slot_strings = array_values( array_filter( $slots, 'is_string' ) );
		$payloads     = $this->fill->for_slots( $slot_strings );

		return new WP_REST_Response(
			array(
				'decisions' => $payloads,
			),
			200
		);
	}
}
