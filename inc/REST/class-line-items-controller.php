<?php
/**
 * Campaign-scoped line-item REST API.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\REST;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Workflow\Assignment_Editor;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Line_Item_Editor;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

/** Reads and edits delivery strategies only through their parent campaign. */
final class Line_Items_Controller implements Service {

	/**
	 * Builds the line-item controller.
	 *
	 * @param Line_Item_Repository           $line_items Line-item persistence.
	 * @param Campaign_Repository            $campaigns  Campaign persistence.
	 * @param Line_Item_Editor               $editor     Authorized editor.
	 * @param Rate_Limiter                   $limiter    Write rate limiter.
	 * @param Creative_Assignment_Repository $assignments       Assignment persistence.
	 * @param Assignment_Editor              $assignment_editor Authorized assignment editor.
	 */
	public function __construct(
		private readonly Line_Item_Repository $line_items,
		private readonly Campaign_Repository $campaigns,
		private readonly Line_Item_Editor $editor,
		private readonly Rate_Limiter $limiter,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Assignment_Editor $assignment_editor
	) {
	}

	/** Attaches route registration. */
	public function init(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/** Registers campaign-scoped line-item routes. */
	public function register_routes(): void {
		$this->register_assignment_routes();

		Creative_File_Controller::register_route(
			'/campaigns/(?P<campaign_id>\d+)/line-items',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'index' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array( 'campaign_id' => $this->positive_int_arg( true ) ),
			)
		);

		Creative_File_Controller::register_route(
			'/campaigns/(?P<campaign_id>\d+)/line-items/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(
					'campaign_id'   => $this->positive_int_arg( true ),
					'id'            => $this->positive_int_arg( true ),
					'revision'      => $this->positive_int_arg( true ),
					'name'          => $this->string_arg(),
					'pricing_model' => $this->string_arg(),
					'goal_type'     => $this->string_arg(),
					'goal_amount'   => $this->nonnegative_int_arg(),
					'daily_cap'     => $this->nonnegative_int_arg(),
					'lifetime_cap'  => $this->nonnegative_int_arg(),
					'priority'      => $this->positive_int_arg( false ),
					'pacing_mode'   => $this->string_arg(),
					'weight'        => $this->positive_int_arg( false ),
				),
			)
		);
	}

	/**
	 * Registers the creative-assignment routes.
	 *
	 * On this controller rather than a new one because it already owns the
	 * campaign-scoped delivery surface, and because both routes share the same
	 * whole-number argument helpers — a second controller would mean a second
	 * copy of the rule that rejects `"1.5"` before `absint()` sees it.
	 */
	private function register_assignment_routes(): void {
		Creative_File_Controller::register_route(
			'/campaigns/(?P<campaign_id>\d+)/creative-assignments',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'assignments' ),
				'permission_callback' => array( $this, 'permission' ),
				'args'                => array( 'campaign_id' => $this->positive_int_arg( true ) ),
			)
		);

		Creative_File_Controller::register_route(
			'/campaigns/(?P<campaign_id>\d+)/creative-assignments/(?P<id>\d+)',
			array(
				'methods'             => 'PATCH',
				'callback'            => array( $this, 'update_assignment' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(
					'campaign_id' => $this->positive_int_arg( true ),
					'id'          => $this->positive_int_arg( true ),
					'revision'    => $this->positive_int_arg( true ),
					'weight'      => $this->positive_int_arg( false ),
					'start_at_ts' => $this->nonnegative_int_arg(),
					'end_at_ts'   => $this->nonnegative_int_arg(),
					'status'      => $this->string_arg(),
				),
			)
		);

		Creative_File_Controller::register_route(
			'/campaigns/(?P<campaign_id>\d+)/creative-assignments/(?P<id>\d+)/assignment',
			array(
				'methods'             => 'DELETE',
				'callback'            => array( $this, 'unassign' ),
				'permission_callback' => array( $this, 'write_permission' ),
				'args'                => array(
					'campaign_id' => $this->positive_int_arg( true ),
					'id'          => $this->positive_int_arg( true ),
					'revision'    => $this->positive_int_arg( true ),
				),
			)
		);
	}

	/**
	 * Lists a readable campaign's creative assignments.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function assignments( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'campaign_id' );

		if ( ! $this->campaigns->exists( $campaign_id ) || ! current_user_can( 'read_aggr_campaign', $campaign_id ) ) {
			return $this->not_found();
		}

		$rows = array();

		foreach ( $this->assignments->for_campaign( $campaign_id ) as $row ) {
			$rows[] = $this->present_assignment( $row );
		}

		return new WP_REST_Response( array( 'creative_assignments' => $rows ), 200 );
	}

	/**
	 * Updates one creative assignment.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_assignment( WP_REST_Request $request ) {
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_AUTOSAVE, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$fields = array();

		foreach ( array( 'weight', 'start_at_ts', 'end_at_ts', 'status' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}

		$campaign_id = (int) $request->get_param( 'campaign_id' );
		$result      = $this->assignment_editor->update(
			$campaign_id,
			(int) $request->get_param( 'id' ),
			$fields,
			(int) $request->get_param( 'revision' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $this->assignments->find_for_campaign( (int) $request->get_param( 'id' ), $campaign_id );

		return new WP_REST_Response( $this->present_assignment( (array) $row ), 200 );
	}

	/**
	 * Withdraws one creative from its placement, keeping the creative.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unassign( WP_REST_Request $request ) {
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_AUTOSAVE, get_current_user_id() );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		$campaign_id = (int) $request->get_param( 'campaign_id' );
		$result      = $this->assignment_editor->unassign(
			$campaign_id,
			(int) $request->get_param( 'id' ),
			(int) $request->get_param( 'revision' )
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $this->assignments->find_for_campaign( (int) $request->get_param( 'id' ), $campaign_id );

		return new WP_REST_Response( $this->present_assignment( (array) $row ), 200 );
	}

	/**
	 * The advertiser-facing shape of an assignment.
	 *
	 * Storage and tenancy columns are removed rather than allowlisted in,
	 * matching `present()` above: `organization_id` is already implied by the
	 * campaign the caller reached through, and `compat_key` is a migration
	 * detail no client has any business knowing about.
	 *
	 * @param array<string, mixed> $row Assignment row.
	 * @return array<string, mixed>
	 */
	private function present_assignment( array $row ): array {
		unset(
			$row['organization_id'],
			$row['compat_key'],
			$row['created_at_ts'],
			$row['updated_at_ts']
		);

		return $row;
	}

	/** Whether the caller may read the advertiser portal. */
	public function permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::ACCESS_PORTAL );
	}

	/** Whether the caller may reach a line-item write workflow. */
	public function write_permission(): bool {
		return is_user_logged_in() && current_user_can( Capabilities::SUBMIT_CAMPAIGN );
	}

	/**
	 * Lists a readable campaign's line items.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function index( WP_REST_Request $request ) {
		$campaign_id = (int) $request->get_param( 'campaign_id' );
		if ( ! $this->campaigns->exists( $campaign_id ) || ! current_user_can( 'read_aggr_campaign', $campaign_id ) ) {
			return $this->not_found();
		}

		$this->line_items->ensure_default( $campaign_id );

		return new WP_REST_Response(
			array( 'line_items' => array_map( array( $this, 'present' ), $this->line_items->for_campaign( $campaign_id ) ) ),
			200
		);
	}

	/**
	 * Updates one campaign-scoped line item.
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( WP_REST_Request $request ) {
		$allowed = $this->limiter->attempt( Rate_Limiter::ACTION_AUTOSAVE, get_current_user_id() );
		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		/*
		 * `budget_cents` is deliberately absent, and its absence is the contract.
		 *
		 * data-schema.md states it plainly: every projected field is
		 * campaign-owned, `sync_default_from_campaign()` copies the budget from
		 * the Campaign, and nothing else may write it. This route accepted it
		 * anyway, which made the documented owner and the actual writers
		 * disagree — and the Campaign always won in the end, because any later
		 * edit touching the schedule or the package re-projects and overwrites.
		 *
		 * So an advertiser could set a line-item budget, get a 200, see it
		 * stored, and lose it on their next unrelated save with nothing
		 * reporting the loss. Refusing the write is the honest answer: there is
		 * one owner, and it is the Campaign until a later phase moves it
		 * deliberately.
		 *
		 * `name` remains the one field with two owners; `name_is_derived`
		 * records which one wrote last, which is why it can be shared safely
		 * and the budget cannot.
		 */
		$fields = array();
		foreach ( array( 'name', 'pricing_model', 'goal_type', 'goal_amount', 'daily_cap', 'lifetime_cap', 'priority', 'pacing_mode', 'weight' ) as $field ) {
			if ( $request->has_param( $field ) ) {
				$fields[ $field ] = $request->get_param( $field );
			}
		}

		$campaign_id = (int) $request->get_param( 'campaign_id' );
		$id          = (int) $request->get_param( 'id' );
		$result      = $this->editor->update( $campaign_id, $id, $fields, (int) $request->get_param( 'revision' ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$row = $this->line_items->default_for_campaign( $campaign_id );

		return null === $row ? $this->not_found() : new WP_REST_Response( $this->present( $row ), 200 );
	}

	/**
	 * Removes persistence-only fields from a response row.
	 *
	 * @param array<string, mixed> $row Stored row.
	 * @return array<string, mixed>
	 */
	public function present( array $row ): array {
		unset( $row['organization_id'], $row['created_at_ts'], $row['updated_at_ts'] );

		return $row;
	}

	/** Returns the route's non-enumerating object refusal. */
	private function not_found(): WP_Error {
		return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
	}

	/**
	 * Whether a transported value is a whole number, in the form it arrived.
	 *
	 * Every numeric field on this route — amounts, caps, priority, weight,
	 * revision — is a whole-number domain value. `is_numeric()` is the wrong
	 * gate for that: it accepts `"1.5"`, `"1e3"` and `" 12 "`, and `absint()`
	 * then quietly turns them into 1, 1000 and 12. A client that sends a budget
	 * of 10.99 gets 10 stored and a 200 back, which is a lossy write reported
	 * as a successful one.
	 *
	 * So the raw value is checked before anything coerces it. An integer passes;
	 * a string passes only if it is digits and nothing else. A float never
	 * passes, `1.0` included — JSON that meant a whole number would have sent
	 * one, and accepting the decimal form is how the truncation gets back in.
	 *
	 * @param mixed $value Raw request value, before sanitisation.
	 * @return bool
	 */
	private static function is_whole_number( mixed $value ): bool {
		if ( is_int( $value ) ) {
			return true;
		}

		// Not is_numeric(): that is the check this replaces.
		return is_string( $value ) && 1 === preg_match( '/^[0-9]+$/', $value );
	}

	/**
	 * Builds a positive integer argument.
	 *
	 * @param bool $required Whether required.
	 * @return array<string, mixed>
	 */
	private function positive_int_arg( bool $required ): array {
		return array(
			'type'              => 'integer',
			'required'          => $required,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn ( $value ): bool => self::is_whole_number( $value ) && (int) $value > 0,
		);
	}

	/**
	 * Builds a non-negative integer argument.
	 *
	 * @return array<string, mixed>
	 */
	private function nonnegative_int_arg(): array {
		return array(
			'type'              => 'integer',
			'required'          => false,
			'sanitize_callback' => 'absint',
			'validate_callback' => static fn ( $value ): bool => self::is_whole_number( $value ) && (int) $value >= 0,
		);
	}

	/**
	 * Builds an optional string argument.
	 *
	 * @return array<string, mixed>
	 */
	private function string_arg(): array {
		return array(
			'type'              => 'string',
			'required'          => false,
			'validate_callback' => static fn ( $value ): bool => is_string( $value ),
		);
	}
}
