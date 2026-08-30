<?php
/**
 * The staff review surface, over REST.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Admin\Campaign_Change_Actions;
use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Review_Actions;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/**
 * Reading the queue, and every staff decision that is not a status change.
 *
 * Two things are deliberately absent because a route for them already exists,
 * and a second path to one workflow is two paths to keep in agreement:
 * status changes belong to `Transitions_Controller`, whose state machine
 * authorizes each edge against the object, and staff decisions on an ad
 * replacement belong to `POST /creative-replacements/{id}/decision` on
 * `Creative_Controller`, behind the same `aggr_review_campaigns` gate.
 *
 * **The read routes carry their own capability check, and that is the point of
 * this class existing rather than the screen simply calling `Review_Data`.**
 * `Review_Data::campaign()` has no gate of its own — it returns internal notes,
 * private creative previews and the audit timeline — and was safe only while
 * `Review_Screen::render()` was its sole caller. Reaching it over HTTP without
 * repeating that check here is exactly how a read model with no gate becomes an
 * unauthenticated disclosure.
 *
 * Every write is thin over a workflow that repeats the capability check for its
 * own audit trail. The two `process()` methods reused here are the same edges
 * the admin-post handlers call, so there is one decision implementation rather
 * than two that drift.
 *
 * Note the asymmetry, because it decides how carefully this file may be edited.
 * The **writes have two gates**: weaken `permission()` and the workflows still
 * refuse. The **reads have exactly one** — `Review_Data` holds no capability
 * check and is not going to grow one, since a read model that authorizes is a
 * read model nobody can reuse. So `permission()` is the whole of the protection
 * on `queue()` and `campaign()`, and `ReviewRoutesTest` exists to fail loudly
 * if it is ever loosened.
 */
final class Review_Controller implements Service {

	/**
	 * Constructor.
	 *
	 * @param Review_Data                                $data      Queue and campaign read model.
	 * @param Review_Actions                             $actions   Internal-notes writes.
	 * @param Campaign_Change_Actions                    $changes   Advertiser change decisions.
	 * @param \Aggressive\Ads\Workflow\Creative_Approval $creatives Creative publication.
	 */
	public function __construct(
		private readonly Review_Data $data,
		private readonly Review_Actions $actions,
		private readonly Campaign_Change_Actions $changes,
		private readonly \Aggressive\Ads\Workflow\Creative_Approval $creatives
	) {
	}

	/**
	 * Attaches the routes.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Registers every review route.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		Creative_File_Controller::register_route(
			'/review/queue',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'queue' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array(
					'filter' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_key',
					),
					'paged'  => array(
						'type'              => 'integer',
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/review/campaigns/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'campaign' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => self::id_arg(),
			)
		);

		Creative_File_Controller::register_route(
			'/review/campaigns/(?P<id>\d+)/notes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'save_notes' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => self::id_arg(),
			)
		);

		Creative_File_Controller::register_route(
			'/review/campaigns/(?P<id>\d+)/changes',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'decide_changes' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => self::id_arg(),
			)
		);

		/*
		 * Keyed on the creative rather than the campaign, because a reviewer is
		 * deciding about one piece of artwork. Which campaign it belongs to,
		 * and whether that campaign is running, are re-derived server-side —
		 * the client knows an id and is trusted with nothing else.
		 */
		Creative_File_Controller::register_route(
			'/review/creatives/(?P<id>\d+)/publish',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'publish_creative' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => self::id_arg(),
			)
		);

		Creative_File_Controller::register_route(
			'/review/campaigns/(?P<id>\d+)/request',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'resolve_request' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => self::id_arg(),
			)
		);
	}

	/**
	 * Publishes one creative added to a campaign that is already running.
	 *
	 * Thin over `Creative_Approval`, which owns the capability check it repeats
	 * for its own audit trail, the running-campaign rule, and the promotion.
	 * There is no second rule set here and there must not be: the workflow is
	 * what the integration tests exercise.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function publish_creative( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = $this->creatives->approve( (int) $request->get_param( 'id' ) );

		if ( is_wp_error( $campaign_id ) ) {
			return $campaign_id;
		}

		return new WP_REST_Response( array( 'campaign' => $this->data->campaign( $campaign_id ) ), 200 );
	}

	/**
	 * Whether the caller may work the review queue at all.
	 *
	 * The same capability the screen's own gate uses. Object-level authorization
	 * stays in the workflows, which check it against the specific campaign and
	 * record the denial.
	 *
	 * @return bool
	 */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::REVIEW_CAMPAIGNS );
	}

	/**
	 * One page of the queue, with the tab counts beside it.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function queue( WP_REST_Request $request ): WP_REST_Response {
		$filter = (string) ( $request->get_param( 'filter' ) ?? '' );
		$paged  = (int) ( $request->get_param( 'paged' ) ?? 1 );

		// An unknown filter falls back rather than erroring: the tab set is
		// display state, and a stale bookmark should show the default queue
		// instead of a failure the reader cannot act on.
		if ( ! Review_Data::is_filter( $filter ) ) {
			$filter = Review_Data::DEFAULT_FILTER;
		}

		return new WP_REST_Response(
			array(
				'filter' => $filter,
				'tabs'   => $this->data->tabs(),
				'queue'  => $this->data->queue( $filter, max( 1, $paged ) ),
			),
			200
		);
	}

	/**
	 * One campaign in full.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function campaign( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign = $this->data->campaign( (int) $request->get_param( 'id' ) );

		if ( null === $campaign ) {
			return new WP_Error(
				'aggr_campaign_not_found',
				__( 'That campaign could not be found.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'campaign' => $campaign ), 200 );
	}

	/**
	 * Saves the staff-only notes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function save_notes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = (int) $request->get_param( 'id' );
		$result      = $this->actions->save_internal_notes( $campaign_id, self::notes( $request ) );

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		return $this->refreshed( $campaign_id );
	}

	/**
	 * Approves or rejects the advertiser's proposed campaign changes.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function decide_changes( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = (int) $request->get_param( 'id' );
		$result      = $this->changes->process( $campaign_id, self::decision( $request ), self::notes( $request ) );

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		return $this->refreshed( $campaign_id );
	}

	/**
	 * Closes an advertiser's request, with an explanation they will read.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return WP_REST_Response|WP_Error
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	public function resolve_request( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$campaign_id = (int) $request->get_param( 'id' );
		$result      = $this->changes->decline( $campaign_id, self::notes( $request ) );

		if ( is_wp_error( $result ) ) {
			return self::as_response_error( $result );
		}

		return $this->refreshed( $campaign_id );
	}

	/**
	 * The campaign as it stands after a write.
	 *
	 * Returned rather than a bare acknowledgement so the screen renders what the
	 * server holds. A decision moves more than it sent — approving a change
	 * rewrites fields, busts the fill cache and adds an audit row — and only the
	 * server knows what else moved.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return WP_REST_Response|WP_Error
	 */
	private function refreshed( int $campaign_id ): WP_REST_Response|WP_Error {
		$campaign = $this->data->campaign( $campaign_id );

		if ( null === $campaign ) {
			return new WP_Error(
				'aggr_campaign_not_found',
				__( 'That campaign could not be found.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		return new WP_REST_Response( array( 'campaign' => $campaign ), 200 );
	}

	/**
	 * The shared `id` path argument.
	 *
	 * @return array<string, mixed>
	 */
	private static function id_arg(): array {
		return array(
			'id' => array(
				'type'              => 'integer',
				'required'          => true,
				'sanitize_callback' => 'absint',
				'validate_callback' => static fn ( $value ): bool => is_numeric( $value ) && (int) $value > 0,
			),
		);
	}

	/**
	 * The advertiser-facing note carried by a decision.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function notes( WP_REST_Request $request ): string {
		$body  = $request->get_json_params();
		$body  = is_array( $body ) ? $body : array();
		$notes = $body['notes'] ?? '';

		return is_string( $notes ) ? sanitize_textarea_field( $notes ) : '';
	}

	/**
	 * The decision word, unshaped beyond its type.
	 *
	 * Whether `approve` and `reject` are the only two is the workflow's ruling,
	 * not a schema's: an unknown decision has to reach `process()` so it returns
	 * the workflow's own error rather than being refused by validation with a
	 * message nobody wrote.
	 *
	 * @param WP_REST_Request $request The request.
	 * @return string
	 *
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 */
	private static function decision( WP_REST_Request $request ): string {
		$body     = $request->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$decision = $body['decision'] ?? '';

		return is_string( $decision ) ? sanitize_key( $decision ) : '';
	}

	/**
	 * Gives a workflow error an HTTP status without rewording it.
	 *
	 * @param WP_Error $error Workflow error.
	 * @return WP_Error
	 */
	private static function as_response_error( WP_Error $error ): WP_Error {
		$status = match ( (string) $error->get_error_code() ) {
			'aggr_forbidden'                                     => 403,
			'aggr_campaign_not_found',
			'aggr_no_pending_edits',
			'aggr_no_action_request',
			'aggr_replacement_not_found'                         => 404,
			'aggr_campaign_not_running', 'aggr_live_edit_stale'   => 409,
			default                                              => 422,
		};

		$error->add_data( array( 'status' => $status ), (string) $error->get_error_code() );

		return $error;
	}
}
