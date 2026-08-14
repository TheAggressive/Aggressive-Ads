<?php
/**
 * Front-of-site slot registration.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Placement_Slot;
use WP_Block_Type_Registry;
use WP_UnitTestCase;

/**
 * The placement block is always a native-delivery surface.
 */
final class PlacementSlotTest extends WP_UnitTestCase {

	/** Block padding wraps, rather than subtracts from, the native ad canvas. */
	public function test_block_reserves_the_declared_size_inside_its_outer_shell(): void {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'true-size-leaderboard',
			)
		);
		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$slot       = Plugin::instance()->container()->get( Placement_Slot::class );
		$shortcode  = $slot->shortcode( array( 'slot' => 'true-size-leaderboard' ) );
		$style_name = generate_block_asset_handle( Placement_Slot::BLOCK, 'style' );

		$this->assertStringContainsString( 'aggr-slot__canvas', $shortcode );
		$this->assertTrue( wp_style_is( $style_name, 'enqueued' ) );

		$html = do_blocks( '<!-- wp:aggr/placement {"slot":"true-size-leaderboard"} /-->' );

		$this->assertStringContainsString( 'display:grid;width:fit-content;max-width:100%', $html );
		$this->assertStringContainsString( 'class="aggr-slot__canvas"', $html );
		$this->assertStringContainsString( 'width:728px;max-width:100%;aspect-ratio:728/90;', $html );
		$this->assertStringNotContainsString( 'width:728px;min-height:90px;', $html );
	}

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
