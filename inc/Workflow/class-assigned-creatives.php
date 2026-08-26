<?php
/**
 * What is assigned where, for the screens that show a campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;

/**
 * The assignment table answers *which* revisions are assigned; not what they contain.
 *
 * That split is the whole design of this class and it is easy to get wrong in
 * the tempting direction. The assignment carries denormalized `click_url`,
 * `alt_text`, `width` and `height`, and reading them here would collapse a
 * second query — but those columns are a snapshot taken at approval, and their
 * correctness rests on the source being an *immutable* revision.
 *
 * That holds for serving, which only ever shows approved revisions. It does not
 * hold yet for the editing surfaces: an advertiser editing a draft creative
 * still updates post meta in place, because the write path has not been
 * converted to create a revision per edit. Reading the snapshot on a portal
 * screen would therefore show the value as it was when the backfill ran, and
 * the advertiser would see their own edit ignored.
 *
 * So structure comes from the assignment and values come from the revision, and
 * that stays true until editing creates revisions. When it does, the two are
 * the same answer and this class can stop making the distinction.
 *
 * Missing rows are healed on the way past, through the same operation the
 * backfill uses. A campaign somebody opens should not have to wait for cron,
 * and a second code path would mean two definitions of what a compatibility
 * assignment is.
 */
final class Assigned_Creatives {

	/**
	 * Builds the reader.
	 *
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Assignment_Migrator   $migrator    The canonical heal operation.
	 */
	public function __construct(
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Creative_Repository $creatives,
		private readonly Creative_Assignment_Migrator $migrator
	) {
	}

	/**
	 * Revision ids assigned to a campaign, healing anything missing.
	 *
	 * The caller has already authorized the campaign; this adds no gate of its
	 * own and must not be reached from an unauthenticated path. Healing writes,
	 * and a write on an unauthorized read is how a lazy migration becomes a
	 * denial-of-service vector.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int> Creative (revision) ids, in assignment order.
	 */
	public function revision_ids( int $campaign_id ): array {
		if ( $campaign_id <= 0 ) {
			return array();
		}

		$rows = $this->heal( $campaign_id );

		$ids = array();

		foreach ( $rows as $row ) {
			$revision_id = (int) ( $row['revision_id'] ?? 0 );

			if ( $revision_id > 0 && ! in_array( $revision_id, $ids, true ) ) {
				$ids[] = $revision_id;
			}
		}

		return $ids;
	}

	/**
	 * Creates whichever assignments a campaign is missing.
	 *
	 * **Per creative, not all-or-nothing.** The first version healed only when
	 * a campaign had *no* assignments, on the reasoning that a partial result
	 * meant the backfill was mid-campaign and would finish on its own. That
	 * reasoning was wrong: the backfill walks the *creative* id space globally,
	 * not campaign by campaign, so one campaign's creatives can sit either side
	 * of the cursor and stay that way for as long as the backfill takes.
	 *
	 * The symptom was quiet and would have been blamed on something else — a
	 * campaign showing some of its artwork and not the rest, on a screen that
	 * had no reason to be wrong.
	 *
	 * Skipping the ones already assigned keeps the ordinary read free of
	 * writes; only the genuinely missing are created.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function heal( int $campaign_id ): array {
		$rows     = $this->assignments->for_campaign( $campaign_id );
		$assigned = array();

		foreach ( $rows as $row ) {
			$assigned[ (int) ( $row['revision_id'] ?? 0 ) ] = true;
		}

		$created = false;

		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$creative_id = (int) $creative['id'];

			if ( isset( $assigned[ $creative_id ] ) ) {
				continue;
			}

			$this->migrator->migrate_one( $creative_id );
			$created = true;
		}

		return $created ? $this->assignments->for_campaign( $campaign_id ) : $rows;
	}
}
