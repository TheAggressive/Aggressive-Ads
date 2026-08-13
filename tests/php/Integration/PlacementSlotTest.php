<?php
/**
 * Front-of-site slot registration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Placement_Slot;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The placement block is always a native-delivery surface.
 */
final class PlacementSlotTest extends WP_UnitTestCase {

	/**
	 * Saving the old kill-switch off cannot unregister aggr/placement.
	 *
	 * @return void
	 */
	public function test_block_stays_registered_when_native_delivery_is_forced_off(): void {
		$settings = Plugin::instance()->container()->get( Settings::class );
		$slot     = Plugin::instance()->container()->get( Placement_Slot::class );
		$registry = WP_Block_Type_Registry::get_instance();

		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = false;
		$this->assertTrue( $settings->save( $document ) );
		$this->assertTrue( $settings->module_enabled( Settings_Schema::MODULE_NATIVE_DELIVERY ) );

		if ( $registry->is_registered( Placement_Slot::BLOCK ) ) {
			$registry->unregister( Placement_Slot::BLOCK );
		}

		$slot->register_block();
		$this->assertTrue( $registry->is_registered( Placement_Slot::BLOCK ) );
	}
}
