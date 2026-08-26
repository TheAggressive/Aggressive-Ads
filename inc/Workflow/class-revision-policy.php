<?php
/**
 * When a creative becomes immutable, and what an edit does after that.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;

/**
 * One function answers "is this frozen", and every write site asks it.
 *
 * That is deliberate and it is the main risk this class exists to remove. The
 * rule — a draft may be edited in place, an approved revision may not — is
 * simple to state and easy to re-derive slightly differently at each call site,
 * and a single call site that gets it wrong mutates artwork a publisher already
 * approved. So the condition lives here once.
 *
 * **Freezing begins at approval, not at creation.** A draft has never been
 * approved, so there is nothing to protect and nothing to preserve: editing one
 * costs nothing and creating a revision for every autosave would fill the
 * history with rows nobody will ever look at. `docs/platform-p2-creative-model.md`
 * scopes immutability to *approved* revisions for exactly this reason.
 *
 * Promotion to the Media Library is the observable form of "approved": the
 * private original is deleted the moment a creative is promoted, so an
 * attachment is the artwork a publisher signed off and the one being served.
 */
final class Revision_Policy {

	/**
	 * Builds the policy.
	 *
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Revision_Repository   $revisions   Revision chain persistence.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Line_Item_Repository           $line_items  Line-item persistence.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Revision_Repository $revisions,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Line_Item_Repository $line_items
	) {
	}

	/**
	 * Whether this creative may no longer be edited in place.
	 *
	 * The single authority. Anything that writes creative text asks this first,
	 * and nothing re-derives it.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function is_frozen( int $creative_id ): bool {
		return $creative_id > 0 && $this->creatives->has_attachment( $creative_id );
	}

	/**
	 * Applies a text change, revising rather than mutating when frozen.
	 *
	 * The caller has already authorized the change and, when the creative is
	 * frozen, a person has already approved this exact text — a destination
	 * edit reaches here only after staff approve the campaign change that
	 * proposed it. So the revision this creates is approved on arrival rather
	 * than re-entering the review queue: sending it back would ask the same
	 * reviewer to approve the thing they just approved, and the campaign would
	 * sit in review over a change already decided.
	 *
	 * That is the one place this differs from an advertiser-initiated edit,
	 * which will create a pending revision and use the text-only review lane.
	 *
	 * @param int         $creative_id Creative post id.
	 * @param string|null $click_url   New destination, or null to keep.
	 * @param string|null $alt_text    New alternative text, or null to keep.
	 * @return int The creative id now carrying the text: the same one when it
	 *             was editable, a new revision when it was frozen, or 0 when
	 *             nothing changed.
	 */
	public function apply_text_change( int $creative_id, ?string $click_url = null, ?string $alt_text = null ): int {
		$current = $this->creatives->details( $creative_id );

		if ( null === $current ) {
			return 0;
		}

		$next_url = null === $click_url ? (string) $current['click_url'] : $click_url;
		$next_alt = null === $alt_text ? (string) $current['alt_text'] : $alt_text;

		/*
		 * A change that changes nothing is refused rather than recorded.
		 *
		 * The contract asks for this explicitly: a revision identical to its
		 * predecessor in both bytes and text says nothing, and a queue full of
		 * them is worse than no history at all.
		 */
		if ( $next_url === (string) $current['click_url'] && $next_alt === (string) $current['alt_text'] ) {
			return 0;
		}

		if ( ! $this->is_frozen( $creative_id ) ) {
			// Never approved, so there is nothing to preserve.
			$this->creatives->set_text( $creative_id, $next_url, $next_alt );

			return $creative_id;
		}

		$revision_id = $this->revisions->create_text_revision( $creative_id, $next_url, $next_alt );

		if ( $revision_id <= 0 ) {
			return 0;
		}

		$this->repoint_assignment( $current, $revision_id, $next_url, $next_alt );

		return $revision_id;
	}

	/**
	 * Moves the campaign's assignment onto the new revision.
	 *
	 * Without this the assignment still names the superseded revision, and the
	 * screens that read structure from assignments would show the old artwork
	 * record for a campaign whose destination has changed. The snapshot columns
	 * move with it, because they describe the revision being pointed at.
	 *
	 * @param array<string, mixed> $current     Superseded creative details.
	 * @param int                  $revision_id New revision id.
	 * @param string               $click_url   New destination.
	 * @param string               $alt_text    New alternative text.
	 */
	private function repoint_assignment( array $current, int $revision_id, string $click_url, string $alt_text ): void {
		$campaign_id = (int) ( $current['campaign_id'] ?? 0 );
		$line_item   = $this->line_items->default_for_campaign( $campaign_id );

		if ( null === $line_item ) {
			return;
		}

		$this->assignments->point_at_revision(
			(int) $line_item['id'],
			(int) ( $current['placement_id'] ?? 0 ),
			$revision_id,
			array(
				'click_url' => $click_url,
				'alt_text'  => $alt_text,
			)
		);
	}
}
