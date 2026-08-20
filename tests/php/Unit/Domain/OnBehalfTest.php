<?php
/**
 * On-behalf determination.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\On_Behalf;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Membership decides, not capability.
 */
final class OnBehalfTest extends TestCase {

	/**
	 * Staff outside the organization are acting on its behalf.
	 *
	 * @return void
	 */
	public function test_staff_outside_the_organization_act_on_its_behalf(): void {
		$this->assertTrue( On_Behalf::applies( true, 7, array( 3 ) ) );
	}

	/**
	 * Staff inside it are editing their own work.
	 *
	 * @return void
	 */
	public function test_staff_inside_the_organization_are_not(): void {
		$this->assertFalse( On_Behalf::applies( true, 7, array( 3, 7 ) ) );
	}

	/**
	 * A non-staff actor never is, whatever their membership.
	 *
	 * @return void
	 */
	public function test_a_non_staff_actor_is_never_acting_on_behalf(): void {
		$this->assertFalse( On_Behalf::applies( false, 7, array() ) );
		$this->assertFalse( On_Behalf::applies( false, 7, array( 7 ) ) );
	}

	/**
	 * An object with no organization is not an on-behalf edit.
	 *
	 * Zero means unknown, and treating unknown as "not yours" would label
	 * every orphaned object as somebody's client work.
	 *
	 * @return void
	 */
	public function test_an_object_without_an_organization_is_not(): void {
		$this->assertFalse( On_Behalf::applies( true, 0, array() ) );
	}

	/**
	 * Membership is compared strictly.
	 *
	 * Ids arrive from meta as strings often enough that a loose comparison
	 * would pass here and fail against a real row.
	 *
	 * @return void
	 */
	public function test_membership_is_compared_strictly(): void {
		$this->assertTrue( On_Behalf::applies( true, 7, array( 3, 11 ) ) );
	}
}
