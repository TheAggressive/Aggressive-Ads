<?php
/**
 * Authorized editing of a creative assignment.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Owns authorization, validation, concurrency and audit for assignments.
 *
 * The same shape as `Line_Item_Editor` — authorize, check the edit window, check
 * the revision, audit — because a second arrangement of those four is a second
 * place to forget one.
 *
 * Its own rule is the window: an assignment may narrow its parent's and never
 * widen it. `Assignment_Rules` states it; this supplies the parent.
 */
final class Assignment_Editor {

	/**
	 * Fields a caller may change. `revision_id` is absent deliberately: pointing
	 * at different artwork goes through review, not a field edit.
	 */
	private const WRITABLE = array( 'weight', 'start_at_ts', 'end_at_ts', 'status' );

	/**
	 * Builds the workflow.
	 *
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Line_Item_Repository           $line_items  Line-item persistence.
	 * @param Campaign_Repository            $campaigns   Campaign persistence.
	 * @param Audit_Repository               $audit       Audit persistence.
	 * @param Edit_Window                    $window      Campaign edit policy.
	 */
	public function __construct(
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Line_Item_Repository $line_items,
		private readonly Campaign_Repository $campaigns,
		private readonly Audit_Repository $audit,
		private readonly Edit_Window $window
	) {
	}

	/**
	 * Updates one campaign-scoped assignment.
	 *
	 * @param int                  $campaign_id       Campaign id.
	 * @param int                  $assignment_id     Assignment id.
	 * @param array<string, mixed> $fields            Candidate values.
	 * @param int                  $expected_revision Last-seen revision.
	 * @return int|WP_Error New assignment revision.
	 */
	public function update( int $campaign_id, int $assignment_id, array $fields, int $expected_revision ): int|WP_Error {
		$current = $this->authorize( $campaign_id, $assignment_id, $expected_revision );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		$clean = $this->validate( $fields, $current, $campaign_id );

		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$revision = $this->assignments->update( $assignment_id, $campaign_id, $clean, $expected_revision );

		if ( false === $revision ) {
			return $this->conflict( $assignment_id, $campaign_id );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative_assignment.updated',
				object_type: 'creative_assignment',
				object_id: $assignment_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				from_state: (string) $current['status'],
				to_state: (string) ( $clean['status'] ?? $current['status'] ),
				message: 'Creative delivery settings updated.',
				context: array(
					'campaign_id' => $campaign_id,
					'fields'      => array_keys( $clean ),
					'revision'    => $revision,
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $revision;
	}

	/**
	 * Withdraws one creative from its placement, keeping the creative.
	 *
	 * The gap this closes: removing a creative deleted the artwork, so an
	 * advertiser running three on a placement could not drop one without
	 * losing it.
	 *
	 * @param int $campaign_id       Campaign id.
	 * @param int $assignment_id     Assignment id.
	 * @param int $expected_revision Last-seen revision.
	 * @return int|WP_Error New assignment revision.
	 */
	public function unassign( int $campaign_id, int $assignment_id, int $expected_revision ): int|WP_Error {
		$current = $this->authorize( $campaign_id, $assignment_id, $expected_revision );

		if ( is_wp_error( $current ) ) {
			return $current;
		}

		/*
		 * Already withdrawn is refused, not repeated.
		 *
		 * `can_transition()` allows a status to stay itself, so a weight-only
		 * write is not rejected for failing to be a transition — which makes
		 * `cancelled → cancelled` legal there and wrong here. A second
		 * withdrawal would otherwise return 200 and bump the revision.
		 */
		if (
			Assignment_Rules::CANCELLED === (string) $current['status']
			|| ! Assignment_Rules::can_transition( (string) $current['status'], Assignment_Rules::CANCELLED )
		) {
			return new WP_Error(
				'aggr_assignment_transition_invalid',
				__( 'This creative cannot be withdrawn from where it is now.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		$revision = $this->assignments->retire( $assignment_id, $campaign_id, $expected_revision );

		if ( false === $revision ) {
			return $this->conflict( $assignment_id, $campaign_id );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative_assignment.unassigned',
				object_type: 'creative_assignment',
				object_id: $assignment_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				from_state: (string) $current['status'],
				to_state: Assignment_Rules::CANCELLED,
				message: 'Creative withdrawn from its placement.',
				context: array(
					'campaign_id' => $campaign_id,
					'revision_id' => (int) $current['revision_id'],
					'revision'    => $revision,
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $revision;
	}

	/**
	 * Shared authorization, edit-window and revision checks.
	 *
	 * @param int $campaign_id       Campaign id.
	 * @param int $assignment_id     Assignment id.
	 * @param int $expected_revision Last-seen revision.
	 * @return array<string, mixed>|WP_Error
	 */
	private function authorize( int $campaign_id, int $assignment_id, int $expected_revision ): array|WP_Error {
		// Missing and forbidden answer alike, or the route counts other tenants.
		if (
			! current_user_can( Capabilities::SUBMIT_CAMPAIGN )
			|| ! $this->campaigns->exists( $campaign_id )
			|| ! current_user_can( 'edit_aggr_campaign', $campaign_id )
		) {
			return $this->not_found();
		}

		if ( ! $this->window->allows( $campaign_id ) ) {
			return new WP_Error(
				'aggr_campaign_not_editable',
				__( 'This campaign cannot be changed right now.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		$current = $this->assignments->find_for_campaign( $assignment_id, $campaign_id );

		if ( null === $current ) {
			return $this->not_found();
		}

		if ( $expected_revision < 1 || $expected_revision !== (int) $current['revision'] ) {
			return $this->conflict( $assignment_id, $campaign_id );
		}

		return $current;
	}

	/**
	 * The stale-revision refusal, carrying the current one.
	 *
	 * @param int $assignment_id Assignment id.
	 * @param int $campaign_id   Campaign id.
	 * @return WP_Error
	 */
	private function conflict( int $assignment_id, int $campaign_id ): WP_Error {
		$fresh = $this->assignments->find_for_campaign( $assignment_id, $campaign_id );

		return new WP_Error(
			'aggr_assignment_conflict',
			__( 'This creative changed in another window. Reload it before saving again.', 'aggressive-ads' ),
			array(
				'status'           => 409,
				'current_revision' => (int) ( $fresh['revision'] ?? 0 ),
			)
		);
	}

	/**
	 * Validates candidate values against the assignment and its parent.
	 *
	 * @param array<string, mixed> $fields      Candidate values.
	 * @param array<string, mixed> $current     Current row.
	 * @param int                  $campaign_id Campaign id.
	 * @return array<string, mixed>|WP_Error
	 */
	private function validate( array $fields, array $current, int $campaign_id ): array|WP_Error {
		$clean = array();

		foreach ( self::WRITABLE as $field ) {
			if ( array_key_exists( $field, $fields ) ) {
				$clean[ $field ] = $fields[ $field ];
			}
		}

		if ( array() === $clean ) {
			return new WP_Error(
				'aggr_assignment_fields_required',
				__( 'Change at least one delivery setting before saving.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		if ( array_key_exists( 'weight', $clean ) && ! Assignment_Rules::is_weight( (int) $clean['weight'] ) ) {
			return new WP_Error(
				'aggr_assignment_weight_invalid',
				sprintf(
					/* translators: 1: minimum weight, 2: maximum weight. */
					__( 'Use a whole number between %1$d and %2$d for the rotation weight.', 'aggressive-ads' ),
					Assignment_Rules::MIN_WEIGHT,
					Assignment_Rules::MAX_WEIGHT
				),
				array( 'status' => 422 )
			);
		}

		if ( array_key_exists( 'status', $clean ) ) {
			$to = (string) $clean['status'];

			if ( ! Assignment_Rules::can_transition( (string) $current['status'], $to ) ) {
				return new WP_Error(
					'aggr_assignment_transition_invalid',
					__( 'This creative cannot move to that state from where it is now.', 'aggressive-ads' ),
					array( 'status' => 409 )
				);
			}

			/*
			 * Records that this pause was somebody's decision about this
			 * advertisement, rather than a consequence of its campaign pausing.
			 * Nothing else can tell the two apart afterwards — both leave the
			 * identical row — and without the distinction a campaign resume
			 * silently puts a deliberately stopped ad back on the page.
			 *
			 * Set on the way in and cleared on the way out, so resuming one
			 * hands it back to its campaign rather than pinning it live.
			 */
			$clean['operator_paused'] = Assignment_Rules::is_operator_pause( $to ) ? 1 : 0;
		}

		$start = array_key_exists( 'start_at_ts', $clean ) ? (int) $clean['start_at_ts'] : (int) $current['start_at_ts'];
		$end   = array_key_exists( 'end_at_ts', $clean ) ? (int) $clean['end_at_ts'] : (int) $current['end_at_ts'];

		if ( array_key_exists( 'start_at_ts', $clean ) || array_key_exists( 'end_at_ts', $clean ) ) {
			$parent = $this->parent_window( $campaign_id );

			if ( ! Assignment_Rules::window_fits( $start, $end, $parent['start'], $parent['end'] ) ) {
				return new WP_Error(
					'aggr_assignment_window_invalid',
					__( 'Creative dates must fall inside the campaign’s own dates.', 'aggressive-ads' ),
					array( 'status' => 422 )
				);
			}
		}

		return $clean;
	}

	/**
	 * The window an assignment must fit inside.
	 *
	 * The line item's, falling back to the campaign's — so a campaign
	 * mid-migration is not treated as unbounded.
	 *
	 * @param int $campaign_id Campaign id.
	 * @return array{start: int, end: int}
	 */
	private function parent_window( int $campaign_id ): array {
		$line_item = $this->line_items->default_for_campaign( $campaign_id );

		if ( null !== $line_item ) {
			return array(
				'start' => (int) $line_item['start_at_ts'],
				'end'   => (int) $line_item['end_at_ts'],
			);
		}

		return array(
			'start' => $this->campaigns->start_ts( $campaign_id ),
			'end'   => $this->campaigns->end_ts( $campaign_id ),
		);
	}

	/** The route's non-enumerating object refusal. */
	private function not_found(): WP_Error {
		return new WP_Error( 'aggr_not_found', __( 'Not found.', 'aggressive-ads' ), array( 'status' => 404 ) );
	}
}
