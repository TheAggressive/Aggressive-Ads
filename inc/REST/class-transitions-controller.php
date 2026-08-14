<?php
/**
 * Moving a campaign through its lifecycle.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * The one route that changes a campaign's status.
 *
 * **This controller decides nothing.** It reads `to` from the body and hands
 * it to the state machine, which authorizes the specific edge, runs its
 * guards, performs the side effects and writes the audit row. A controller
 * that decided anything here would be a second implementation of the
 * lifecycle, and the two would disagree within a release.
 *
 * The target status is deliberately not restricted by an enum in the schema
 * beyond being one of ours: an advertiser POSTing `aggr_approved` is an
 * expected event that must be denied and *recorded* as denied, not rejected by
 * schema validation before anything sees it.
 */
final class Transitions_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Campaign_State_Machine $machine   The lifecycle.
	 * @param Campaign_Repository    $campaigns Campaign persistence.
	 * @param Rate_Limiter           $limiter   Abuse bounding.
	 */
	public function __construct(
		private readonly Campaign_State_Machine $machine,
		private readonly Campaign_Repository $campaigns,
		private readonly Rate_Limiter $limiter
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
	 * Registers the route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/campaigns/(?P<id>\d+)/transitions',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id'           => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'to'           => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
						'validate_callback' => static fn ( $value ): bool => is_string( $value ) && Post_Statuses::is_valid( $value ),
					),
					'review_notes' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_textarea_field',
					),
				),
			)
		);
	}

	/**
	 * Whether the caller may use the portal at all.
	 *
	 * Everything finer-grained is the state machine's business: it knows which
	 * capability this particular edge needs, and asks about this particular
	 * object.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
	}

	/**
	 * Applies the requested transition.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function handle( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'id' );
		$to          = (string) $request->get_param( 'to' );

		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_TRANSITION, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$context = array();
		$notes   = $request->get_param( 'review_notes' );

		if ( is_string( $notes ) && '' !== trim( $notes ) ) {
			$context['review_notes'] = $notes;
		}

		$applied = $this->machine->apply( $campaign_id, $to, $context );

		if ( is_wp_error( $applied ) ) {
			return $this->with_status( $applied );
		}

		return new WP_REST_Response(
			array(
				'id'     => $campaign_id,
				'status' => $this->campaigns->status( $campaign_id ),
			),
			200
		);
	}

	/**
	 * Gives a workflow error the HTTP status it deserves.
	 *
	 * The state machine speaks in domain terms and knows nothing about HTTP,
	 * which is the right way round; the mapping lives here, once.
	 *
	 * @param WP_Error $error The error to map.
	 * @return WP_Error
	 */
	private function with_status( WP_Error $error ): WP_Error {
		$status = match ( (string) $error->get_error_code() ) {
			'aggr_campaign_not_found' => 404,
			'aggr_forbidden'          => 403,

			// The caller asked for something this campaign cannot currently
			// do. 409 rather than 400: the request is well-formed, the
			// campaign's state is what makes it impossible.
			'aggr_illegal_transition',
			'aggr_system_transition',
			'aggr_not_a_system_transition',
			'aggr_campaign_claimed'   => 409,

			// Well-formed and permitted, but something about the campaign is
			// not ready.
			default                       => 422,
		};

		$error->add_data( array( 'status' => $status ), (string) $error->get_error_code() );

		return $error;
	}
}
