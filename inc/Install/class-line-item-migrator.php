<?php
/**
 * Restartable legacy-campaign line-item backfill.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;

/** Migrates in small primary-key batches so deploy requests stay bounded. */
final class Line_Item_Migrator implements Service {

	public const HOOK          = 'aggr_migrate_line_items';
	public const OPTION_CURSOR = 'aggr_line_item_migration_cursor';
	public const OPTION_DONE   = 'aggr_line_item_migration_done';

	/**
	 * Cursor and completion marker for the name-provenance backfill.
	 *
	 * A second pass with its own cursor rather than a flag on the first, so a
	 * site part-way through the original backfill when this shipped resumes
	 * each one independently instead of restarting either.
	 */
	public const OPTION_NAME_CURSOR = 'aggr_line_item_name_cursor';
	public const OPTION_NAME_DONE   = 'aggr_line_item_name_done';
	public const BATCH_SIZE         = 100;

	/**
	 * Builds the migrator.
	 *
	 * @param Line_Item_Repository $line_items Line-item persistence.
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 */
	public function __construct(
		private readonly Line_Item_Repository $line_items,
		private readonly Campaign_Repository $campaigns
	) {
	}

	/** Attaches the migration callback and repairs a missing schedule. */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );

		if ( $this->line_items->table_exists() && ! $this->is_complete() ) {
			$this->schedule();
		}
	}

	/** Runs one scheduled batch without returning a value to the action hook. */
	public function run(): void {
		$this->run_batch();

		// Defaults first, names second, on the same tick: a row has to exist
		// before its name can be classified, and run_batch() reschedules the
		// hook while it still has campaigns to visit.
		if ( $this->is_complete() ) {
			$this->backfill_name_provenance_batch();
		}
	}

	/** Starts or resumes migration 12 after the table exists. */
	public function start(): void {
		if ( false === get_option( self::OPTION_CURSOR, false ) ) {
			add_option( self::OPTION_CURSOR, 0, '', false );
		}
		delete_option( self::OPTION_DONE );
		$this->schedule();
	}

	/**
	 * Migrates one bounded batch.
	 *
	 * @return int Number of campaigns inspected.
	 */
	public function run_batch(): int {
		if ( $this->is_complete() ) {
			return 0;
		}

		if ( ! $this->line_items->table_exists() ) {
			$this->line_items->install_table();
		}
		$cursor  = max( 0, (int) get_option( self::OPTION_CURSOR, 0 ) );
		$ids     = $this->line_items->campaign_ids_after( $cursor, self::BATCH_SIZE );
		$visited = 0;

		foreach ( $ids as $campaign_id ) {
			$row = $this->line_items->ensure_default( $campaign_id );
			if ( null === $row && $this->campaigns->exists( $campaign_id ) ) {
				break;
			}
			$cursor = $campaign_id;
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

	/** Whether the migration reached the end of the campaign id space. */
	public function is_complete(): bool {
		return 1 === (int) get_option( self::OPTION_DONE, 0 );
	}

	/** Clears any scheduled migration callback. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Schedules one batch unless one is already pending. */
	/**
	 * Starts the name-provenance backfill.
	 *
	 * @return void
	 */
	public function start_name_provenance(): void {
		if ( false === get_option( self::OPTION_NAME_CURSOR, false ) ) {
			add_option( self::OPTION_NAME_CURSOR, 0, '', false );
		}

		delete_option( self::OPTION_NAME_DONE );
		$this->schedule();
	}

	/**
	 * Whether the name-provenance backfill has finished.
	 *
	 * @return bool
	 */
	public function name_provenance_is_complete(): bool {
		return 1 === (int) get_option( self::OPTION_NAME_DONE, 0 );
	}

	/**
	 * Classifies one bounded page of existing line-item names.
	 *
	 * Runs after the default-creation pass on the same hook, because a row has
	 * to exist before its name can be classified.
	 *
	 * @return int Rows examined.
	 */
	public function backfill_name_provenance_batch(): int {
		if ( $this->name_provenance_is_complete() || ! $this->line_items->table_exists() ) {
			return 0;
		}

		$cursor = max( 0, (int) get_option( self::OPTION_NAME_CURSOR, 0 ) );
		$result = $this->line_items->backfill_name_provenance( $cursor, self::BATCH_SIZE );

		if ( $result['examined'] > 0 ) {
			update_option( self::OPTION_NAME_CURSOR, $result['cursor'], false );
		}

		if ( $result['examined'] < self::BATCH_SIZE ) {
			update_option( self::OPTION_NAME_DONE, 1, false );
			delete_option( self::OPTION_NAME_CURSOR );
		} else {
			$this->schedule();
		}

		return $result['examined'];
	}

	/** Schedules the next batch, unless one is already queued. */
	private function schedule(): void {
		if ( false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}
}
