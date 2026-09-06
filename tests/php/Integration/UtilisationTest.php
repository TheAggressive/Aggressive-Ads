<?php
/**
 * How much of a placement was genuinely spoken for.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Report_Data;
use Aggressive\Ads\Admin\Reports_Screen;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Decision_Outcome;
use Aggressive\Ads\Domain\No_Fill_Reason;
use Aggressive\Ads\Domain\Opportunity;
use Aggressive\Ads\Domain\Report_Period;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Decision_Rollup_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Utilisation is the slice that could not be built before the page/refresh
 * split existed, because a figure that counts a timer as supply says a
 * publisher is busier than their inventory actually is — and says it more
 * loudly the faster they rotate.
 *
 * The tests here are mostly about what must *not* happen: refreshes must not
 * inflate it, an idle placement must not read as nought per cent, and a group
 * must not be an average of rates.
 */
final class UtilisationTest extends WP_UnitTestCase {

	/**
	 * Assembler under test.
	 *
	 * @var Report_Data
	 */
	private Report_Data $data;

	/**
	 * Counter writes.
	 *
	 * @var Decision_Rollup_Repository
	 */
	private Decision_Rollup_Repository $rollups;

	/**
	 * Placement writes.
	 *
	 * @var Placement_Repository
	 */
	private Placement_Repository $placements;

	/**
	 * Screen under test.
	 *
	 * @var Reports_Screen
	 */
	private Reports_Screen $screen;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * The UTC day every fixture counter is written to.
	 *
	 * @var string
	 */
	private string $day = '';

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install();

		$container        = Plugin::instance()->container();
		$this->data       = $container->get( Report_Data::class );
		$this->rollups    = $container->get( Decision_Rollup_Repository::class );
		$this->placements = $container->get( Placement_Repository::class );
		$this->screen     = $container->get( Reports_Screen::class );
		$this->settings   = $container->get( Settings::class );

		$this->rollups->install_table();
		$this->enable_reporting();
		$this->day = gmdate( 'Y-m-d' );
	}

	/**
	 * An active placement.
	 *
	 * @param string             $slug   Slot slug.
	 * @param array<int, string> $groups Group labels.
	 * @return int
	 */
	private function placement( string $slug, array $groups = array() ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_name'   => $slug,
				'post_title'  => ucfirst( $slug ),
			)
		);

		update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $id, Placement_Repository::META_SIZE, '728x90' );

		if ( array() !== $groups ) {
			$this->placements->set_groups( $id, $groups );
		}

		return $id;
	}

	/**
	 * The window every test reads.
	 *
	 * @return Report_Period
	 */
	private function window(): Report_Period {
		return Report_Period::trailing( 7, $this->day );
	}

	/**
	 * The utilisation row for one placement.
	 *
	 * @param array<string, mixed> $view Utilisation view.
	 * @param int                  $id   Placement post id.
	 * @return array<string, mixed>
	 */
	private function row( array $view, int $id ): array {
		foreach ( $view['placements'] as $row ) {
			if ( $row['id'] === $id ) {
				return $row;
			}
		}

		$this->fail( 'Placement ' . $id . ' is missing from the utilisation view.' );
	}

	public function test_utilisation_is_fills_over_page_requests(): void {
		$id = $this->placement( 'leader' );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST     => 100,
				Decision_Outcome::FILL        => 40,
				No_Fill_Reason::NO_CANDIDATES => 60,
			),
			Opportunity::PAGE
		);

		$row = $this->row( $this->data->utilisation( $this->window() ), $id );

		$this->assertSame( 100, $row['requests'] );
		$this->assertSame( 40, $row['fills'] );
		$this->assertSame( 0.4, $row['fill_rate'] );
		$this->assertSame( 0, $row['unaccounted'] );
	}

	/**
	 * **A rotation does not raise utilisation.**
	 *
	 * This is the whole reason slice 4 came last. Refresh counters are real
	 * delivery and real impressions, but they are not independent supply — so a
	 * publisher who doubles their rotation rate must see the same utilisation,
	 * not twice as much inventory sold.
	 *
	 * @return void
	 */
	public function test_refresh_counters_do_not_change_utilisation(): void {
		$id = $this->placement( 'leader' );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 100,
				Decision_Outcome::FILL    => 40,
			),
			Opportunity::PAGE
		);

		$before = $this->row( $this->data->utilisation( $this->window() ), $id );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 900,
				Decision_Outcome::FILL    => 900,
			),
			Opportunity::REFRESH
		);

		$after = $this->row( $this->data->utilisation( $this->window() ), $id );

		$this->assertSame( $before, $after, 'A rotation changed how much inventory looked sold.' );
		$this->assertSame( 0.4, $after['fill_rate'] );
		$this->assertSame( 100, $after['requests'] );
	}

	/**
	 * **A placement nobody asked for reads as no data, not as nought per cent.**
	 *
	 * Rendering 0% would tell a publisher their placement is failing to fill
	 * when in fact nothing has requested it — a different problem with a
	 * different fix.
	 *
	 * @return void
	 */
	public function test_an_idle_placement_has_no_rate_rather_than_zero(): void {
		$id  = $this->placement( 'never-asked' );
		$row = $this->row( $this->data->utilisation( $this->window() ), $id );

		$this->assertSame( 0, $row['requests'] );
		$this->assertNull( $row['fill_rate'] );
	}

	/** A placement that was asked and never filled is a measured zero. */
	public function test_a_requested_but_unfilled_placement_is_a_measured_zero(): void {
		$id = $this->placement( 'empty' );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST     => 30,
				No_Fill_Reason::NO_CANDIDATES => 30,
			),
			Opportunity::PAGE
		);

		$row = $this->row( $this->data->utilisation( $this->window() ), $id );

		$this->assertSame( 0.0, $row['fill_rate'] );
		$this->assertNotNull( $row['fill_rate'] );
	}

	/** Every active placement appears, traffic or not. */
	public function test_every_placement_appears(): void {
		$busy = $this->placement( 'busy' );
		$idle = $this->placement( 'idle' );

		$this->rollups->add(
			$this->day,
			$busy,
			array(
				Decision_Outcome::REQUEST => 5,
				Decision_Outcome::FILL    => 5,
			),
			Opportunity::PAGE
		);

		$ids = array_column( $this->data->utilisation( $this->window() )['placements'], 'id' );

		$this->assertContains( $busy, $ids );
		$this->assertContains( $idle, $ids );
	}

	/**
	 * **A group totals its placements; it does not average their rates.**
	 *
	 * A mean of rates weights a placement with nine requests the same as one
	 * with nine thousand. Here the average of the two rates is 0.75 and the
	 * true share sold is 0.19 — a group that is nearly empty would report as
	 * three-quarters sold.
	 *
	 * @return void
	 */
	public function test_a_group_sums_counters_rather_than_averaging_rates(): void {
		$small = $this->placement( 'small', array( 'sidebar' ) );
		$large = $this->placement( 'large', array( 'sidebar' ) );

		// Rate 1.0 on tiny volume.
		$this->rollups->add(
			$this->day,
			$small,
			array(
				Decision_Outcome::REQUEST => 10,
				Decision_Outcome::FILL    => 10,
			),
			Opportunity::PAGE
		);

		// Rate 0.5 on large volume.
		$this->rollups->add(
			$this->day,
			$large,
			array(
				Decision_Outcome::REQUEST => 1000,
				Decision_Outcome::FILL    => 100,
			),
			Opportunity::PAGE
		);

		$groups = $this->data->utilisation( $this->window() )['groups'];
		$this->assertCount( 1, $groups );

		$sidebar = $groups[0];

		$this->assertSame( 'sidebar', $sidebar['slug'] );
		$this->assertSame( 2, $sidebar['placements'] );
		$this->assertSame( 1010, $sidebar['requests'] );
		$this->assertSame( 110, $sidebar['fills'] );

		$expected = 110 / 1010;

		$this->assertEqualsWithDelta( $expected, $sidebar['fill_rate'], 0.0000001 );

		// The average of the two rates would be 0.75. It must not be that.
		$this->assertNotEqualsWithDelta( 0.75, $sidebar['fill_rate'], 0.01 );
	}

	/** A placement in two groups counts toward both. */
	public function test_a_placement_counts_toward_every_group_it_is_in(): void {
		$id = $this->placement( 'shared', array( 'sidebar', 'footer' ) );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 20,
				Decision_Outcome::FILL    => 5,
			),
			Opportunity::PAGE
		);

		$groups = array_column( $this->data->utilisation( $this->window() )['groups'], null, 'slug' );

		$this->assertSame( 20, $groups['sidebar']['requests'] );
		$this->assertSame( 20, $groups['footer']['requests'] );
		$this->assertSame( 0.25, $groups['sidebar']['fill_rate'] );
	}

	/** A group whose placements were never requested has no rate either. */
	public function test_an_idle_group_has_no_rate(): void {
		$this->placement( 'quiet', array( 'sidebar' ) );

		$groups = array_column( $this->data->utilisation( $this->window() )['groups'], null, 'slug' );

		$this->assertSame( 0, $groups['sidebar']['requests'] );
		$this->assertNull( $groups['sidebar']['fill_rate'] );
	}

	/** An ungrouped placement appears in the list and in no group. */
	public function test_an_ungrouped_placement_is_in_no_group(): void {
		$id = $this->placement( 'loner' );

		$view = $this->data->utilisation( $this->window() );

		$this->assertSame( array(), $this->row( $view, $id )['groups'] );
		$this->assertSame( array(), $view['groups'] );
	}

	/**
	 * **Counters for a deleted placement are reported, not silently dropped.**
	 *
	 * The headline figures sum every row in the window; the breakdown can only
	 * name placements that still exist. Delete one and its history stays in the
	 * table while vanishing from every row, so the totals disagree with the
	 * rows by a number nobody can account for. Observed on a real screen:
	 * "Page requests: 3,120" above a table where every placement read nought.
	 *
	 * @return void
	 */
	public function test_counters_for_a_deleted_placement_are_reported(): void {
		$id = $this->placement( 'doomed' );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 3120,
				Decision_Outcome::FILL    => 2340,
			),
			Opportunity::PAGE
		);

		wp_delete_post( $id, true );

		$view = $this->data->utilisation( $this->window() );

		$this->assertSame( array(), $view['placements'], 'A deleted placement must not be listed.' );
		$this->assertSame( 3120, $view['unattributed']['requests'] );
		$this->assertSame( 2340, $view['unattributed']['fills'] );

		$this->assertStringContainsString(
			'no longer exist',
			$this->rendered(),
			'The screen counted events it could not attribute and said nothing.'
		);
	}

	/** A site with no deleted placements reports nothing extra. */
	public function test_nothing_unattributed_prints_no_notice(): void {
		$id = $this->placement( 'present' );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 10,
				Decision_Outcome::FILL    => 4,
			),
			Opportunity::PAGE
		);

		$view = $this->data->utilisation( $this->window() );

		$this->assertSame( 0, $view['unattributed']['requests'] );
		$this->assertStringNotContainsString( 'no longer exist', $this->rendered() );
	}

	/**
	 * Turns reporting on, since the screen renders nothing without it.
	 *
	 * @return void
	 */
	private function enable_reporting(): void {
		$document = $this->settings->get();

		$document['modules'][ Settings_Schema::MODULE_REPORTING ] = true;

		$this->settings->save( $document );
	}

	/**
	 * The rendered screen.
	 *
	 * @return string
	 */
	private function rendered(): string {
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		ob_start();
		$this->screen->render();

		return (string) ob_get_clean();
	}

	public function test_the_screen_lists_each_placement_and_its_rate(): void {
		$id = $this->placement( 'leader', array( 'sidebar' ) );

		$this->rollups->add(
			$this->day,
			$id,
			array(
				Decision_Outcome::REQUEST => 200,
				Decision_Outcome::FILL    => 50,
			),
			Opportunity::PAGE
		);

		$html = $this->rendered();

		$this->assertStringContainsString( 'Utilisation by placement', $html );
		$this->assertStringContainsString( 'Leader', $html );
		$this->assertStringContainsString( 'sidebar', $html );
		$this->assertStringContainsString( 'Utilisation by group', $html );
	}

	/**
	 * **The screen says the figure excludes refreshes.**
	 *
	 * The number is only correct if a reader knows what it counts. Somebody
	 * who assumes it includes every impression will read a healthy placement
	 * as a failing one, so the caveat is asserted rather than trusted to
	 * survive a later edit.
	 *
	 * @return void
	 */
	public function test_the_screen_says_refreshes_are_excluded(): void {
		$this->placement( 'leader' );

		$this->assertStringContainsString( 'Page opportunities only', $this->rendered() );
	}

	/** A placement in no group renders an em dash, not an empty cell. */
	public function test_an_ungrouped_placement_renders_a_dash(): void {
		$this->placement( 'loner' );

		$html = $this->rendered();

		$this->assertStringContainsString( 'Utilisation by placement', $html );
		$this->assertStringNotContainsString( 'Utilisation by group', $html );
		$this->assertStringContainsString( '—', $html );
	}
}
