<?php
/**
 * Deletes private creative files once a campaign has been terminal long enough.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Lifecycle_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Storage\Private_Storage;

/**
 * Daily retention job for the private stage of two-stage creative storage.
 *
 * Campaign posts, Media Library attachments and checksum metadata stay. Only
 * the unguessable private bytes are removed, and only after the campaign has
 * been terminal for ninety days. See docs/domain-model.md.
 */
final class Creative_Retention implements Service {

	/**
	 * The cron hook.
	 */
	public const HOOK = 'aggr_purge_private_creatives';

	/**
	 * How often the sweep runs.
	 *
	 * Daily is enough: the retention window is measured in months, and a
	 * missed day is recovered by the next run.
	 */
	public const RECURRENCE = 'daily';

	/**
	 * How long after terminal relevance a private file may remain.
	 */
	/**
	 * Fallback window when no setting has been stored.
	 *
	 * Matches Domain\Settings_Schema's default. Kept as a constant so a sweep
	 * that runs before any staff member opens Settings still has a bound rather
	 * than deleting on day zero.
	 */
	public const RETENTION = 30 * DAY_IN_SECONDS;

	/**
	 * Campaigns examined per run.
	 */
	public const BATCH = 50;

	/**
	 * Constructor.
	 *
	 * @param Campaign_Lifecycle_Repository $lifecycle Batch campaign lookups.
	 * @param Campaign_Repository           $campaigns Campaign persistence.
	 * @param Creative_Repository           $creatives Creative persistence.
	 * @param Private_Storage               $storage   Private file storage.
	 * @param Audit_Repository              $audit     Audit persistence.
	 * @param Settings                      $settings  Stored configuration.
	 */
	public function __construct(
		private readonly Campaign_Lifecycle_Repository $lifecycle,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Private_Storage $storage,
		private readonly Audit_Repository $audit,
		private readonly Settings $settings
	) {
	}

	/**
	 * The configured window, in seconds.
	 *
	 * @return int
	 */
	private function window(): int {
		$days = $this->settings->creative_retention_days();

		return $days > 0 ? $days * DAY_IN_SECONDS : self::RETENTION;
	}

	/**
	 * Attaches the sweep and keeps it scheduled.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
	}

	/**
	 * Schedules the sweep, repairing a drifted recurrence.
	 *
	 * @return void
	 */
	public function ensure_scheduled(): void {
		$scheduled = wp_next_scheduled( self::HOOK );

		if ( false !== $scheduled && self::RECURRENCE === wp_get_schedule( self::HOOK ) ) {
			return;
		}

		if ( false !== $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}

		wp_schedule_event( time() + HOUR_IN_SECONDS, self::RECURRENCE, self::HOOK );
	}

	/**
	 * Removes the scheduled sweep.
	 *
	 * @return void
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * The cron callback.
	 *
	 * @return void
	 */
	public function run(): void {
		$this->purge();
	}

	/**
	 * One sweep.
	 *
	 * @return int How many private files were removed.
	 */
	public function purge(): int {
		$cutoff = time() - $this->window();
		$purged = $this->purge_promoted();

		foreach ( $this->lifecycle->ids_terminal_before( $cutoff, self::BATCH ) as $campaign_id ) {
			$purged += $this->purge_campaign( $campaign_id );
		}

		return $purged;
	}

	/**
	 * Originals whose public copy already exists.
	 *
	 * Deliberately not governed by the retention window, because this is not a
	 * question about age. Once a creative is promoted, the Media Library
	 * attachment is what delivery serves and what Campaign_Copier falls back
	 * to, so the private original has no reader left — waiting thirty days to
	 * delete something nothing reads is a window with no purpose.
	 *
	 * Creative_Promoter already does this at the moment of promotion. This is
	 * the sweep for anything promoted before it did, and the reason the
	 * invariant is enforced continuously rather than assumed after one
	 * migration: a future path that promotes without purging gets caught here
	 * on the next run instead of never.
	 *
	 * @return int Files removed.
	 */
	public function purge_promoted(): int {
		$removed = 0;

		foreach ( $this->creatives->ids_promoted_with_private_file( self::BATCH ) as $creative_id ) {
			$details = $this->creatives->storage_details( (int) $creative_id );

			if ( null === $details || '' === $details['path'] ) {
				continue;
			}

			if ( null !== $this->storage->resolve( $details['path'] ) && ! $this->storage->delete( $details['path'] ) ) {
				continue;
			}

			$this->creatives->clear_private_file( (int) $creative_id );
			++$removed;
		}

		return $removed;
	}

	/**
	 * Deletes every remaining private file for one terminal campaign.
	 *
	 * Idempotent: a creative with no path is a no-op, and a missing file still
	 * clears the pointer so the next sweep does not keep rediscovering it.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int Files removed (including already-missing paths that were cleared).
	 */
	public function purge_campaign( int $campaign_id ): int {
		if ( ! $this->campaigns->exists( $campaign_id ) ) {
			return 0;
		}

		$removed = 0;
		$failed  = 0;

		foreach ( $this->creatives->ids_for_campaign( $campaign_id ) as $creative_id ) {
			$details = $this->creatives->storage_details( $creative_id );

			if ( null === $details || '' === $details['path'] ) {
				continue;
			}

			$path = $this->storage->resolve( $details['path'] );

			if ( null !== $path && ! $this->storage->delete( $details['path'] ) ) {
				++$failed;

				continue;
			}

			$this->creatives->clear_private_file( $creative_id );
			++$removed;
		}

		if ( 0 === $removed && 0 === $failed ) {
			return 0;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.private_files_purged',
				outcome: 0 === $failed ? Audit_Event::OUTCOME_OK : Audit_Event::OUTCOME_FAILED,
				object_type: 'campaign',
				object_id: $campaign_id,
				org_id: $this->campaigns->org_id( $campaign_id ),
				message: 0 === $failed
					? sprintf( '%d private creative file(s) purged.', $removed )
					: sprintf( '%d private creative file(s) purged; %d could not be deleted.', $removed, $failed ),
				context: array(
					'purged' => $removed,
					'failed' => $failed,
				)
			)
		);

		return $removed;
	}
}
