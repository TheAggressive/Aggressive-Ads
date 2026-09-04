<?php
/**
 * When a creative becomes immutable, and what an edit does after that.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;

/**
 * One function answers "is this frozen", and every write site asks it.
 *
 * Freezing begins at approval, not creation: a draft has never been approved, so
 * a revision per autosave would fill the history with rows nobody reads. See
 * docs/platform-p2-creative-model.md.
 *
 * Promotion to the Media Library is the observable form of "approved" — the
 * private original is deleted at that moment.
 */
final class Revision_Policy {

	/**
	 * Builds the policy.
	 *
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Attachment_Repository $attachments Media Library copy of the artwork.
	 * @param Creative_Revision_Repository   $revisions   Revision chain persistence.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Line_Item_Repository           $line_items  Line-item persistence.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Attachment_Repository $attachments,
		private readonly Creative_Revision_Repository $revisions,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Line_Item_Repository $line_items
	) {
	}

	/**
	 * Whether this creative may no longer be edited in place. The single
	 * authority; nothing re-derives it.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function is_frozen( int $creative_id ): bool {
		return $creative_id > 0 && $this->attachments->has_attachment( $creative_id );
	}

	/**
	 * Applies a text change, revising rather than mutating when frozen.
	 *
	 * The revision arrives approved rather than pending: a destination edit only
	 * reaches here after staff approve the campaign change that proposed it, so
	 * re-queueing it would ask the same reviewer to approve what they just did.
	 * An advertiser-initiated edit differs — that one stages a pending revision.
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

		// A revision identical in bytes and text says nothing; a queue full of
		// them is worse than no history.
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
	 * Moves the campaign's assignment onto the new revision, snapshot and all —
	 * a row naming one revision and describing another is worse than not
	 * denormalizing at all.
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
