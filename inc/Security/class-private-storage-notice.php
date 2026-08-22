<?php
/**
 * Surfaces a critical private-storage result outside Site Health.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Security;

use Aggressive\Ads\Core\Service;

/**
 * Raises an admin notice while unapproved creative is publicly reachable.
 *
 * Private_Storage_Health already proves whether the web server hands out
 * private creative bytes. It proves it on the Site Health screen, which is a
 * screen nobody opens on an ordinary day — so the answer to "is another
 * organization's unpublished artwork downloadable right now" was available and
 * unread. This service is the difference between detecting that and noticing
 * it.
 */
final class Private_Storage_Notice implements Service {

	/**
	 * Cron hook that refreshes the stored verdict.
	 */
	public const HOOK = 'aggr_verify_private_storage';

	/**
	 * How often the probe runs.
	 */
	public const RECURRENCE = 'daily';

	/**
	 * Option holding the last verdict.
	 */
	public const OPTION = 'aggr_private_storage_status';

	/**
	 * The one status worth interrupting somebody over.
	 */
	public const STATUS_CRITICAL = 'critical';

	/**
	 * Constructor.
	 *
	 * @param Private_Storage_Health $health Probe that answers the question.
	 */
	public function __construct( private readonly Private_Storage_Health $health ) {
	}

	/**
	 * Attaches the notice and keeps the probe scheduled.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( self::HOOK, array( $this, 'run' ) );
		add_action( 'init', array( $this, 'ensure_scheduled' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	/**
	 * Schedules the probe, repairing a drifted recurrence.
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
	 * Removes the scheduled probe.
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
		$this->refresh();
	}

	/**
	 * Re-runs the probe and stores the verdict.
	 *
	 * On cron rather than on `admin_notices`, because the probe is an HTTP
	 * request to this site with a three-second timeout. Running that inline
	 * would put three seconds on an arbitrary admin page load, and a security
	 * control that makes the admin feel broken is a control somebody disables.
	 *
	 * @return string The stored status.
	 */
	public function refresh(): string {
		$result = $this->health->run_test();
		$status = isset( $result['status'] ) && is_string( $result['status'] ) ? $result['status'] : '';

		update_option(
			self::OPTION,
			array(
				'status'     => $status,
				'checked_at' => time(),
			),
			false
		);

		return $status;
	}

	/**
	 * The last stored status, or '' when the probe has never run.
	 *
	 * @return string
	 */
	public function status(): string {
		$stored = get_option( self::OPTION );

		if ( ! is_array( $stored ) || ! isset( $stored['status'] ) || ! is_string( $stored['status'] ) ) {
			return '';
		}

		return $stored['status'];
	}

	/**
	 * Prints the notice while the last probe says critical.
	 *
	 * Deliberately not dismissible. Everything else here is advisory; this one
	 * means unreleased creative is being served to anyone who asks, and a
	 * dismiss button on that is a button that hides a live exposure until
	 * somebody happens to open Site Health.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( Capabilities::ACCESS_STAFF ) ) {
			return;
		}

		if ( self::STATUS_CRITICAL !== $this->status() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%1$s</strong></p><p>%2$s</p><p><a href="%3$s">%4$s</a></p></div>',
			esc_html__( 'Aggressive Ads: unapproved advertising creative is publicly accessible.', 'aggressive-ads' ),
			esc_html__( 'The web server returned a private-storage verification file directly, so anyone with the URL can download creative that has not been approved. Add a deny rule for the ads-uploads uploads directory.', 'aggressive-ads' ),
			esc_url( admin_url( 'site-health.php' ) ),
			esc_html__( 'Open Site Health for the exact rule', 'aggressive-ads' )
		);
	}
}
