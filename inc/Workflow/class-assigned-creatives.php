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

		$rows = $this->assignments->for_campaign( $campaign_id );

		if ( array() === $rows ) {
			$rows = $this->heal( $campaign_id );
		}

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
	 * Creates the missing assignments for a campaign's active creatives.
	 *
	 * Only reached when a campaign has no assignments at all, which is the
	 * backfill not having got here yet. A campaign with *some* assignments is
	 * left alone: the backfill visits creatives in id order, so a partial
	 * result means it is mid-campaign, and racing it would create nothing the
	 * unique key does not already prevent while doing the work twice.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array<string, mixed>>
	 */
	private function heal( int $campaign_id ): array {
		foreach ( $this->creatives->for_campaign( $campaign_id ) as $creative ) {
			$this->migrator->migrate_one( (int) $creative['id'] );
		}

		return $this->assignments->for_campaign( $campaign_id );
	}
}
