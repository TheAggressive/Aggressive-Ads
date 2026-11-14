<?php
/**
 * Bounded, restartable compatibility projection for responsive image eligibility.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Image_Projection_Repository;
use Aggressive\Ads\Repository\Image_Resource_Repository;

/** Legacy rows keep serving; new snapshots are derived from their actual reviewed publication. */
final class Image_Projection_Migrator implements Service {

	public const HOOK = 'aggr_migrate_image_projection';

	/**
	 * Repair eligibility facts through a restartable primary-id cursor.
	 *
	 * @param Image_Projection_Repository $rows Schema, cursor and CAS writes.
	 * @param Image_Resource_Repository   $resources Revision-owned source facts.
	 */
	public function __construct( private readonly Image_Projection_Repository $rows, private readonly Image_Resource_Repository $resources ) {}

	/** Attach the callback and repair a missing schedule. */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		$this->schedule();
	}

	/** Start after verified additive DDL. */
	public function start(): void {
		$this->rows->begin();
		$this->schedule();
	}

	/** Remove pending work during lifecycle cleanup. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/** Cron callback. */
	public function run(): void {
		$this->run_batch();
	}

	/** Process at most 100 assignment inputs; failed reads/writes cannot advance the cursor. */
	public function run_batch(): int {
		$lock = $this->rows->claim();
		if ( '' === $lock ) {
			$this->schedule();
			return 0;
		}
		try {
			return $this->migrate_batch();
		} finally {
			$this->rows->release( $lock );
		}
	}

	/** Derive at most 100 snapshots while the cursor worker claim is held. */
	private function migrate_batch(): int {
		$state = $this->rows->state();
		if ( $state['done'] || ! $this->rows->available() ) {
			return 0;
		}
		$rows = $this->rows->after( $state['cursor'] );
		if ( is_wp_error( $rows ) ) {
			$this->rows->progress( $state['cursor'], false, 'read_failed' );
			$this->schedule();
			return 0;
		}
		$visited = 0;
		$cursor  = $state['cursor'];
		foreach ( $rows as $row ) {
			$fields = $this->resources->projection( (int) $row['revision_id'] );
			if ( 255 === $fields['image_version'] || ! $this->rows->write( $row, $fields ) ) {
				$this->rows->progress( $cursor, false, 'write_or_source_failed' );
				$this->schedule();
				return $visited;
			}
			$cursor = (int) $row['id'];
			if ( ! $this->rows->progress( $cursor, false ) ) {
				$this->schedule();
				return $visited;
			}
			++$visited;
		}
		$this->rows->progress( $cursor, count( $rows ) < 100 );
		if ( $this->rows->state()['done'] ) {
			wp_clear_scheduled_hook( self::HOOK );
		} else {
			$this->schedule();
		}
		return $visited;
	}

	/** The event is repaired after interruption as long as the additive schema is usable. */
	private function schedule(): void {
		if ( ! $this->rows->state()['done'] && $this->rows->available() && false === wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
		}
	}
}
