<?php
/**
 * Restartable backfill of P2 creative assets and assignments.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Creative_Revision_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;

/**
 * Gives every existing Creative an asset and a compatibility assignment.
 *
 * Deliberately the same shape as `Line_Item_Migrator`, down to the option
 * names and the batch loop, because that pattern has been through this
 * repository's failure modes already: a lost cron event after one pass
 * stranded the next, and completion was marked before the id space was
 * exhausted. Inventing a second migration pattern would mean rediscovering
 * both.
 *
 * **Nothing reads what this writes yet on the serving path.** Native fill still
 * selects Campaigns and Creative posts, so a half-finished or failed backfill
 * cannot blank an ad slot. That is the property the P1 migration was built
 * around and it is preserved here on purpose.
 */
final class Creative_Assignment_Migrator implements Service {

	public const HOOK          = 'aggr_migrate_creative_assignments';
	public const OPTION_CURSOR = 'aggr_creative_assignment_cursor';
	public const OPTION_DONE   = 'aggr_creative_assignment_done';
	public const BATCH_SIZE    = 100;

	/**
	 * Builds the migrator.
	 *
	 * @param Creative_Repository            $creatives   Creative persistence.
	 * @param Creative_Asset_Repository      $assets      Asset persistence.
	 * @param Creative_Assignment_Repository $assignments Assignment persistence.
	 * @param Line_Item_Repository           $line_items  Line-item persistence.
	 * @param Campaign_Repository            $campaigns   Campaign persistence.
	 * @param Creative_Revision_Repository   $revisions   Revision chain persistence.
	 */
	public function __construct(
		private readonly Creative_Repository $creatives,
		private readonly Creative_Asset_Repository $assets,
		private readonly Creative_Assignment_Repository $assignments,
		private readonly Line_Item_Repository $line_items,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Revision_Repository $revisions
	) {
	}

	/** Attaches the migration callback and repairs a missing schedule. */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		// Scheduling work against a table that does not exist would fire a job
		// that can only fail. The same guard `Line_Item_Migrator` carries.
		if ( ! $this->assignments->table_exists() ) {
			return;
		}

		if ( ! $this->is_complete() ) {
			$this->schedule();
		}
	}

	/** Runs one batch without returning a value to the action hook. */
	public function run(): void {
		$this->run_batch();
	}

	/** Seeds the cursor and schedules the first batch. */
	public function start(): void {
		if ( false === get_option( self::OPTION_CURSOR, false ) ) {
			add_option( self::OPTION_CURSOR, 0, '', false );
		}

		delete_option( self::OPTION_DONE );
		$this->schedule();
	}

	/** Whether the backfill reached the end of the creative id space. */
	public function is_complete(): bool {
		return 1 === (int) get_option( self::OPTION_DONE, 0 );
	}

	/** Clears any scheduled backfill callback. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Migrates one bounded batch.
	 *
	 * @return int Number of creatives inspected.
	 */
	public function run_batch(): int {
		if ( $this->is_complete() || ! $this->assignments->table_exists() ) {
			return 0;
		}

		$cursor  = max( 0, (int) get_option( self::OPTION_CURSOR, 0 ) );
		$ids     = $this->creatives->creative_ids_after( $cursor, self::BATCH_SIZE );
		$visited = 0;

		foreach ( $ids as $creative_id ) {
			$this->migrate_one( $creative_id );

			/*
			 * The cursor advances whether or not a row was created.
			 *
			 * A creative with no campaign, no line item or no placement cannot
			 * have an assignment and never will — stopping on it would wedge
			 * the backfill on one unmigratable row forever. Skipping it is the
			 * correct answer; the destination is defined only for creatives
			 * that have somewhere to be assigned.
			 */
			$cursor = $creative_id;
			++$visited;

			update_option( self::OPTION_CURSOR, $cursor, false );
		}

		if ( count( $ids ) === $visited && count( $ids ) < self::BATCH_SIZE ) {
			update_option( self::OPTION_DONE, 1, false );
			delete_option( self::OPTION_CURSOR );
			wp_clear_scheduled_hook( self::HOOK );
		} else {
			$this->schedule();
		}

		return $visited;
	}

	/**
	 * Gives one creative its asset and compatibility assignment.
	 *
	 * Public so an authorized read can materialize a missing record with the
	 * same operation the backfill uses — the contract permits lazy healing
	 * precisely so a campaign somebody opens does not have to wait for cron,
	 * and using a second code path would mean two definitions of what a
	 * compatibility assignment is.
	 *
	 * @param int $creative_id Creative post id.
	 * @return array<string, mixed>|null The assignment row, or null when the
	 *                                   creative has nowhere to be assigned.
	 */
	public function migrate_one( int $creative_id ): ?array {
		$details = $this->creatives->details( $creative_id );

		if ( null === $details ) {
			return null;
		}

		$campaign_id  = (int) ( $details['campaign_id'] ?? 0 );
		$placement_id = (int) ( $details['placement_id'] ?? 0 );

		if ( $campaign_id <= 0 || $placement_id <= 0 ) {
			return null;
		}

		$line_item = $this->line_items->ensure_default( $campaign_id );

		if ( null === $line_item ) {
			return null;
		}

		$root  = $this->revisions->chain_root( $creative_id );
		$asset = $this->assets->ensure_for_root(
			$root,
			(int) ( $details['org_id'] ?? 0 ),
			$this->creatives->title( $root )
		);

		return $this->assignments->ensure(
			array(
				'line_item_id'    => (int) $line_item['id'],
				'campaign_id'     => $campaign_id,
				'organization_id' => (int) ( $details['org_id'] ?? 0 ),
				'asset_id'        => $asset,
				'revision_id'     => $creative_id,
				'placement_id'    => $placement_id,
				'status'          => $this->campaigns->status( $campaign_id ),
				'click_url'       => (string) ( $details['click_url'] ?? '' ),
				'alt_text'        => (string) ( $details['alt_text'] ?? '' ),
				'width'           => (int) ( $details['width'] ?? 0 ),
				'height'          => (int) ( $details['height'] ?? 0 ),
				// `details()` does not carry this; the repository has its own accessor.
				'attachment_id'   => $this->creatives->attachment_id( $creative_id ),
			)
		);
	}

	/** Schedules the next batch, unless one is already queued. */
	private function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}
}
