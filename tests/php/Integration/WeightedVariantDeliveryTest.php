<?php
/**
 * Weighted variants, through the path a visitor actually takes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Fill_Service;
use WP_UnitTestCase;

/**
 * P17's required evidence, and the thing that has to be true before a screen
 * lets anybody set a weight.
 *
 * The mechanism is described as already built: assignments carry a weight and
 * `Domain\Weighted_Selection` picks among the survivors. Both existing tests for
 * it call the selector directly, which proves the arithmetic and nothing about
 * whether delivery reaches it — the same shape as the page coordinator, which
 * was complete, tested, and never once executed for a visitor.
 *
 * So this goes through `Fill_Service::for_slug()`: the real candidate query, the
 * real pipeline, the real payload. If weighted delivery were unreachable, a
 * management screen for weights would be a control over nothing.
 *
 * Variants are identified by alt text rather than by id. `with_tokens()` strips
 * `placement`, `campaign` and `creative` before the payload leaves the server —
 * those ids travel inside the signed token, so the browser never learns them.
 * That is the right design, and it means a test has to tell two variants apart
 * the way a visitor could: by something rendered.
 */
final class WeightedVariantDeliveryTest extends WP_UnitTestCase {

	/**
	 * Placement both variants are assigned to.
	 *
	 * @var int
	 */
	private int $placement_id = 0;

	/**
	 * Campaign holding both variants.
	 *
	 * @var int
	 */
	private int $campaign_id = 0;

	/**
	 * Revision ids, keyed by the weight each was given.
	 *
	 * @var array<int, int>
	 */
	private array $revisions = array();


	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();
		update_option( Creative_Assignment_Migrator::OPTION_DONE, 1 );

		$settings = Plugin::instance()->container()->get( Settings::class );
		$document = $settings->get();
		$document['modules'][ Settings_Schema::MODULE_NATIVE_DELIVERY ] = true;
		$settings->save( $document );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => 'variant-gate',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		Plugin::instance()->container()->get( Creative_Assignment_Repository::class )->install_table();

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
			)
		);
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$this->revisions[3] = $this->variant( 'Heavy', 3 );
		$this->revisions[1] = $this->variant( 'Light', 1 );

		add_filter(
			'wp_get_attachment_image_src',
			static fn (): array => array( 'https://example.org/creative.png', 728, 90, false )
		);
	}

	/**
	 * **Two variants at 3 and 1 converge on three to one.**
	 *
	 * Asserted as a band rather than an exact ratio, because the selection is
	 * genuinely random per request; the band is wide enough not to flake and
	 * narrow enough that an unweighted even split — the failure that matters —
	 * falls outside it. Both variants must also appear at all: a selector that
	 * always returned the heavier one would land at 100/0 and pass any test
	 * that only checked the heavy share was large.
	 *
	 * @return void
	 */
	public function test_a_weighted_pair_converges_on_its_weights(): void {
		$fill   = Plugin::instance()->container()->get( Fill_Service::class );
		$counts = array(
			'Heavy' => 0,
			'Light' => 0,
		);

		for ( $request = 0; $request < 600; $request++ ) {
			$payload = $fill->for_slug( 'variant-gate' );

			$this->assertIsArray( $payload );
			$this->assertIsArray( $payload['creative'], 'A weighted variant stopped serving entirely.' );

			$served = (string) $payload['creative']['alt'];

			$this->assertArrayHasKey( $served, $counts, 'Something outside the two variants served.' );

			++$counts[ $served ];
		}

		$heavy = $counts['Heavy'];
		$light = $counts['Light'];

		$this->assertGreaterThan( 0, $light, 'The lighter variant never served, so weight is being read as a filter.' );

		$share = $heavy / 600;

		$this->assertGreaterThan(
			0.66,
			$share,
			sprintf( 'The heavier variant took %d of 600, which is nearer an even split than three to one.', $heavy )
		);
		$this->assertLessThan(
			0.84,
			$share,
			sprintf( 'The heavier variant took %d of 600, so the lighter one is being suppressed rather than weighted.', $heavy )
		);
	}

	/**
	 * Both variants reach the counters they are compared on.
	 *
	 * Slice 1 gave the rollup a creative dimension; this is the half that
	 * proves delivery fills it. A comparison screen reading two rows that only
	 * one variant ever writes to would show a winner by default.
	 *
	 * @return void
	 */
	public function test_each_variant_is_measured_under_its_own_creative(): void {
		$fill = Plugin::instance()->container()->get( Fill_Service::class );
		$seen = array();

		for ( $request = 0; $request < 200; $request++ ) {
			$payload = $fill->for_slug( 'variant-gate' );

			$this->assertIsArray( $payload['creative'] );

			$seen[ (string) $payload['creative']['alt'] ] = true;
		}

		$this->assertArrayHasKey( 'Heavy', $seen );
		$this->assertArrayHasKey( 'Light', $seen );

		/*
		 * The token is what ties a delivery to the row it will be counted in.
		 * The ids are stripped from the payload, so this is the only thing
		 * carrying the creative from the fill to the beacon — without it,
		 * per-creative measurement is unattributable however correct the
		 * projector is.
		 */
		$this->assertNotSame( '', (string) $fill->for_slug( 'variant-gate' )['creative']['token'] );
	}

	/**
	 * One assignment, one weight, no behaviour change.
	 *
	 * The overwhelmingly common case, asserted so the weighting cannot start
	 * costing a placement its only creative.
	 *
	 * @return void
	 */
	public function test_a_single_variant_still_serves_every_request(): void {
		global $wpdb;

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Retiring one variant of this plugin's own fixture.
		$wpdb->delete( $assignments->table_name(), array( 'revision_id' => $this->revisions[1] ) );

		$fill = Plugin::instance()->container()->get( Fill_Service::class );

		for ( $request = 0; $request < 25; $request++ ) {
			$payload = $fill->for_slug( 'variant-gate' );

			$this->assertIsArray( $payload['creative'] );
			$this->assertSame( 'Heavy', (string) $payload['creative']['alt'] );
		}
	}

	/**
	 * Creates one live variant on the shared placement.
	 *
	 * @param string $label  Alt text, so a failure names which variant.
	 * @param int    $weight Relative share.
	 * @return int Revision id.
	 */
	private function variant( string $label, int $weight ): int {
		global $wpdb;

		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => strtolower( $label ) . '.png',
				'post_mime_type' => 'image/png',
			)
		);

		$revision_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $revision_id, Creative_Repository::META_CAMPAIGN_ID, $this->campaign_id );
		update_post_meta( $revision_id, Creative_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $revision_id, Creative_Repository::META_CLICK_URL, 'https://example.com/' . strtolower( $label ) );
		update_post_meta( $revision_id, Creative_Repository::META_ALT_TEXT, $label );
		update_post_meta( $revision_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );
		update_post_meta( $revision_id, Creative_Repository::META_WIDTH, 728 );
		update_post_meta( $revision_id, Creative_Repository::META_HEIGHT, 90 );

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert(
			$assignments->table_name(),
			array(
				'line_item_id'  => $this->campaign_id,
				'campaign_id'   => $this->campaign_id,
				'placement_id'  => $this->placement_id,
				'revision_id'   => $revision_id,
				'status'        => Assignment_Rules::LIVE,
				'weight'        => $weight,
				'click_url'     => 'https://example.com/' . strtolower( $label ),
				'attachment_id' => $attachment_id,
				'alt_text'      => $label,
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);

		return $revision_id;
	}
}
