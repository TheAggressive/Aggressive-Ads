<?php
/**
 * Whether a click keeps the reader, and who gets to decide.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Decision_Engine;
use Aggressive\Ads\Workflow\Fill_Service;
use WP_UnitTestCase;

/**
 * Every advertisement used to open in the same tab, and no setting could
 * change that. `_aggr_target_blank` was declared on `Creative_Repository`,
 * copied across every creative revision, and read by a single method that
 * nothing in the plugin called — while `fill.js` set `rel="noopener
 * noreferrer"` next to no `target` at all, which is the tell: `noopener`
 * severs `window.opener` on a new browsing context and protects nothing on a
 * same-tab navigation.
 *
 * These assert the two halves that have to agree — the scripted fill payload
 * and the server-rendered no-JavaScript markup — because they are rendered by
 * different code and a visitor without JavaScript never reaches the route the
 * other one uses.
 */
final class AdTargetTest extends WP_UnitTestCase {

	/**
	 * House payload builder.
	 *
	 * @var Fill_Service
	 */
	private Fill_Service $fill;

	/**
	 * Paid payload builder.
	 *
	 * @var Decision_Engine
	 */
	private Decision_Engine $engine;

	/**
	 * Placement writes.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	public function set_up(): void {
		parent::set_up();

		$container        = Plugin::instance()->container();
		$this->fill       = $container->get( Fill_Service::class );
		$this->engine     = $container->get( Decision_Engine::class );
		$this->placements = $container->get( Placement_Repository::class );

		update_option(
			Settings::OPTION,
			array( 'delivery' => array( 'house_policy' => Settings_Schema::HOUSE_WHEN_EMPTY ) )
		);
	}

	/**
	 * A placement with a servable house advertisement.
	 *
	 * @param bool $same_tab Whether the publisher asked to keep the reader.
	 * @return int
	 */
	private function housed_placement( bool $same_tab = false ): int {
		$placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'slot-' . wp_generate_password( 8, false ),
			)
		);

		update_post_meta( $placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $placement_id, Placement_Repository::META_SIZE, '728x90' );

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'house.png',
				'post_mime_type' => 'image/png',
			)
		);

		$this->placements->set_house( $placement_id, $attachment_id, 'https://example.test/house', 'House advertisement', $same_tab );

		return $placement_id;
	}

	public function test_a_paid_creative_always_opens_a_new_tab(): void {
		$payload = $this->engine->payload_from_row(
			array(
				'attachment_id' => (int) self::factory()->attachment->create_object(
					array(
						'file'           => 'paid.png',
						'post_mime_type' => 'image/png',
					)
				),
				'alt_text'      => 'Paid advertisement',
				'width'         => 728,
				'height'        => 90,
				'campaign_id'   => 7,
				'revision_id'   => 9,
			),
			$this->housed_placement( true )
		);

		$this->assertIsArray( $payload );
		$this->assertArrayHasKey(
			'sameTab',
			$payload,
			'The browser reads one field for both kinds of advertisement, so a paid payload has to carry it rather than leave it absent.'
		);
		$this->assertFalse(
			$payload['sameTab'],
			'A placement whose house advertisement stays in the same tab must not drag a paid creative along with it — the reader a paid click carries away is the publisher\'s, and an advertiser has no standing to spend that.'
		);
	}

	public function test_a_house_advertisement_opens_a_new_tab_by_default(): void {
		$placement_id = $this->housed_placement();
		$payload      = $this->fill->for_slug( $this->placements->slug( $placement_id ) );

		$this->assertIsArray( $payload );
		$this->assertIsArray( $payload['house'] );
		$this->assertArrayHasKey(
			'sameTab',
			$payload['house'],
			'Read through the route the browser calls, not through the private builder behind it — a payload key nothing serves is exactly the defect this replaces.'
		);
		$this->assertFalse( $payload['house']['sameTab'] );
	}

	public function test_a_publisher_may_keep_their_own_house_click_on_the_page(): void {
		$placement_id = $this->housed_placement( true );
		$payload      = $this->fill->for_slug( $this->placements->slug( $placement_id ) );

		$this->assertIsArray( $payload );
		$this->assertIsArray( $payload['house'] );
		$this->assertTrue( $payload['house']['sameTab'] );
	}

	public function test_the_no_script_house_advertisement_opens_a_new_tab_too(): void {
		$placement_id = $this->housed_placement();
		$slug         = $this->placements->slug( $placement_id );

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"' . $slug . '"} /-->' );

		$this->assertStringContainsString( '<noscript><a href=', $html, 'The house advertisement stopped rendering.' );
		$this->assertStringContainsString(
			'target="_blank"',
			$html,
			'A visitor without JavaScript never reaches the fill route, so this markup is the only thing deciding where their click goes.'
		);
		$this->assertStringContainsString( 'rel="noopener noreferrer"', $html );
	}

	public function test_the_no_script_house_advertisement_honours_the_same_tab_choice(): void {
		$placement_id = $this->housed_placement( true );
		$slug         = $this->placements->slug( $placement_id );

		$html = do_blocks( '<!-- wp:aggr/ad-slot {"slot":"' . $slug . '"} /-->' );

		$this->assertStringContainsString( '<noscript><a href=', $html );
		$this->assertStringNotContainsString(
			'target="_blank"',
			$html,
			'Both renderers have to reach the same answer; the risk of two is that the one nobody looks at drifts.'
		);
		$this->assertStringNotContainsString(
			'rel="noopener noreferrer"',
			$html,
			'`noopener` severs `window.opener` on a new browsing context and protects nothing on a same-tab navigation. Emitting it alone is how the defect this fixes looked from the outside.'
		);
	}

	public function test_the_choice_survives_a_round_trip(): void {
		$placement_id = $this->housed_placement( true );

		$this->assertTrue( $this->placements->house_same_tab( $placement_id ) );

		$this->placements->set_house( $placement_id, $this->placements->house_attachment_id( $placement_id ), 'https://example.test/house', 'House advertisement', false );

		$this->assertFalse(
			$this->placements->house_same_tab( $placement_id ),
			'A setting that cannot be turned back off is how the previous one looked: stored, carried forward, and never read.'
		);
	}

	public function test_a_write_that_did_not_land_is_reported_as_a_failure(): void {
		$placement_id = $this->housed_placement();

		$refuse = static fn ( $check, $object_id, $meta_key ) =>
			Placement_Repository::META_HOUSE_SAME_TAB === $meta_key ? false : $check;

		add_filter( 'update_post_metadata', $refuse, 10, 3 );

		$saved = $this->placements->set_house( $placement_id, $this->placements->house_attachment_id( $placement_id ), 'https://example.test/house', 'House advertisement', true );

		remove_filter( 'update_post_metadata', $refuse, 10 );

		$this->assertFalse(
			$saved,
			'Every other house field is read back after writing it. Without the same check this one would report success over storage that refused it, and the publisher would be told their choice was saved.'
		);
	}

	public function test_a_placement_that_was_never_asked_opens_a_new_tab(): void {
		$placement_id = (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );

		$this->assertFalse(
			$this->placements->house_same_tab( $placement_id ),
			'Absent means a new tab, so the default does not depend on anything having been written.'
		);
	}
}
