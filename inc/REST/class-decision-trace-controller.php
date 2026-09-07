<?php
/**
 * Staff-only decision trace.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Decision_Engine;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * GET /aggr/v1/placements/{id}/decision — replay one fill decision for staff.
 */
final class Decision_Trace_Controller implements Service {

	/**
	 * Builds the controller.
	 *
	 * @param Decision_Engine      $engine     Decision pipeline.
	 * @param Placement_Repository $placements Slot catalogue.
	 */
	public function __construct(
		private readonly Decision_Engine $engine,
		private readonly Placement_Repository $placements
	) {
	}

	/** Attaches the route. */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Registers the route. */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/placements/(?P<id>\d+)/decision',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'show' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'id'   => array(
						'type'              => 'integer',
						'required'          => true,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => is_numeric( $value ) && (int) $value > 0,
					),
					'at'   => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
						'validate_callback' => static fn ( mixed $value ): bool => ! is_numeric( $value ) || (int) $value >= 0,
					),
					'seed' => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	/**
	 * Staff reviewers only. Missing and forbidden are the same answer.
	 *
	 * @return true|WP_Error
	 */
	public function permission(): true|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error(
				'aggr_decision_forbidden',
				__( 'That decision is not available.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		return true;
	}

	/**
	 * Returns one decision and its trace for a placement and clock.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function show( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$placement_id = (int) $request->get_param( 'id' );

		if ( $placement_id <= 0 || ! $this->placements->exists( $placement_id ) ) {
			return new WP_Error(
				'aggr_decision_missing',
				__( 'That decision is not available.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		// A replayable clock is the point of this route, but an absurd one is a
		// typo rather than a request: every window comparison downstream takes
		// it at face value.
		$at   = $request->get_param( 'at' );
		$now  = is_numeric( $at ) && (int) $at > 0 ? (int) $at : time();
		$seed = $request->get_param( 'seed' );
		$seed = is_numeric( $seed ) ? (int) $seed : random_int( 0, PHP_INT_MAX );

		$decision = $this->engine->decide( $placement_id, $now, $seed, null, false );
		$result   = $decision['result'];
		$trace    = $decision['trace'];

		$response = new WP_REST_Response(
			array(
				'placement_id'  => $placement_id,
				'evaluated_at'  => $now,
				'seed'          => $seed,
				'path'          => $this->engine->serving_status(),
				'result'        => array(
					'winner' => $result->winner,
					'reason' => $result->reason,
				),
				'trace'         => array(
					'entries' => $trace->entries,
				),
				'reason_labels' => self::reason_labels(),
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );

		return $response;
	}

	/**
	 * Human labels for every exclusion code, in stage order.
	 *
	 * **This map had six entries and the vocabulary had twenty-seven.** Every
	 * schedule, targeting, frequency, pacing, priority and page-coordination
	 * exclusion reached this screen as a raw code — on the one screen whose
	 * entire job is explaining why an advertisement did not serve. A staff
	 * member debugging a campaign that would not run was shown
	 * `pacing_daily_cap_reached` and left to guess.
	 *
	 * `DecisionTraceRoutesTest` now asserts the map and
	 * `Domain\Exclusion_Reason::all()` cover each other exactly, so a reason
	 * added without a label fails rather than shipping as a code, and a label
	 * for something that is not a reason fails too — that second direction is
	 * what `Exclusion_Reason::is_reason()` is for, and it had no caller until
	 * now.
	 *
	 * Ordered as the pipeline runs, so a reader scanning the list meets the
	 * stages in the order a candidate meets them.
	 *
	 * @return array<string, string>
	 */
	private static function reason_labels(): array {
		return array(
			Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL => __( 'Invalid click URL', 'aggressive-ads' ),
			Exclusion_Reason::ELIGIBILITY_MISSING_ATTACHMENT => __( 'Missing attachment', 'aggressive-ads' ),
			Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT  => __( 'Invalid weight', 'aggressive-ads' ),
			Exclusion_Reason::ELIGIBILITY_SIZE_MISMATCH   => __( 'Wrong size for this placement', 'aggressive-ads' ),
			Exclusion_Reason::ELIGIBILITY_STAGE_ERROR     => __( 'Eligibility check failed', 'aggressive-ads' ),
			Exclusion_Reason::SCHEDULE_NOT_STARTED        => __( 'Scheduled to start later', 'aggressive-ads' ),
			Exclusion_Reason::SCHEDULE_EXPIRED            => __( 'Schedule has ended', 'aggressive-ads' ),
			Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED   => __( 'Outside its booked hours', 'aggressive-ads' ),
			Exclusion_Reason::SCHEDULE_INVALID_TIMEZONE   => __( 'Unreadable schedule timezone', 'aggressive-ads' ),
			Exclusion_Reason::SCHEDULE_STAGE_ERROR        => __( 'Schedule check failed', 'aggressive-ads' ),
			Exclusion_Reason::TARGETING_EXCLUDED          => __( 'Targeting did not match this page', 'aggressive-ads' ),
			Exclusion_Reason::TARGETING_STAGE_ERROR       => __( 'Targeting check failed', 'aggressive-ads' ),
			Exclusion_Reason::FREQUENCY_CAPPED            => __( 'This visitor has seen it enough', 'aggressive-ads' ),
			Exclusion_Reason::FREQUENCY_STAGE_ERROR       => __( 'Frequency check failed', 'aggressive-ads' ),
			Exclusion_Reason::PACING_DAILY_CAP_REACHED    => __( 'Daily cap reached', 'aggressive-ads' ),
			Exclusion_Reason::PACING_LIFETIME_CAP_REACHED => __( 'Lifetime cap reached', 'aggressive-ads' ),
			Exclusion_Reason::PACING_BEHIND_PACE          => __( 'Held back to spread delivery', 'aggressive-ads' ),
			Exclusion_Reason::PACING_THROTTLED            => __( 'Throttled to stay on pace', 'aggressive-ads' ),
			Exclusion_Reason::PACING_STAGE_ERROR          => __( 'Pacing check failed', 'aggressive-ads' ),
			Exclusion_Reason::PACING_UNAVAILABLE          => __( 'Delivery counts unavailable', 'aggressive-ads' ),
			Exclusion_Reason::PRIORITY_LOWER              => __( 'Outranked by a higher priority', 'aggressive-ads' ),
			Exclusion_Reason::PRIORITY_STAGE_ERROR        => __( 'Priority check failed', 'aggressive-ads' ),
			Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION => __( 'A competitor is already on this page', 'aggressive-ads' ),
			Exclusion_Reason::PAGE_ROADBLOCK_INCOMPLETE   => __( 'Roadblock could not fill every slot', 'aggressive-ads' ),
			Exclusion_Reason::PAGE_DUPLICATE_ASSET        => __( 'Already shown on this page', 'aggressive-ads' ),
			Exclusion_Reason::COMPETITION_LOST            => __( 'Lost competition', 'aggressive-ads' ),
			Exclusion_Reason::NO_FILL                     => __( 'No eligible assignment', 'aggressive-ads' ),
		);
	}
}
