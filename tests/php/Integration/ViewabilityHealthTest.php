<?php
/**
 * The signal that tells a dead script from a day nobody looked.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Install\Viewability_Health;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The portal tile shows the same `0.0%` whether nobody saw the advertisements
 * or the measuring script never ran, and those are very different problems.
 * From the site's own side the second is knowable, which is what this reports.
 *
 * Only one of the four answers is a warning. A site that has served nothing, or
 * whose first measured day has not closed, is in an ordinary state — calling
 * either critical is how somebody learns to dismiss Site Health, and that cost
 * is paid by the next warning that matters.
 */
final class ViewabilityHealthTest extends WP_UnitTestCase {

	/**
	 * Rollup persistence.
	 *
	 * @var Rollup_Repository
	 */
	private Rollup_Repository $rollups;

	/**
	 * The check under test.
	 *
	 * @var Viewability_Health
	 */
	private Viewability_Health $health;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_delivery_tables();

		$this->rollups = Plugin::instance()->container()->get( Rollup_Repository::class );
		$this->health  = Plugin::instance()->container()->get( Viewability_Health::class );
	}

	/** Yesterday, which is the last day that can be closed. */
	private function yesterday(): string {
		return gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
	}

	/** Nothing served is not a fault. */
	public function test_a_quiet_site_is_not_warned_about(): void {
		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
	}

	/**
	 * Impressions from before measurement existed are not a fault either.
	 *
	 * NULL means nobody was measuring. Reporting that as a problem would warn
	 * every site about history it cannot change.
	 */
	public function test_unmeasured_history_is_not_warned_about(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating a pre-P11 row in this plugin's own table.
		$wpdb->insert(
			$this->rollups->table_name(),
			array(
				'day_utc'      => $this->yesterday(),
				'placement_id' => 91,
				'campaign_id'  => 910,
				'line_item_id' => 9100,
				'impressions'  => 40,
			)
		);

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( 'not measured', strtolower( (string) $result['label'] ) );
	}

	/**
	 * A measured day with no views at all is the one worth reporting.
	 *
	 * Across a whole day this is almost never "nobody looked" — it is a script
	 * that is not executing, which nothing else on the site would tell anyone.
	 */
	public function test_a_measured_day_with_no_views_is_flagged(): void {
		$this->rollups->increment( 'impressions', 92, 920, $this->yesterday(), 9200 );

		$result = $this->health->run_test();

		$this->assertSame(
			'recommended',
			$result['status'],
			'A whole day of impressions with no views was reported as healthy.'
		);
	}

	/** A real rate is reported as the number, not as a problem. */
	public function test_a_measured_day_with_views_reports_the_rate(): void {
		$day = $this->yesterday();

		for ( $i = 0; $i < 4; $i++ ) {
			$this->rollups->increment( 'impressions', 93, 930, $day, 9300 );
		}

		$this->rollups->increment( 'viewables', 93, 930, $day, 9300 );

		$result = $this->health->run_test();

		$this->assertSame( 'good', $result['status'] );
		$this->assertStringContainsString( '25.0', (string) $result['label'] );
	}

	/**
	 * The check is attached, not merely correct.
	 *
	 * A refactor that drops the `add_filter` leaves every assertion above green
	 * and the signal entirely absent from the screen it exists for.
	 */
	public function test_the_check_is_registered_with_site_health(): void {
		$this->health->init();

		$tests = apply_filters( 'site_status_tests', array( 'direct' => array() ) );

		$this->assertArrayHasKey(
			'aggr_viewability',
			$tests['direct'],
			'The viewability check never reaches Site Health.'
		);
	}
}
