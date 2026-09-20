<?php
/**
 * How much of a placement each of its creatives takes.
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
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Sets one creative's share, and the others' with it.
 *
 * Its own class rather than another method on `Creative_Manager`, which is
 * near the file-length gate, and for a plainer reason: every other creative
 * write changes one row, and this one changes every row on the placement.
 * That is a different failure to reason about — a half-applied rotation — and
 * it belongs somewhere a reviewer can see whole.
 */
final class Share_Editor {

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Assignment_Repository $assignments Delivery assignments.
	 * @param Campaign_Repository            $campaigns   Campaign persistence.
	 * @param Edit_Window                    $window      When editing is permitted.
	 * @param Audit_Repository               $audit       Audit persistence.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Campaign_Repository $campaigns,
		private readonly Edit_Window $window,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Gives one creative a percentage of its placement.
	 *
	 * The others keep the proportions they had between them, so the column
	 * still adds to a hundred — `Assignment_Rules::rebalance()` is the whole
	 * of that arithmetic and is tested on its own.
	 *
	 * **Each row is written with the revision it was read at.** Two people
	 * setting shares on one placement cannot overwrite each other silently:
	 * the second write matches no row and is reported. A write that fails
	 * part-way leaves the rotation as it was for the rows not yet reached and
	 * says so, rather than reporting a rotation nobody asked for as saved.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $percent     Share of the placement, 1–100.
	 * @return array<int, int>|WP_Error Percentage by creative id, or a refusal.
	 */
	public function set_share( int $creative_id, int $percent ): array|WP_Error {
		if ( ! current_user_can( Capabilities::UPLOAD_CREATIVE ) || ! current_user_can( 'edit_aggr_creative', $creative_id ) ) {
			return $this->error( 'aggr_share_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$creative = $this->creatives->details( $creative_id );

		if ( null === $creative ) {
			return $this->error( 'aggr_share_forbidden', __( 'You do not have permission to change that creative.', 'aggressive-ads' ), 403 );
		}

		$campaign_id = (int) $creative['campaign_id'];

		if ( ! current_user_can( 'edit_aggr_campaign', $campaign_id ) || ! $this->window->allows( $campaign_id ) ) {
			return $this->error( 'aggr_campaign_not_editable', __( 'This campaign cannot be changed right now.', 'aggressive-ads' ), 409 );
		}

		$mine = 0;

		foreach ( $this->rotation( $campaign_id, (int) $creative['placement_id'] ) as $row ) {
			if ( (int) $row['revision_id'] === $creative_id ) {
				$mine = (int) $row['id'];
			}
		}

		if ( 0 === $mine ) {
			return $this->error( 'aggr_share_no_assignment', __( 'That creative is not delivering yet, so it has no share to set.', 'aggressive-ads' ), 409 );
		}

		return $this->apply( $campaign_id, $mine, $percent );
	}

	/**
	 * Gives one assignment a percentage of its placement.
	 *
	 * The same rebalance the portal does, reached by assignment rather than by
	 * creative, because the API addresses assignments. **One rule with two
	 * front doors, not two rules**: the REST route used to write the raw
	 * weight column, so a share set through the API left the placement adding
	 * to whatever it happened to add to, while the portal kept it at a
	 * hundred.
	 *
	 * The caller is responsible for authorizing the campaign; every write goes
	 * through the assignment's own campaign scope regardless.
	 *
	 * @param int $campaign_id   Campaign post id.
	 * @param int $assignment_id The assignment to set.
	 * @param int $percent       Share of the placement, 1–100.
	 * @return array<int, int>|WP_Error Percentage by creative id, or a refusal.
	 */
	public function apply( int $campaign_id, int $assignment_id, int $percent ): array|WP_Error {
		$assignment = $this->assignments->find_for_campaign( $assignment_id, $campaign_id );

		if ( null === $assignment ) {
			return $this->error( 'aggr_share_no_assignment', __( 'That creative is not delivering yet, so it has no share to set.', 'aggressive-ads' ), 409 );
		}

		$rows        = $this->rotation( $campaign_id, (int) $assignment['placement_id'] );
		$mine        = $assignment_id;
		$creative_id = (int) $assignment['revision_id'];

		$weights = array();

		foreach ( $rows as $row ) {
			$weights[ (int) $row['id'] ] = (int) $row['weight'];
		}

		$shares  = Assignment_Rules::rebalance( $weights, $mine, $percent );
		$by_id   = array();
		$changed = array();

		foreach ( $rows as $row ) {
			$id = (int) $row['id'];

			// Only what changed: a write of the same number is a revision
			// bump that makes somebody else's open page conflict for nothing.
			if ( $shares[ $id ] !== $weights[ $id ] ) {
				$changed[ $id ] = array(
					'weight'   => $shares[ $id ],
					'revision' => (int) $row['revision'],
				);
			}

			$by_id[ (int) $row['revision_id'] ] = $shares[ $id ];
		}

		// All of them or none: a rotation half written adds to nothing anybody chose.
		if ( array() !== $changed && ! $this->assignments->set_weights( $campaign_id, $changed ) ) {
			return $this->error( 'aggr_share_not_saved', __( 'Those shares could not be saved. Reload the page and try again.', 'aggressive-ads' ), 409 );
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'creative.share_changed',
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 'Placement shares changed.',
				context: array(
					'creative_id'  => $creative_id,
					'placement_id' => (int) $assignment['placement_id'],
					'percent'      => $shares[ $mine ],
					'shares'       => $by_id,
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $by_id;
	}

	/**
	 * The creatives competing on one placement, oldest first.
	 *
	 * Retired and completed rows are left out: they deliver nothing, and
	 * counting them would divide a hundred per cent between ads that ran last
	 * month and ads running now.
	 *
	 * @param int $campaign_id  Campaign post id.
	 * @param int $placement_id Placement post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function rotation( int $campaign_id, int $placement_id ): array {
		$rows = array();

		foreach ( $this->assignments->for_campaign( $campaign_id ) as $row ) {
			if ( (int) $row['placement_id'] !== $placement_id ) {
				continue;
			}

			if ( Assignment_Rules::is_terminal( (string) $row['status'] ) ) {
				continue;
			}

			$rows[] = $row;
		}

		return $rows;
	}

	/**
	 * Builds a refusal in the shape the portal renders.
	 *
	 * @param string $code    Stable error code.
	 * @param string $message User-facing message.
	 * @param int    $status  HTTP status.
	 * @return WP_Error
	 */
	private function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, array( 'status' => $status ) );
	}
}
