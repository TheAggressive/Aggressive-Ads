<?php
/**
 * Account-owned report preferences; no request may select another recipient.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\User_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Reporting_Read;
use Aggressive\Ads\Workflow\Scheduled_Reports;

/** Authenticated form handling and the personal scheduling read model. */
final class Report_Preference_Actions implements Service {

	public const SAVE_ACTION = 'aggr_save_report_preferences';

	/**
	 * Assemble personal preference handling and its read model.
	 *
	 * @param Scheduled_Reports $reports Preference workflow.
	 * @param Org_Repository    $organizations Current memberships.
	 * @param User_Repository   $users Current account.
	 * @param Reporting_Read    $reporting Gate and timezone.
	 */
	public function __construct(
		private readonly Scheduled_Reports $reports,
		private readonly Org_Repository $organizations,
		private readonly User_Repository $users,
		private readonly Reporting_Read $reporting
	) {}

	/** Register only the authenticated action. */
	public function init(): void {
		add_action( 'admin_post_' . self::SAVE_ACTION, array( $this, 'handle_save' ) );
	}

	/** Save the signed-in account's opt-in or opt-out, then return to Account. */
	public function handle_save(): void {
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::SAVE_ACTION );
		$cadence = isset( $_POST['cadence'] ) && is_string( $_POST['cadence'] ) ? sanitize_key( wp_unslash( $_POST['cadence'] ) ) : '';
		$result  = $this->reports->configure( $this->current_org(), get_current_user_id(), $cadence );
		$args    = is_wp_error( $result ) ? array(
			'report_notice' => 'error',
			'report_error'  => $result->get_error_code(),
		) : array( 'report_notice' => 'saved' );
		wp_safe_redirect( add_query_arg( $args, Routes::url( Request::ROUTE_ACCOUNT ) ) . '#aggr-report-emails' );
		exit;
	}

	/** Resolve membership from the session, never a submitted organization id. */
	private function current_org(): int {
		$ids = $this->organizations->org_ids_for_user( get_current_user_id() );
		return (int) ( $ids[0] ?? 0 );
	}

	/**
	 * Account view, without querying report results or running delivery work.
	 *
	 * @return array<string, mixed>|null
	 */
	public function view_data(): ?array {
		$org  = $this->current_org();
		$user = $this->users->by_id( get_current_user_id() );
		if ( $org <= 0 || null === $user || ! user_can( $user, Capabilities::ACCESS_PORTAL ) || ! $this->organizations->is_active( $org ) ) {
			return null;
		}
		$row = $this->reports->preferences( $org, (int) $user->ID );
		if ( is_wp_error( $row ) ) {
			return array( 'unavailable' => true );
		}
		$zone    = $this->reporting->timezone();
		$enabled = is_array( $row ) && (bool) $row['enabled'];
		$due     = $enabled ? (int) $row['due_at'] : 0;
		$status  = is_array( $row ) ? self::state_message( (string) $row['state'] ) : __( 'Off', 'aggressive-ads' );
		if ( ! $this->reporting->surfaces() ) {
			$status = __( 'Report emails are paused while reporting is turned off.', 'aggressive-ads' );
		} elseif ( $enabled && false === wp_next_scheduled( Scheduled_Reports::HOOK ) ) {
			$status = __( 'Report scheduling is unavailable. Please contact site staff.', 'aggressive-ads' );
		}

		return array(
			'unavailable' => false,
			'cadence'     => $enabled ? (string) $row['cadence'] : 'off',
			'org'         => $this->organizations->name( $org ),
			'email'       => (string) $user->user_email,
			'timezone'    => $zone,
			'next'        => $due > 0 ? wp_date( 'F j, Y g:i a T', $due, new \DateTimeZone( $zone ) ) : '',
			'status'      => $status,
			'last_queued' => is_array( $row ) && (int) $row['last_queued_at'] > 0 ? wp_date( 'F j, Y g:i a T', (int) $row['last_queued_at'], new \DateTimeZone( $zone ) ) : '',
		);
	}

	/**
	 * User-facing outcomes distinguish transport acceptance from inbox delivery.
	 *
	 * @param string $state Durable job state.
	 */
	private static function state_message( string $state ): string {
		return match ( $state ) {
			'idle' => __( 'Scheduled', 'aggressive-ads' ),
			'pending' => __( 'Preparing the report.', 'aggressive-ads' ),
			'sending' => __( 'Submitting to the mail service.', 'aggressive-ads' ),
			'queued' => __( 'Queued for delivery. Inbox delivery is not confirmed.', 'aggressive-ads' ),
			'retry' => __( 'Waiting to retry.', 'aggressive-ads' ),
			'failed' => __( 'The last report could not be queued. The next period remains scheduled.', 'aggressive-ads' ),
			'blocked' => __( 'Report emails stopped because account access changed.', 'aggressive-ads' ),
			'uncertain' => __( 'The last delivery could not be confirmed and will not be resent automatically.', 'aggressive-ads' ),
			default => __( 'Off', 'aggressive-ads' ),
		};
	}

	/** Safe PRG messages; codes and sentence content cannot be supplied by a URL. */
	public static function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only, allowlisted PRG display state.
		$notice = isset( $_GET['report_notice'] ) && is_string( $_GET['report_notice'] ) ? sanitize_key( wp_unslash( $_GET['report_notice'] ) ) : '';
		$code   = isset( $_GET['report_error'] ) && is_string( $_GET['report_error'] ) ? sanitize_key( wp_unslash( $_GET['report_error'] ) ) : '';
		// phpcs:enable
		if ( 'saved' === $notice ) {
			Portal_Notice::add( __( 'Your report email preferences were saved.', 'aggressive-ads' ), 'success' );
		} elseif ( 'error' === $notice ) {
			$message = match ( $code ) {
				'aggr_report_schedule_denied' => __( 'Report preferences are not available for this account.', 'aggressive-ads' ),
				'aggr_report_cadence_invalid' => __( 'Choose Off, Weekly, or Monthly.', 'aggressive-ads' ),
				'aggr_reporting_disabled' => __( 'Report emails are paused while reporting is turned off.', 'aggressive-ads' ),
				'aggr_report_schedule_busy' => __( 'Report preferences are busy. Please try again.', 'aggressive-ads' ),
				'aggr_report_schedule_unavailable' => __( 'Report preferences could not be loaded. Please try again.', 'aggressive-ads' ),
				default => __( 'Report preferences could not be saved. Please try again.', 'aggressive-ads' ),
			};
			Portal_Notice::add( $message, 'error' );
		}
	}
}
