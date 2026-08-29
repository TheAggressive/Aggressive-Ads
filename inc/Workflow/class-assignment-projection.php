<?php
/**
 * Keeping assignment rows in step with their campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;

/**
 * Re-derives the campaign-owned columns on every assignment a campaign owns.
 *
 * **This is the write half of the fill query.** `candidates_for_placement()`
 * selects on `status = 'live'` and reads `attachment_id` off the assignment
 * row, because a fill must be one indexed read rather than a join back to the
 * campaign and the creative. That denormalization is only correct while
 * something refreshes it.
 *
 * Nothing did. `Assignment_Rules::status_for_campaign()` existed and was
 * correct, and its only production caller was `Creative_Assignment_Migrator` —
 * the one-time P2 backfill. So an assignment's status was set once, during that
 * migration, and then frozen: every campaign that went live afterwards kept
 * assignments at `draft`, matched no candidate, and served nothing. The same
 * held for `attachment_id`, which promotion writes onto the creative and
 * nothing copied onto the row delivery reads.
 *
 * Every delivery test passed throughout, because each one wrote
 * `'status' => Assignment_Rules::LIVE` into its own fixture — arranging by hand
 * the state production never produced. That is the shape `CLAUDE.md` records
 * for frequency capping: a correct read half, no write half, and a green suite
 * over both.
 */
final class Assignment_Projection implements Service {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository            $campaigns   Campaign status and ownership.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Creative_Repository            $creatives   Promoted attachment lookup.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Creative_Repository $creatives
	) {
	}

	/**
	 * Projects on every campaign transition.
	 *
	 * Priority 6, immediately after `Line_Item_Lifecycle::sync()` at 5. The line
	 * item is the assignment's parent, so it is settled first — nothing here
	 * reads it today, and the ordering means nothing has to change if that stops
	 * being true.
	 */
	public function init(): void {
		add_action( 'aggr_campaign_transitioned', array( $this, 'on_transition' ), 6, 1 );
	}

	/**
	 * The hook's callback.
	 *
	 * Separate from `project()` so the useful return value survives: an action
	 * callback must return nothing, and folding the two together would mean
	 * either a hook that returns a count or a method a test cannot assert on.
	 *
	 * @param int $campaign_id Transitioned campaign id.
	 */
	public function on_transition( int $campaign_id ): void {
		$this->project( $campaign_id );
	}

	/**
	 * Re-derives every non-terminal assignment the campaign owns.
	 *
	 * @param int $campaign_id Transitioned campaign id.
	 * @return int Rows changed.
	 */
	public function project( int $campaign_id ): int {
		if ( $campaign_id <= 0 ) {
			return 0;
		}

		$rows = $this->assignments->for_campaign( $campaign_id );

		if ( array() === $rows ) {
			return 0;
		}

		$status = Assignment_Rules::status_for_campaign( $this->campaigns->status( $campaign_id ) );
		$org_id = $this->campaigns->org_id( $campaign_id );
		$moved  = 0;

		foreach ( $rows as $row ) {
			$current = (string) ( $row['status'] ?? '' );

			/*
			 * A withdrawal is the one thing a campaign transition must not
			 * undo. Checked here as well as in SQL: this keeps the intent
			 * legible, the statement keeps it true under a concurrent retire.
			 */
			if ( Assignment_Rules::is_terminal( $current ) ) {
				continue;
			}

			$fields = array(
				'status'          => $status,
				'organization_id' => $org_id,
			);

			/*
			 * Only ever set forward. Promotion writes the attachment onto the
			 * creative at approval; a creative not yet promoted has none, and
			 * projecting that zero would blank an assignment that was already
			 * serving and take a live ad down.
			 */
			$attachment_id = $this->creatives->attachment_id( (int) ( $row['revision_id'] ?? 0 ) );

			if ( $attachment_id > 0 ) {
				$fields['attachment_id'] = $attachment_id;
			}

			if ( $this->assignments->project( (int) ( $row['id'] ?? 0 ), $fields ) ) {
				++$moved;
			}
		}

		return $moved;
	}
}
