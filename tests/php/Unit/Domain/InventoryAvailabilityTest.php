<?php
/**
 * Public availability vocabulary never implies a promised quantity.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Inventory_Availability;
use PHPUnit\Framework\TestCase;

final class InventoryAvailabilityTest extends TestCase {

	/** A missing or uncertain assessment cannot be available or sold out. */
	public function test_unknown_and_uncertain_inventory_require_confirmation(): void {
		$this->assertSame( 'limited', Inventory_Availability::placement_state( null, 0, false ) );
		$this->assertSame( 'limited', Inventory_Availability::placement_state( 100, 0, true ) );
		$this->assertSame( 'limited', Inventory_Availability::placement_state( 0, 100, true ) );
	}

	/** The last remaining opportunity and the sold-out boundary are distinct. */
	public function test_known_headroom_uses_the_native_availability_rule(): void {
		$this->assertSame( 'available', Inventory_Availability::placement_state( 100, 99, false ) );
		$this->assertSame( 'unavailable', Inventory_Availability::placement_state( 100, 100, false ) );
		$this->assertSame( 'unavailable', Inventory_Availability::placement_state( 100, 101, false ) );
		$this->assertSame( 'unavailable', Inventory_Availability::placement_state( 0, 0, false ) );
	}

	/** A package needs every included placement; no empty set implies availability. */
	public function test_packages_combine_placement_answers_without_summing_inventory(): void {
		$this->assertSame( 'limited', Inventory_Availability::package_state( array() ) );
		$this->assertSame(
			'available',
			Inventory_Availability::package_state(
				array(
					4 => 'available',
					9 => 'available',
				)
			)
		);
		$this->assertSame( 'limited', Inventory_Availability::package_state( array( 'available', 'limited' ) ) );
		$this->assertSame( 'unavailable', Inventory_Availability::package_state( array( 'limited', 'unavailable' ) ) );
		$this->assertSame( 'limited', Inventory_Availability::package_state( array( 'invalid' ) ) );
	}
}
