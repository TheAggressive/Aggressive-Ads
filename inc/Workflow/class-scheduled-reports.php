<?php
/**
 * Opt-in report preferences and bounded durable delivery processing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Domain\Report_Cadence;
use Aggressive\Ads\Notification\Scheduled_Report_Mailer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Report_Subscription_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;

/** Session locks prevent overlap; persisted sending states survive process loss. */
final class Scheduled_Reports implements Service {

	public const HOOK         = 'aggr_send_scheduled_reports';
	public const RECURRENCE   = 'aggr_report_five_minutes';
	public const MAX_PER_RUN  = 20;
	public const MAX_ATTEMPTS = 4;

	/**
	 * Compose the worker without performing delivery during registration.
	 *
	 * @param Report_Subscription_Repository $subscriptions Preferences and jobs.
	 * @param Org_Repository                 $organizations Membership and active state.
	 * @param User_Repository                $users Current recipient accounts.
	 * @param Reporting_Read                 $reporting Reporting gate/timezone.
	 * @param Scheduled_Report_Mailer        $mailer Shared presentation and transport.
	 * @param Audit_Repository               $audit Outcome evidence without email contents.
	 */
	public function __construct(
		private readonly Report_Subscription_Repository $subscriptions,
		private readonly Org_Repository $organizations,
		private readonly User_Repository $users,
		private readonly Reporting_Read $reporting,
		private readonly Scheduled_Report_Mailer $mailer,
		private readonly Audit_Repository $audit
	) {}

	/** Attach the worker, schedule repair, and authoritative deletion cleanup. */
	public function init(): void {
		// phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- Five-minute polling supports the first bounded retry; paused and empty workers do no report work.
		add_filter( 'cron_schedules', array( $this, 'recurrences' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( self::HOOK, array( $this, 'run_scheduled' ) );
		add_action( 'deleted_user', array( $this, 'deleted_user' ) );
		add_action( 'deleted_post', array( $this, 'deleted_post' ), 10, 2 );
	}

	/**
	 * Poll retry cursors at the shortest supported backoff interval.
	 *
	 * @param array<string, array<string, mixed>> $schedules Existing intervals.
	 * @return array<string, array<string, mixed>>
	 */
	public function recurrences( array $schedules ): array {
		$schedules[ self::RECURRENCE ] = array(
			'interval' => 5 * MINUTE_IN_SECONDS, // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- The first bounded retry is due after five minutes; empty/paused workers do no report work.
			'display'  => __( 'Report delivery every five minutes', 'aggressive-ads' ),
		);
		return $schedules;
	}

	/** Repair missing/wrong recurrence without querying preferences on public reads. */
	public function ensure_scheduled(): void {
		if ( false !== wp_next_scheduled( self::HOOK ) && self::RECURRENCE === wp_get_schedule( self::HOOK ) ) {
			return;
		}
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + 5 * MINUTE_IN_SECONDS, self::RECURRENCE, self::HOOK );
	}

	/** Uninstall/deactivation adapter. */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Remove a deleted account's subscriptions.
	 *
	 * @param int $id Deleted account.
	 */
	public function deleted_user( int $id ): void {
		$this->subscriptions->delete_for( 'user_id', $id );
	}

	/**
	 * Remove subscriptions belonging to a deleted organization.
	 *
	 * @param int      $id Deleted post.
	 * @param \WP_Post $post Deleted record.
	 */
	public function deleted_post( int $id, \WP_Post $post ): void {
		if ( \Aggressive\Ads\Core\Post_Types::ORGANIZATION === $post->post_type ) {
			$this->subscriptions->delete_for( 'org_id', $id );
		}
	}

	/**
	 * Save only the subscribing account's own organization preference.
	 *
	 * @param int      $org_id Server-resolved organization.
	 * @param int      $user_id Authenticated subscriber.
	 * @param string   $cadence Off, weekly, or monthly.
	 * @param int|null $now Testable clock; production uses time().
	 * @return true|\WP_Error
	 */
	public function configure( int $org_id, int $user_id, string $cadence, ?int $now = null ): true|\WP_Error {
		if ( null === $this->recipient( $org_id, $user_id ) ) {
			return new \WP_Error( 'aggr_report_schedule_denied', __( 'Report preferences are not available for this account.', 'aggressive-ads' ) );
		}
		if ( ! in_array( $cadence, array( 'off', Report_Cadence::WEEKLY, Report_Cadence::MONTHLY ), true ) ) {
			return new \WP_Error( 'aggr_report_cadence_invalid', __( 'Choose Off, Weekly, or Monthly.', 'aggressive-ads' ) );
		}
		if ( 'off' !== $cadence && ! $this->reporting->surfaces() ) {
			return new \WP_Error( 'aggr_reporting_disabled', __( 'Report emails are paused while reporting is turned off.', 'aggressive-ads' ) );
		}
		$scope = $org_id . ':' . $user_id;
		if ( ! $this->subscriptions->acquire( $scope ) ) {
			return new \WP_Error( 'aggr_report_schedule_busy', __( 'Report preferences are busy. Please try again.', 'aggressive-ads' ) );
		}
		try {
			if ( null === $this->recipient( $org_id, $user_id ) ) {
				return new \WP_Error( 'aggr_report_schedule_denied', __( 'Report preferences are not available for this account.', 'aggressive-ads' ) );
			}
			$before = $this->subscriptions->for_member( $org_id, $user_id );
			if ( is_wp_error( $before ) ) {
				return $before;
			}
			$zone = $this->reporting->timezone();
			if ( null === $before && 'off' === $cadence ) {
				return true;
			}
			$enabled = 'off' !== $cadence;
			if ( is_array( $before ) && (string) $before['cadence'] === $cadence && (string) $before['timezone'] === $zone && (bool) $before['enabled'] === $enabled ) {
				return true;
			}
			if ( ! $this->subscriptions->save( $org_id, $user_id, $cadence, $zone, Report_Cadence::next_due( $cadence, $now ?? time(), $zone ) ) ) {
				return new \WP_Error( 'aggr_report_schedule_save_failed', __( 'Report preferences could not be saved. Please try again.', 'aggressive-ads' ) );
			}
			$row = $this->subscriptions->for_member( $org_id, $user_id );
			if ( ! is_array( $row ) || (string) $row['cadence'] !== $cadence || (string) $row['timezone'] !== $zone || (bool) $row['enabled'] !== $enabled ) {
				return new \WP_Error( 'aggr_report_schedule_save_failed', __( 'Report preferences could not be saved. Please try again.', 'aggressive-ads' ) );
			}
			$this->record( $row, 'subscription_changed', 'off' === $cadence ? 'disabled' : 'idle', $user_id );
			return true;
		} finally {
			$this->subscriptions->release( $scope );
		}
	}

	/**
	 * Read preferences only for a current member, even when reporting is paused.
	 *
	 * @param int $org_id Server-resolved organization.
	 * @param int $user_id Authenticated subscriber.
	 * @return array<string, mixed>|\WP_Error|null
	 */
	public function preferences( int $org_id, int $user_id ): array|\WP_Error|null {
		return null === $this->recipient( $org_id, $user_id ) ? null : $this->subscriptions->for_member( $org_id, $user_id );
	}

	/** WordPress action adapter. */
	public function run_scheduled(): void {
		$this->run();
	}

	/**
	 * Bounded worker; no report is sent merely by viewing a page.
	 *
	 * @param int|null $now Testable clock.
	 * @return int Messages accepted and recorded as queued.
	 */
	public function run( ?int $now = null ): int {
		if ( ! $this->reporting->surfaces() || ! $this->subscriptions->acquire( 'worker' ) ) {
			return 0;
		}
		$now    = $now ?? time();
		$start  = hrtime( true );
		$queued = 0;
		try {
			foreach ( $this->subscriptions->due_ids( $now, self::MAX_PER_RUN ) as $id ) {
				if ( hrtime( true ) - $start >= 20_000_000_000 ) {
					break;
				}
				$row = $this->subscriptions->find( $id );
				if ( null === $row ) {
					continue;
				}
				$scope = $row['org_id'] . ':' . $row['user_id'];
				if ( ! $this->subscriptions->acquire( $scope ) ) {
					continue;
				}
				try {
					$row = $this->subscriptions->find( $id );
					if ( null !== $row && (bool) $row['enabled'] && (int) $row['due_at'] <= $now && $this->process( $row, $now ) ) {
						++$queued;
					}
				} finally {
					$this->subscriptions->release( $scope );
				}
			}
		} finally {
			$this->subscriptions->release( 'worker' );
		}
		return $queued;
	}

	/**
	 * Fresh authorization: stored schedule ids alone confer no access.
	 *
	 * @param int $org_id Organization.
	 * @param int $user_id Subscriber.
	 * @phpstan-impure Reads mutable membership and capability state again after locking or report generation.
	 */
	private function recipient( int $org_id, int $user_id ): ?\WP_User {
		if ( $org_id <= 0 || $user_id <= 0 ) {
			return null;
		}
		// Another request's revocation cannot evict this process's local cache.
		clean_user_cache( $user_id );
		clean_post_cache( $org_id );
		$user = $this->users->by_id( $user_id );
		if ( null === $user || ! user_can( $user, Capabilities::ACCESS_PORTAL ) || ! is_email( $user->user_email )
			|| ! $this->organizations->is_active( $org_id ) || ! in_array( $user_id, $this->organizations->user_ids_for_org( $org_id ), true ) ) {
			return null;
		}
		return $user;
	}

	/**
	 * Process one locked subscription, preserving an explicit ambiguous-send state.
	 *
	 * @param array<string, mixed> $row Locked row.
	 * @param int                  $now Worker clock.
	 */
	private function process( array $row, int $now ): bool {
		$org  = (int) $row['org_id'];
		$uid  = (int) $row['user_id'];
		$user = $this->recipient( $org, $uid );
		$zone = $this->reporting->timezone();
		$next = Report_Cadence::next_due( (string) $row['cadence'], $now, $zone );
		if ( null === $user || 0 === $next ) {
			$this->finish( $row, 'blocked', 'recipient_unavailable', 0, false );
			return false;
		}
		if ( (string) $row['timezone'] !== $zone ) {
			$state = 'sending' === $row['state'] ? 'uncertain' : 'idle';
			if ( $this->subscriptions->transition(
				$row,
				array(
					'timezone'     => $zone,
					'state'        => $state,
					'period_start' => '',
					'period_end'   => '',
					'attempts'     => 0,
					'due_at'       => $next,
					'last_error'   => 'timezone_changed',
				)
			) ) {
				$this->record( $row, 'rescheduled', $state );
			}
			return false;
		}
		if ( 'sending' === $row['state'] ) {
			$this->finish( $row, 'uncertain', 'send_interrupted', $next );
			return false;
		}
		$period = Report_Cadence::period( (string) $row['cadence'], $now, $zone );
		if ( null === $period ) {
			$this->finish( $row, 'blocked', 'invalid_cadence', 0, false );
			return false;
		}
		$same = $period->start === $row['period_start'] && $period->end === $row['period_end'];
		if ( $same && in_array( $row['state'], array( 'queued', 'failed', 'uncertain' ), true ) ) {
			$this->subscriptions->transition( $row, array( 'due_at' => $next ) );
			return false;
		}
		$attempt = $same ? (int) $row['attempts'] + 1 : 1;
		if ( $attempt > self::MAX_ATTEMPTS ) {
			$this->finish( $row, 'failed', 'preparation_interrupted', $next );
			return false;
		}
		if ( ! $this->subscriptions->transition(
			$row,
			array(
				'state'        => 'pending',
				'period_start' => $period->start,
				'period_end'   => $period->end,
				'attempts'     => $attempt,
				'due_at'       => $now,
				'last_error'   => '',
			)
		) ) {
			return false;
		}
		$row = $this->subscriptions->find( (int) $row['id'] );
		if ( null === $row ) {
			return false;
		}
		$switched = switch_to_user_locale( $uid );
		try {
			$message = $this->mailer->message( $org, $period, $now );
			if ( is_wp_error( $message ) ) {
				$this->retry_or_finish( $row, 'report_read_failed', $now, $next );
				return false;
			}
			$user = $this->recipient( $org, $uid );
			if ( null === $user ) {
				$this->finish( $row, 'blocked', 'recipient_unavailable', 0, false );
				return false;
			}
			if ( ! $this->reporting->surfaces() || $this->reporting->timezone() !== $zone ) {
				return false;
			}
			if ( ! $this->subscriptions->transition( $row, array( 'state' => 'sending' ) ) ) {
				return false;
			}
			$row['state'] = 'sending';
			$result       = $this->mailer->send( (string) $user->user_email, $message );
			if ( 'failed' === $result ) {
				$this->retry_or_finish( $row, 'transport_failed', $now, $next );
				return false;
			}
			if ( 'uncertain' === $result ) {
				$this->finish( $row, 'uncertain', 'transport_uncertain', $next );
				return false;
			}
			$stored = $this->subscriptions->transition(
				$row,
				array(
					'state'          => 'queued',
					'due_at'         => $next,
					'last_queued_at' => $now,
					'last_error'     => '',
				)
			);
			if ( $stored ) {
				$this->record( $row, 'queued', 'queued' );
			}
			return $stored;
		} finally {
			if ( $switched ) {
				restore_previous_locale();
			}
		}
	}

	/**
	 * Retry explicit failure on the bounded backoff curve.
	 *
	 * @param array<string, mixed> $row Attempt snapshot.
	 * @param string               $reason Allowlisted failure class.
	 * @param int                  $now Current time.
	 * @param int                  $next Next period's release.
	 */
	private function retry_or_finish( array $row, string $reason, int $now, int $next ): void {
		$delay = match ( (int) $row['attempts'] ) {
			1 => 5 * MINUTE_IN_SECONDS, 2 => 30 * MINUTE_IN_SECONDS, 3 => 2 * HOUR_IN_SECONDS, default => 0 };
		$this->finish( $row, $delay > 0 ? 'retry' : 'failed', $reason, $delay > 0 ? $now + $delay : $next );
	}

	/**
	 * Persist an outcome before it is presented as completed.
	 *
	 * @param array<string, mixed> $row Expected state.
	 * @param string               $state Next state.
	 * @param string               $reason Safe error code.
	 * @param int                  $due Next action.
	 * @param bool                 $enabled Whether future periods remain enabled.
	 */
	private function finish( array $row, string $state, string $reason, int $due, bool $enabled = true ): void {
		if ( $this->subscriptions->transition(
			$row,
			array(
				'state'      => $state,
				'last_error' => $reason,
				'due_at'     => $due,
				'enabled'    => $enabled ? 1 : 0,
			)
		) ) {
			$this->record( $row, $state, $reason );
		}
	}

	/**
	 * Record ids and outcome codes without report contents or addresses.
	 *
	 * @param array<string, mixed> $row Subscription snapshot.
	 * @param string               $event Outcome event.
	 * @param string               $reason Safe code, never transport text or report values.
	 * @param int                  $actor Preference actor, or system.
	 */
	private function record( array $row, string $event, string $reason, int $actor = 0 ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'report.' . $event,
				outcome: in_array( $event, array( 'failed', 'uncertain', 'retry', 'blocked' ), true ) ? Audit_Event::OUTCOME_FAILED : Audit_Event::OUTCOME_OK,
				object_type: 'report_subscription',
				object_id: (int) $row['id'],
				org_id: (int) $row['org_id'],
				actor_user_id: $actor,
				message: 'Scheduled report state changed.',
				context: array(
					'recipient_id' => (int) $row['user_id'],
					'cadence'      => (string) $row['cadence'],
					'period_start' => (string) $row['period_start'],
					'period_end'   => (string) $row['period_end'],
					'attempt'      => (int) $row['attempts'],
					'reason'       => $reason,
				)
			)
		);
	}
}
