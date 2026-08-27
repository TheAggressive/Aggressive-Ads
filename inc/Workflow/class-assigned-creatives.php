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
 * Structure from the assignment; values from the revision.
 *
 * The assignment's denormalized `click_url` and `alt_text` are a snapshot taken
 * at approval, correct only while the source is immutable. That holds for
 * serving. It does not hold for editing surfaces, where a draft creative is
 * still edited in place — reading the snapshot there would show an advertiser
 * their own edit ignored. The distinction goes away when every edit creates a
 * revision.
 *
 * Missing rows heal on the way past, through the same operation the backfill
 * uses, so there is one definition of a compatibility assignment.
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
	 * Adds no gate of its own: the caller must have authorized the campaign
	 * already. Healing writes, and a write on an unauthorized read is a
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
	 * Per creative, not all-or-nothing. Healing only when a campaign had *no*
	 * assignments was wrong: the backfill walks the creative id space globally,
	 * so one campaign's creatives can sit either side of the cursor and stay
	 * there — showing some of its artwork and not the rest.
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
