<?php
/**
 * The admin notice that surfaces a critical private-storage verdict.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Private_Storage_Notice;
use WP_UnitTestCase;

/**
 * Site Health answers the question; this proves somebody is told the answer.
 */
final class PrivateStorageNoticeTest extends WP_UnitTestCase {

	/**
	 * Subject under test.
	 *
	 * @var Private_Storage_Notice
	 */
	private Private_Storage_Notice $notice;

	/**
	 * An account holding a staff capability.
	 *
	 * @var int
	 */
	private int $staff;

	/**
	 * An advertiser, who must never see server configuration advice.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Builds the fixtures.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->notice = Plugin::instance()->container()->get( Private_Storage_Notice::class );

		$this->staff = self::factory()->user->create( array( 'role' => 'administrator' ) );

		$this->advertiser = self::factory()->user->create( array( 'role' => 'subscriber' ) );
		$advertiser       = get_user_by( 'id', $this->advertiser );

		if ( $advertiser instanceof \WP_User ) {
			$advertiser->add_cap( Capabilities::ACCESS_PORTAL );
		}
	}

	/**
	 * Leaves no verdict or schedule behind for the next test.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Private_Storage_Notice::OPTION );
		Private_Storage_Notice::unschedule();

		parent::tear_down();
	}

	/**
	 * Captures whatever the notice prints.
	 *
	 * @return string
	 */
	private function rendered(): string {
		ob_start();
		$this->notice->render();

		return (string) ob_get_clean();
	}

	/**
	 * Stores a verdict without running the HTTP probe.
	 *
	 * @param string $status Site Health status.
	 * @return void
	 */
	private function store( string $status ): void {
		update_option(
			Private_Storage_Notice::OPTION,
			array(
				'status'     => $status,
				'checked_at' => time(),
			),
			false
		);
	}

	/**
	 * The whole point: a critical verdict reaches an admin screen.
	 *
	 * @return void
	 */
	public function test_a_critical_verdict_prints_a_notice_for_staff(): void {
		wp_set_current_user( $this->staff );
		$this->store( Private_Storage_Notice::STATUS_CRITICAL );

		$html = $this->rendered();

		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( 'publicly accessible', $html );
	}

	/**
	 * A healthy site is not nagged.
	 *
	 * @return void
	 */
	public function test_a_good_verdict_prints_nothing(): void {
		wp_set_current_user( $this->staff );
		$this->store( 'good' );

		$this->assertSame( '', $this->rendered() );
	}

	/**
	 * Before the probe has ever run there is nothing to report.
	 *
	 * Silence rather than a warning: an unconfigured install has not been shown
	 * to be leaking, and crying wolf on first activation is how the real
	 * warning gets ignored later.
	 *
	 * @return void
	 */
	public function test_no_stored_verdict_prints_nothing(): void {
		wp_set_current_user( $this->staff );
		delete_option( Private_Storage_Notice::OPTION );

		$this->assertSame( '', $this->rendered() );
	}

	/**
	 * Advertisers are not told how the server is misconfigured.
	 *
	 * The notice names a directory and links to Site Health. That is a map for
	 * somebody who should not have one, and the tenant it would leak belongs to
	 * a different organization.
	 *
	 * @return void
	 */
	public function test_an_advertiser_is_told_nothing(): void {
		wp_set_current_user( $this->advertiser );
		$this->store( Private_Storage_Notice::STATUS_CRITICAL );

		$this->assertSame( '', $this->rendered() );
	}

	/**
	 * A logged-out visitor is told nothing.
	 *
	 * @return void
	 */
	public function test_a_logged_out_visitor_is_told_nothing(): void {
		wp_set_current_user( 0 );
		$this->store( Private_Storage_Notice::STATUS_CRITICAL );

		$this->assertSame( '', $this->rendered() );
	}

	/**
	 * The probe is scheduled, and a drifted recurrence is repaired.
	 *
	 * @return void
	 */
	public function test_the_probe_schedule_is_repaired_when_it_drifts(): void {
		Private_Storage_Notice::unschedule();
		wp_schedule_event( time() + 60, 'hourly', Private_Storage_Notice::HOOK );

		$this->notice->ensure_scheduled();

		$this->assertSame( Private_Storage_Notice::RECURRENCE, wp_get_schedule( Private_Storage_Notice::HOOK ) );
	}

	/**
	 * The stored verdict survives a round trip through the option.
	 *
	 * @return void
	 */
	public function test_status_reads_back_what_refresh_stored(): void {
		$this->store( Private_Storage_Notice::STATUS_CRITICAL );

		$this->assertSame( Private_Storage_Notice::STATUS_CRITICAL, $this->notice->status() );
	}

	/**
	 * A malformed option does not become a status.
	 *
	 * @return void
	 */
	public function test_a_malformed_option_reads_as_no_verdict(): void {
		update_option( Private_Storage_Notice::OPTION, 'critical', false );

		$this->assertSame( '', $this->notice->status() );
	}
}
