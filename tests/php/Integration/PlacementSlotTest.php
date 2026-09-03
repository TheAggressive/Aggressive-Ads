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
use Aggressive\Ads\Domain\Slot_Options;
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
			'&quot;rotateSeconds&quot;:' . Slot_Options::MIN_ROTATE_SECONDS,
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
	 * A slot keeps its space only when the block asked it to.
	 *
	 * Collapsing is what the block does; holding the box open is the choice.
	 * Both halves are asserted on the same placement, because the failure that
	 * matters is not "the attribute did nothing" — it is "the attribute did
	 * something to every slot", which a test of the opt-in alone cannot see.
	 *
	 * @return void
	 */
	public function test_only_a_slot_that_asked_keeps_its_space_when_unsold(): void {
		$this->place( 'reserved-leaderboard' );

		$this->assertStringContainsString(
			'&quot;collapseWhenEmpty&quot;:false',
			do_blocks( '<!-- wp:aggr/ad-slot {"slot":"reserved-leaderboard","collapseWhenEmpty":false} /-->' ),
			'A slot that asked to keep its space did not tell the client.'
		);

		$this->assertStringContainsString(
			'&quot;collapseWhenEmpty&quot;:true',
			do_blocks( '<!-- wp:aggr/ad-slot {"slot":"reserved-leaderboard","collapseWhenEmpty":true} /-->' )
		);
	}

	/**
	 * **Content saved before the attribute existed still collapses.**
	 *
	 * Every ad slot already in somebody's `post_content` was written without
	 * `collapseWhenEmpty`, under both the current block name and the pre-1.6.0
	 * one. If an absent attribute read as "keep the space", the upgrade would
	 * put an empty box on every unsold slot on every one of those pages, and
	 * nothing would error.
	 *
	 * @return void
	 */
	public function test_a_slot_saved_before_the_attribute_existed_still_collapses(): void {
		$this->place( 'inherited-leaderboard' );

		foreach ( array( 'aggr/ad-slot', 'aggr/placement' ) as $block ) {
			$html = do_blocks( '<!-- wp:' . $block . ' {"slot":"inherited-leaderboard"} /-->' );

			$this->assertStringContainsString(
				'&quot;collapseWhenEmpty&quot;:true',
				$html,
				$block . ' stopped collapsing when unsold.'
			);
		}
	}

	/**
	 * The shortcode and the template helper reach the same three settings.
	 *
	 * A publisher moving a slot out of post content and into a template is the
	 * person most likely to want the space held — a template region is fixed
	 * layout — so the surface without an editor UI is the one that must not
	 * quietly ignore the attribute.
	 *
	 * @return void
	 */
	public function test_the_shortcode_and_helper_carry_the_same_settings(): void {
		$this->place( 'helper-leaderboard' );

		$shortcode = do_shortcode(
			'[aggr_placement slot="helper-leaderboard" rotate="true" rotate_seconds="20" collapse_when_empty="false"]'
		);

		$this->assertStringContainsString( '&quot;rotate&quot;:true', $shortcode );
		$this->assertStringContainsString( '&quot;rotateSeconds&quot;:20', $shortcode );
		$this->assertStringContainsString( '&quot;collapseWhenEmpty&quot;:false', $shortcode );

		ob_start();
		aggr_placement(
			'helper-leaderboard',
			array(
				'rotate'              => true,
				'rotate_seconds'      => 20,
				'collapse_when_empty' => false,
			)
		);
		$helper = (string) ob_get_clean();

		$this->assertStringContainsString( '&quot;collapseWhenEmpty&quot;:false', $helper );
		$this->assertStringContainsString( '&quot;rotateSeconds&quot;:20', $helper );

		// A bare shortcode and a bare helper call are the shipped defaults, not
		// three settings turned on by the empty strings shortcode_atts() fills
		// unwritten attributes with.
		$bare = do_shortcode( '[aggr_placement slot="helper-leaderboard"]' );

		$this->assertStringContainsString( '&quot;rotate&quot;:false', $bare );
		$this->assertStringContainsString( '&quot;collapseWhenEmpty&quot;:true', $bare );
		$this->assertStringContainsString(
			'&quot;rotateSeconds&quot;:' . Slot_Options::DEFAULT_ROTATE_SECONDS,
			$bare
		);
	}

	/**
	 * **A shortcode slot hydrates, or it is a box that never fills.**
	 *
	 * The block wrapper gets its attributes from
	 * `get_block_wrapper_attributes()`, which emits everything it is handed.
	 * The shortcode and `aggr_placement()` share a wrapper that used to name
	 * four attributes by hand, so the Interactivity directives — added to the
	 * array later — were built for every slot and dropped for these two. The
	 * result renders exactly like a slot with no inventory, which is a state
	 * the plugin has on purpose, so nothing about it looked wrong.
	 *
	 * Asserted per surface rather than once, because the two reach the same
	 * wrapper through different callers and a fix to one is not a fix to both.
	 *
	 * @return void
	 */
	public function test_a_shortcode_slot_carries_the_directives_that_fill_it(): void {
		$this->place( 'hydrating-leaderboard' );

		ob_start();
		aggr_placement( 'hydrating-leaderboard' );
		$helper = (string) ob_get_clean();

		$surfaces = array(
			'shortcode' => do_shortcode( '[aggr_placement slot="hydrating-leaderboard"]' ),
			'helper'    => $helper,
		);

		foreach ( $surfaces as $surface => $html ) {
			$this->assertStringContainsString(
				'data-wp-interactive="aggr/ad-slot"',
				$html,
				'A ' . $surface . ' slot is not hydrated by any store, so it can never fill.'
			);
			$this->assertStringContainsString( 'data-wp-init="callbacks.fill"', $html, $surface );
			$this->assertStringContainsString( 'data-wp-context=', $html, $surface );

			// And it still carries what it always did.
			$this->assertStringContainsString( 'class="aggr-slot"', $html, $surface );
			$this->assertStringContainsString( 'data-aggr-slot="hydrating-leaderboard"', $html, $surface );
			$this->assertStringContainsString( '/aggr/v1/fill/hydrating-leaderboard', $html, $surface );
		}
	}

	/**
	 * Every registration of the block declares the same attributes.
	 *
	 * There are three: `block.json` in `dist/`, the fallback registration for
	 * an install whose `dist/` was never built, and the pre-1.6.0 alias. An
	 * attribute missing from one of them is not an error — WordPress drops
	 * undeclared attributes before `render_callback` sees them, so the setting
	 * simply stops working on that surface and nothing says so.
	 *
	 * Asserted against `src/`, which is what a build is made from, so this
	 * fails whether or not `dist/` exists on the machine running it.
	 *
	 * @return void
	 */
	public function test_every_registration_declares_the_same_attributes(): void {
		$source = json_decode(
			(string) file_get_contents( AGGR_PLUGIN_DIR . 'src/blocks-interactivity/ad-slot/block.json' ),
			true
		);

		$this->assertIsArray( $source );
		$this->assertIsArray( $source['attributes'] );

		$registry = WP_Block_Type_Registry::get_instance();

		foreach ( array( Placement_Slot::BLOCK, Placement_Slot::LEGACY_BLOCK ) as $name ) {
			$registered = $registry->get_registered( $name );

			$this->assertNotNull( $registered, $name . ' is not registered at all.' );

			foreach ( $source['attributes'] as $attribute => $definition ) {
				$this->assertArrayHasKey(
					$attribute,
					(array) $registered->attributes,
					$name . ' does not declare ' . $attribute . ', so that setting is silently dropped.'
				);
				$this->assertSame(
					$definition['default'],
					$registered->attributes[ $attribute ]['default'],
					$name . ' defaults ' . $attribute . ' differently from block.json.'
				);
			}
		}
	}

	/**
	 * Creates an active placement and returns nothing but the guarantee.
	 *
	 * @param string $slug Placement post_name.
	 * @return int Placement post id.
	 */
	private function place( string $slug ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => $slug,
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		return $placement_id;
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
