<?php
/**
 * Publishing a creative added to a campaign that is already running.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * The decision that had nowhere to be made.
 *
 * A creative is promoted into the Media Library by
 * `Publisher::publish_campaign()`, which runs on the transition *into* a
 * published state and promotes everything on the campaign at that moment. A
 * creative added afterwards misses it: the campaign has no publish transition
 * left, `EFFECT_RESUME` only busts the fill cache, and the only per-creative
 * approval that existed was for *replacements*. So the creative stayed
 * unpublished for ever, had no attachment, and `Decision_Engine` refused it
 * with `eligibility_missing_attachment` — correctly, and invisibly.
 *
 * This is that missing decision. It does the same work the campaign transition
 * would have: promote, re-point the assignment, drop the fill cache.
 */
final class Creative_Approval {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository            $campaigns   Campaign status and ownership.
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Creative_Promoter              $promoter    Private-to-public promotion.
	 * @param Assignment_Projection          $projection  Assignment snapshot refresh.
	 * @param Fill_Cache                     $cache       Delivery cache.
	 * @param Audit_Repository               $audit       Audit persistence.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Creative_Promoter $promoter,
		private readonly Assignment_Projection $projection,
		private readonly Fill_Cache $cache,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Creatives on a campaign that a reviewer still has to decide about.
	 *
	 * Only on a campaign that has already been published. Everything on a
	 * campaign still awaiting its first approval is decided by approving the
	 * campaign, and offering a second control for the same thing would let a
	 * reviewer publish one creative of a campaign nobody has approved.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function awaiting( int $campaign_id ): array {
		if ( ! $this->is_running( $campaign_id ) ) {
			return array();
		}

		return $this->creatives->unpublished_for_campaign( $campaign_id );
	}

	/**
	 * Publishes one creative on a running campaign.
	 *
	 * Returns the campaign it belongs to, so a caller can refresh the screen
	 * without asking a repository which campaign that was — the workflow has
	 * already had to decide, and answering twice is how the two disagree.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int|WP_Error Owning campaign id.
	 */
	public function approve( int $creative_id ): int|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) || ! current_user_can( Capabilities::PUBLISH_TO_ADSANITY ) ) {
			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to publish creative.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		$campaign_id = $this->creatives->campaign_id( $creative_id );

		if ( $campaign_id <= 0 ) {
			return new WP_Error(
				'aggr_creative_not_found',
				__( 'That creative no longer exists.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		/*
		 * Re-derived rather than taken from the caller. The route knows a
		 * creative id and nothing else; which campaign it belongs to, and
		 * whether that campaign is running, are the server's to decide.
		 */
		if ( ! in_array( $creative_id, $this->awaiting( $campaign_id ), true ) ) {
			return new WP_Error(
				'aggr_creative_not_awaiting',
				__( 'That creative is not waiting to be published.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		$promoted = $this->promoter->promote( $creative_id );

		if ( is_wp_error( $promoted ) ) {
			$this->record( $campaign_id, $creative_id, Audit_Event::OUTCOME_FAILED, 'Creative could not be published.' );

			return $promoted;
		}

		/*
		 * The assignment row carries its own copy of the attachment, because a
		 * fill reads that row and never joins back to the creative. Promotion
		 * alone would leave the snapshot at zero and the ad would stay
		 * ineligible — which is the same stale-snapshot fault that stopped
		 * delivery working at all until `Assignment_Projection` existed.
		 */
		$this->projection->project( $campaign_id );

		$this->campaigns->set_pending_creative_count(
			$campaign_id,
			$this->creatives->unpublished_count_for_campaign( $campaign_id )
		);

		$this->cache->bust_campaign( $campaign_id );

		$this->record( $campaign_id, $creative_id, Audit_Event::OUTCOME_OK, 'Creative published on a running campaign.' );

		return $campaign_id;
	}

	/**
	 * The longest explanation a reviewer may leave.
	 *
	 * The same bound the replacement rejection uses. An advertiser reads this,
	 * so it has to be room for a reason rather than a field somebody pastes a
	 * document into.
	 */
	public const MAX_NOTES_LENGTH = 2000;

	/**
	 * Turns down a creative on a running campaign, with a reason.
	 *
	 * **The reason is required**, exactly as it is for a rejected replacement.
	 * A creative that simply stops being offered tells the advertiser nothing,
	 * and the whole point of surfacing this decision was that silence was the
	 * previous behaviour.
	 *
	 * Publishing needs two capabilities because it puts bytes on a public page.
	 * Refusing needs only the reviewing one: turning something down cannot
	 * publish anything.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $notes       Explanation the advertiser will read.
	 * @return int|WP_Error Owning campaign id.
	 */
	public function reject( int $creative_id, string $notes ): int|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to review creative.', 'aggressive-ads' ),
				array( 'status' => 403 )
			);
		}

		$notes = trim( sanitize_textarea_field( $notes ) );

		if ( '' === $notes ) {
			return new WP_Error(
				'aggr_creative_notes_required',
				__( 'Explain why this advertisement is not being published.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		if ( mb_strlen( $notes ) > self::MAX_NOTES_LENGTH ) {
			return new WP_Error(
				'aggr_creative_notes_too_long',
				__( 'Use 2,000 characters or fewer for this explanation.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		$campaign_id = $this->creatives->campaign_id( $creative_id );

		if ( $campaign_id <= 0 ) {
			return new WP_Error(
				'aggr_creative_not_found',
				__( 'That creative no longer exists.', 'aggressive-ads' ),
				array( 'status' => 404 )
			);
		}

		if ( ! in_array( $creative_id, $this->awaiting( $campaign_id ), true ) ) {
			return new WP_Error(
				'aggr_creative_not_awaiting',
				__( 'That creative is not waiting to be published.', 'aggressive-ads' ),
				array( 'status' => 409 )
			);
		}

		if ( ! $this->creatives->reject_creative( $creative_id, $notes ) ) {
			$this->record( $campaign_id, $creative_id, Audit_Event::OUTCOME_FAILED, 'Creative decision could not be saved.' );

			return new WP_Error(
				'aggr_creative_decision_failed',
				__( 'That decision could not be saved. Please try again.', 'aggressive-ads' ),
				array( 'status' => 500 )
			);
		}

		$this->retire_assignments( $campaign_id, $creative_id );

		$this->campaigns->set_pending_creative_count(
			$campaign_id,
			$this->creatives->unpublished_count_for_campaign( $campaign_id )
		);

		$this->record( $campaign_id, $creative_id, Audit_Event::OUTCOME_OK, 'Creative turned down on a running campaign.' );

		return $campaign_id;
	}

	/**
	 * Takes a turned-down creative out of the candidate set.
	 *
	 * Rejecting only the creative would leave its assignment `live`, and the
	 * decision engine would go on considering a candidate it must always refuse
	 * for a missing attachment. Retiring is terminal, and
	 * `Assignment_Projection` never revives a terminal row — so a later campaign
	 * transition cannot quietly put it back.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $creative_id Creative post id.
	 */
	private function retire_assignments( int $campaign_id, int $creative_id ): void {
		foreach ( $this->assignments->for_campaign( $campaign_id ) as $row ) {
			if ( (int) ( $row['revision_id'] ?? 0 ) !== $creative_id ) {
				continue;
			}

			$this->assignments->retire(
				(int) ( $row['id'] ?? 0 ),
				$campaign_id,
				(int) ( $row['revision'] ?? 0 )
			);
		}
	}

	/**
	 * The reason a creative was turned down, for the advertiser who must read it.
	 *
	 * Deliberately not `Creative_Repository::change_notes()`, which is the raw
	 * key. `META_CHANGE_NOTES` carries two different decisions: a refused
	 * *replacement*, written on the replacement revision, and a turned-down
	 * creative, written on the creative itself. A reader taking the raw value is
	 * correct today only because `is_active()` happens to filter replacement
	 * revisions out before it is reached — a property of a different method,
	 * relied on silently, and true until somebody writes that key for a third
	 * reason.
	 *
	 * Pairing the reason with the decision is this class's job, because the
	 * decision is.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string Empty unless this creative was rejected.
	 */
	public function rejection_notes( int $creative_id ): string {
		return $this->creatives->is_rejected( $creative_id )
			? $this->creatives->change_notes( $creative_id )
			: '';
	}

	/**
	 * Recomputes the queue counter for one campaign.
	 *
	 * Called after an upload, so a creative added to a running campaign shows
	 * up for a reviewer rather than waiting to be noticed.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	public function refresh_count( int $campaign_id ): void {
		if ( $campaign_id <= 0 ) {
			return;
		}

		$this->campaigns->set_pending_creative_count(
			$campaign_id,
			$this->is_running( $campaign_id ) ? $this->creatives->unpublished_count_for_campaign( $campaign_id ) : 0
		);
	}

	/**
	 * Whether the campaign has already been published at least once.
	 *
	 * @param int $campaign_id Campaign post id.
	 */
	private function is_running( int $campaign_id ): bool {
		return in_array(
			$this->campaigns->status( $campaign_id ),
			array( Post_Statuses::SCHEDULED, Post_Statuses::LIVE, Post_Statuses::PAUSED ),
			true
		);
	}

	/**
	 * Records one decision.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $creative_id Creative post id.
	 * @param string $outcome     Audit outcome.
	 * @param string $message     Audit message.
	 */
	private function record( int $campaign_id, int $creative_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'creative.published_on_running_campaign',
				outcome: $outcome,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: $message,
				context: array( 'creative_id' => $creative_id ),
				actor_user_id: get_current_user_id()
			)
		);
	}
}
