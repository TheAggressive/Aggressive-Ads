<?php
/**
 * Acting-as session lifetime rules.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Acting_Session;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * The boundary is the whole point: an expiry check that is wrong is almost
 * always wrong by exactly one second, in the direction of staying open.
 */
final class ActingSessionTest extends TestCase {

	/**
	 * A session runs for its lifetime from now.
	 *
	 * @return void
	 */
	public function test_a_session_expires_a_lifetime_from_now(): void {
		$this->assertSame(
			1_000_000 + Acting_Session::LIFETIME,
			Acting_Session::expires_at( 1_000_000 )
		);
	}

	/**
	 * A fresh session is live.
	 *
	 * @return void
	 */
	public function test_a_fresh_session_is_live(): void {
		$now = 1_000_000;

		$this->assertTrue(
			Acting_Session::is_live( Acting_Session::expires_at( $now ), $now )
		);
	}

	/**
	 * One second before expiry is still live.
	 *
	 * @return void
	 */
	public function test_the_second_before_expiry_is_live(): void {
		$this->assertTrue( Acting_Session::is_live( 1_000_001, 1_000_000 ) );
	}

	/**
	 * The expiry second itself has lapsed.
	 *
	 * Exclusive on purpose. Erring towards ended costs one re-entry; erring
	 * the other way leaves a session running past its window.
	 *
	 * @return void
	 */
	public function test_the_expiry_second_has_lapsed(): void {
		$this->assertFalse( Acting_Session::is_live( 1_000_000, 1_000_000 ) );
	}

	/**
	 * A past stamp has lapsed.
	 *
	 * @return void
	 */
	public function test_a_past_session_has_lapsed(): void {
		$this->assertFalse( Acting_Session::is_live( 999_999, 1_000_000 ) );
	}

	/**
	 * A missing stamp is not a live session.
	 *
	 * Absent meta reads as 0, and a rule that treated 0 as "no expiry set,
	 * therefore fine" would make every unset session permanent.
	 *
	 * @return void
	 */
	public function test_an_absent_stamp_is_not_live(): void {
		$this->assertFalse( Acting_Session::is_live( 0, 1_000_000 ) );
	}

	/**
	 * The lifetime is hours, not days.
	 *
	 * A forgotten session is the hazard this bounds, so the value itself is
	 * asserted rather than left to whatever a later edit makes it.
	 *
	 * @return void
	 */
	public function test_the_lifetime_is_bounded_to_hours(): void {
		$this->assertGreaterThanOrEqual( 60 * 60, Acting_Session::LIFETIME );
		$this->assertLessThanOrEqual( 12 * 60 * 60, Acting_Session::LIFETIME );
	}
}
