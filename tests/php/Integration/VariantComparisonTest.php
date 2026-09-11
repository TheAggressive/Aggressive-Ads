<?php
/**
 * P17 slice 3: a campaign's variants compared over a window.
 *
 * Counters are written through `Rollup_Repository::increment()`, the path the
 * projector writes, and read back through the builder the portal renders from.
 * The creative dimension was written for a whole slice before anything read it,
 * and a comparison tested against rows it arranged itself would prove only its
 * arithmetic.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Delivery_View_Data;
use Aggressive\Ads\Repository\Rollup_Report_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Workflow\Reporting_Read;
use WP_UnitTestCase;

/**
 * Per-creative figures, tenancy, window and reconciliation.
 */
final class VariantComparisonTest extends WP_UnitTestCase {

	/**
	 * Counter writer.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * Portal delivery builder.
	 *
	 * @var Delivery_View_Data
	 */
	private Delivery_View_Data $delivery;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Organization that owns the campaign.
	 *
	 * @var int
	 */
	private int $org;

	/**
	 * An unrelated organization.
	 *
	 * @var int
	 */
	private int $other_org;

	/**
	 * Campaign under test.
	 *
	 * @var int
	 */
	private int $campaign;

	/**
	 * Placement both variants compete on.
	 *
	 * @var int
	 */
	private int $placement;

	/**
	 * Variant A's creative id.
	 *
	 * @var int
	 */
	private int $creative_a;

	/**
	 * Variant B's creative id.
	 *
	 * @var int
	 */
	private int $creative_b;

	/**
	 * Builds one campaign with two variants on one placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->rollups  = $container->get( Rollup_Repository::class );
		$this->delivery = $container->get( Delivery_View_Data::class );
		$this->settings = $container->get( Settings::class );
		$this->rollups->install_table();

		$this->org        = (int) self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );
		$this->other_org  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::ORGANIZATION ) );
		$this->campaign   = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CAMPAIGN ) );
		$this->placement  = (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );
		$this->creative_a = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CREATIVE ) );
		$this->creative_b = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CREATIVE ) );

		$this->enable_reporting( true );
	}

	/**
	 * Two variants on one placement are compared row by row.
	 *
	 * @return void
	 */
	public function test_two_variants_are_compared_side_by_side(): void {
		$this->serve( $this->creative_a, 3, 1 );
		$this->serve( $this->creative_b, 1, 0 );

		$groups = $this->compare()['groups'] ?? array();

		$this->assertCount( 1, $groups, 'One placement holds both variants.' );
		$this->assertSame( 'Homepage Leaderboard', $groups[0]['placement'] );
		$this->assertCount( 2, $groups[0]['rows'] );

		$a = $groups[0]['rows'][0];
		$this->assertSame( 'Spring banner', $a['label'] );
		$this->assertSame( '75%', $a['share'] );
		$this->assertSame( '3', $a['impressions'] );
		$this->assertSame( '1', $a['clicks'] );
		$this->assertSame( '33.3%', $a['ctr'] );

		$b = $groups[0]['rows'][1];
		$this->assertSame( 'Summer banner', $b['label'] );
		$this->assertSame( '1', $b['impressions'] );

		// A measured zero is a real rate, not an absence.
		$this->assertSame( '0.0%', $b['ctr'] );
	}

	/**
	 * The organization is the boundary, not the campaign id.
	 *
	 * Rows for the same campaign, placement and creative attributed to another
	 * organization are the case the frozen `org_id` exists for — a campaign that
	 * changed hands keeps its history with whoever ran it.
	 *
	 * **On a different day, because that is the only shape it can take.** The
	 * rollup's unique key is placement, campaign, line item, creative and day —
	 * `org_id` is not in it — so a second organization's increment on the same
	 * day upserts into the first organization's row rather than making its own.
	 * The first version of this test did exactly that and "found" 53 of the
	 * owner's impressions where there were 3. A campaign that changes hands
	 * leaves its old history on earlier days, and that is what this arranges.
	 *
	 * @return void
	 */
	public function test_another_organizations_delivery_is_never_read(): void {
		$this->serve( $this->creative_a, 3, 1 );
		$this->serve( $this->creative_b, 1, 0 );
		$this->serve( $this->creative_a, 50, 20, gmdate( 'Y-m-d', strtotime( '-1 day' ) ), $this->other_org );

		$reports = Plugin::instance()->container()->get( Rollup_Report_Repository::class );
		$period  = Plugin::instance()->container()->get( Reporting_Read::class )->default_period();

		$own    = $reports->creative_totals_for_campaign( $this->org, $this->campaign, $period );
		$theirs = $reports->creative_totals_for_campaign( $this->other_org, $this->campaign, $period );

		$this->assertSame( 3, $own[ $this->placement ][ $this->creative_a ]['impressions'] );
		$this->assertSame( 50, $theirs[ $this->placement ][ $this->creative_a ]['impressions'], 'The other organization’s rows exist, so their absence below means something.' );

		$rows = $this->compare()['groups'][0]['rows'];

		$this->assertSame( '3', $rows[0]['impressions'], 'Another organization’s delivery reached this campaign’s comparison.' );
		$this->assertNotContains( '53', array_column( $rows, 'impressions' ) );
	}

	/**
	 * Delivery outside the window is not part of the comparison.
	 *
	 * @return void
	 */
	public function test_delivery_outside_the_window_is_not_counted(): void {
		$this->serve( $this->creative_a, 3, 1 );
		$this->serve( $this->creative_b, 1, 0 );
		$this->serve( $this->creative_a, 7, 7, gmdate( 'Y-m-d', strtotime( '-200 days' ) ) );

		$rows = $this->compare()['groups'][0]['rows'];

		$this->assertSame( '3', $rows[0]['impressions'], 'A day two hundred days back was read into a thirty-day window.' );
	}

	/**
	 * Every row delivered on the placement is shown, so the rows add up.
	 *
	 * Delivery counted before the creative dimension existed lands at creative
	 * id 0, and a creative removed after delivering keeps its rows. Showing only
	 * the current variants would make the placement's rows sum to less than it
	 * delivered — the defect P15 shipped one dimension higher.
	 *
	 * @return void
	 */
	public function test_the_rows_add_up_to_what_the_placement_delivered(): void {
		$removed = (int) self::factory()->post->create( array( 'post_type' => Post_Types::CREATIVE ) );

		$this->serve( $this->creative_a, 3, 1 );
		$this->serve( $this->creative_b, 1, 0 );
		$this->serve( 0, 2, 0 );
		$this->serve( $removed, 4, 1 );

		$rows = $this->compare()['groups'][0]['rows'];

		$this->assertCount( 4, $rows, 'Delivery that is not a current variant was dropped.' );

		$labels = array_column( $rows, 'impressions', 'label' );

		$this->assertSame( '2', $labels['Before per-ad counting'] );
		$this->assertSame( '4', $labels['An ad no longer on this placement'] );

		$this->assertSame(
			10,
			array_sum( array_map( 'intval', array_column( $rows, 'impressions' ) ) ),
			'The rows must sum to everything the placement delivered in the window.'
		);
	}

	/**
	 * A variant that served nothing is "nothing yet", not "not measured".
	 *
	 * Its siblings on the placement were counted over the same days, so
	 * counting was on. "Not measured" is the reading `format_viewability()`
	 * reserves for a day nobody was counting and warns is the alarming, false
	 * one; the first version of the builder showed it for every variant with no
	 * row in the window.
	 *
	 * @return void
	 */
	public function test_a_variant_that_served_nothing_is_not_called_unmeasured(): void {
		$this->serve( $this->creative_a, 3, 1 );

		$rows = $this->compare()['groups'][0]['rows'];

		$this->assertSame( '0', $rows[1]['impressions'] );
		$this->assertSame( '—', $rows[1]['ctr'], 'No impressions means no rate.' );
		$this->assertSame( '—', $rows[1]['viewable'], 'A variant that did not serve was reported as unmeasured.' );
		$this->assertNotSame( 'Not measured', $rows[1]['viewable'] );
	}

	/**
	 * Reporting off shows nothing, rather than a table of zeros.
	 *
	 * @return void
	 */
	public function test_nothing_is_shown_when_reporting_is_off(): void {
		$this->serve( $this->creative_a, 3, 1 );
		$this->serve( $this->creative_b, 1, 0 );

		$this->enable_reporting( false );

		$this->assertSame( array(), $this->compare() );
	}

	/**
	 * A creative alone on its placement has nothing to be compared with.
	 *
	 * @return void
	 */
	public function test_a_lone_creative_is_not_a_comparison(): void {
		$elsewhere = (int) self::factory()->post->create( array( 'post_type' => Post_Types::PLACEMENT ) );

		$this->serve( $this->creative_a, 3, 1 );

		$creatives                    = $this->creatives();
		$creatives[1]['placement_id'] = $elsewhere;

		$this->assertSame(
			array(),
			$this->delivery->variant_comparison( $this->org, $this->campaign, $creatives )
		);
	}

	/**
	 * Before anything is seen, there is no table to show.
	 *
	 * @return void
	 */
	public function test_nothing_is_shown_before_anything_is_seen(): void {
		$this->assertSame( array(), $this->compare() );
	}

	/**
	 * The comparison for the fixture's two variants.
	 *
	 * @return array<string, mixed>
	 */
	private function compare(): array {
		return $this->delivery->variant_comparison( $this->org, $this->campaign, $this->creatives() );
	}

	/**
	 * The creative rows the campaign screen passes in.
	 *
	 * Only the keys the builder reads, and every one of them is a key
	 * `Creative_View_Data::creative_rows()` produces.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function creatives(): array {
		return array(
			array(
				'id'           => $this->creative_a,
				'placement_id' => $this->placement,
				'placement'    => 'Homepage Leaderboard',
				'name'         => 'Spring banner',
				'share'        => 0.75,
			),
			array(
				'id'           => $this->creative_b,
				'placement_id' => $this->placement,
				'placement'    => 'Homepage Leaderboard',
				'name'         => 'Summer banner',
				'share'        => 0.25,
			),
		);
	}

	/**
	 * Records delivery through the projector's own write path.
	 *
	 * @param int    $creative_id Creative that served, or 0 for pre-dimension.
	 * @param int    $impressions Impressions to count.
	 * @param int    $clicks      Clicks to count.
	 * @param string $day_utc     UTC day, or empty for today.
	 * @param int    $org_id      Organization, or 0 for the fixture's own.
	 */
	private function serve( int $creative_id, int $impressions, int $clicks, string $day_utc = '', int $org_id = 0 ): void {
		$org = $org_id > 0 ? $org_id : $this->org;

		for ( $i = 0; $i < $impressions; $i++ ) {
			$this->assertTrue( $this->rollups->increment( 'impressions', $this->placement, $this->campaign, $day_utc, $this->campaign, $org, $creative_id ) );
		}

		for ( $i = 0; $i < $clicks; $i++ ) {
			$this->assertTrue( $this->rollups->increment( 'clicks', $this->placement, $this->campaign, $day_utc, $this->campaign, $org, $creative_id ) );
		}
	}

	/**
	 * Turns the Reporting module on or off.
	 *
	 * @param bool $on Whether Reporting is enabled.
	 */
	private function enable_reporting( bool $on ): void {
		$document = $this->settings->get();
		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = $on;

		$this->assertTrue( $this->settings->save( $document ) );
	}
}
