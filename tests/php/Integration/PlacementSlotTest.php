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

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"true-size-leaderboard"} /-->' );

		$this->assertStringContainsString( 'display:grid;width:fit-content;max-width:100%', $html );
		$this->assertStringContainsString( 'class="aggr-slot__canvas"', $html );
		$this->assertStringContainsString( 'width:728px;max-width:100%;aspect-ratio:728/90;', $html );
		$this->assertStringNotContainsString( 'width:728px;min-height:90px;', $html );
	}

	/**
	 * **Content saved under the old block name still renders.**
	 *
	 * The block name is serialized into `post_content`, so every post, template
	 * and reusable block carrying `wp:aggr/placement` would show "This block
	 * contains unexpected or invalid content" if the rename simply dropped the
	 * old registration. Nothing rewrites anybody's content; the alias is what
	 * makes that safe.
	 *
	 * @return void
	 */
	public function test_the_pre_rename_block_name_still_renders(): void {
		$placement_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'legacy-leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$legacy = do_blocks( '<!-- wp:aggr/placement {"slot":"legacy-leaderboard"} /-->' );
		$fresh  = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"legacy-leaderboard"} /-->' );

		$this->assertStringContainsString( 'class="aggr-slot__canvas"', $legacy, 'Old content stopped rendering.' );
		$this->assertStringContainsString( 'data-aggr-slot="legacy-leaderboard"', $legacy );

		/*
		 * Identical but for the class WordPress derives from the block name.
		 *
		 * `get_block_wrapper_attributes()` emits `wp-block-aggr-placement` for
		 * the old name and `wp-block-aggr-ad-slot` for the new one, and that
		 * difference is unavoidable without rewriting content. It is also
		 * harmless: every style rule keys off `.aggr-slot`, which both carry.
		 * Asserted by normalising exactly that one token rather than by
		 * loosening the comparison, so any *other* divergence still fails.
		 */
		$normalised = str_replace( 'wp-block-aggr-placement', 'wp-block-aggr-ad-slot', $legacy );

		$this->assertSame( $fresh, $normalised );
		$this->assertStringContainsString( 'aggr-slot', $legacy, 'The styling hook must survive the rename.' );
	}

	/**
	 * The rotation attributes reach the client as interactivity context.
	 *
	 * @return void
	 */
	public function test_rotation_reaches_the_client_as_context(): void {
		$placement_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'rotating-leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"rotating-leaderboard","rotate":true,"rotateSeconds":45} /-->' );

		$this->assertStringContainsString( 'data-wp-interactive="aggr/ad-slot"', $html );
		$this->assertStringContainsString( 'data-wp-init="callbacks.fill"', $html );
		$this->assertStringContainsString( '&quot;rotate&quot;:true', $html );
		$this->assertStringContainsString( '&quot;rotateSeconds&quot;:45', $html );
	}

	/**
	 * **An interval below the floor is raised, not honoured.**
	 *
	 * The floor is one second, so this is about zero and negatives rather than
	 * about pacing: an interval of no length is a timer that fires as fast as
	 * the browser will let it. A block comment can be hand-edited to say
	 * anything, so the server floors it rather than trusting the editor control
	 * to have done so.
	 *
	 * @return void
	 */
	public function test_an_interval_below_the_floor_is_raised(): void {
		$placement_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'fast-leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"fast-leaderboard","rotate":true,"rotateSeconds":0} /-->' );

		$this->assertStringContainsString(
			'&quot;rotateSeconds&quot;:' . Placement_Slot::MIN_ROTATE_SECONDS,
			$html
		);
		$this->assertStringNotContainsString( '&quot;rotateSeconds&quot;:0', $html );

		// And a legal short interval is passed straight through, so the floor is
		// a boundary rather than a blanket rewrite.
		$this->assertStringContainsString(
			'&quot;rotateSeconds&quot;:2',
			do_blocks( '<!-- wp:aggr/ad-slot {"slot":"fast-leaderboard","rotate":true,"rotateSeconds":2} /-->' )
		);
	}

	/**
	 * A slot that did not ask to rotate says so, rather than omitting the key.
	 *
	 * The store reads `context.rotate`; an absent key and a false one behave the
	 * same in JavaScript, which is exactly the kind of agreement that stops
	 * being true when somebody refactors the store.
	 *
	 * @return void
	 */
	public function test_a_static_slot_declares_that_it_does_not_rotate(): void {
		$placement_id = self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'static-leaderboard',
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"static-leaderboard"} /-->' );

		$this->assertStringContainsString( '&quot;rotate&quot;:false', $html );
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
