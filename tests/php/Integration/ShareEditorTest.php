<?php
/**
 * Shares of a placement, set through the portal.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Attachment_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Creative_Manager;
use Aggressive\Ads\Workflow\Share_Editor;
use WP_UnitTestCase;

/**
 * `Share_Editor` against real assignments, removals and permissions.
 *
 * The arithmetic is proven in `AssignmentShareTest`, in milliseconds and
 * exhaustively. What is here is what that cannot see: which rows are read,
 * what a refusal leaves behind, and that the number an advertiser types is
 * the number the screen shows afterwards.
 */
final class ShareEditorTest extends WP_UnitTestCase {

	use CreativeFixtures;

	/**
	 * Owning advertiser user id.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $stranger;

	/**
	 * Draft campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * The placement the rotation is on.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Under test.
	 *
	 * @var Share_Editor
	 */
	private Share_Editor $shares;

	/**
	 * Uploads.
	 *
	 * @var Creative_Manager
	 */
	private Creative_Manager $manager;

	/**
	 * A draft campaign with one placement that can hold a rotation.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner        = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger     = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->placement_id = $this->placement( 'Header', '728x90' );
		$this->campaign_id  = $this->campaign( $this->owner, $this->org( $this->owner ), array( $this->placement_id ) );

		$this->shares  = Plugin::instance()->container()->get( Share_Editor::class );
		$this->manager = Plugin::instance()->container()->get( Creative_Manager::class );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Removes stored and temporary files.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$this->remove_creative_files( $this->campaign_id );

		parent::tear_down();
	}

	/**
	 * One ad takes the share asked for; the rest is the others'.
	 *
	 * @return void
	 */
	public function test_setting_one_share_leaves_the_rest_to_the_others(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->ad( 1 );
		$second = $this->ad( 2 );

		$this->assertSame(
			array(
				$first  => 70,
				$second => 30,
			),
			$this->shares->set_share( $first, 70 )
		);
		$this->assertSame( array( 70, 30 ), $this->percentages() );
	}

	/**
	 * A removed ad's share is not still held against the ads that remain.
	 *
	 * The defect this is for: a placement whose ads had been removed and
	 * re-uploaded showed two live ads at "about 10%" each, because every
	 * retired row's weight was still in the denominator.
	 *
	 * @return void
	 */
	public function test_a_removed_ad_takes_its_share_with_it(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->ad( 1 );
		$second = $this->ad( 2 );
		$third  = $this->ad( 3 );

		// Three ads as uploaded: one weight each, so a third of the placement.
		$this->assertSame( array( 33, 33, 33 ), $this->percentages() );
		$this->assertTrue( $this->manager->remove( $third ) );

		// The two that remain divide the whole placement, not two thirds of it.
		$this->assertSame(
			array(
				$first  => 60,
				$second => 40,
			),
			$this->shares->set_share( $first, 60 )
		);
		$this->assertSame( array( 60, 40 ), $this->percentages() );
	}

	/**
	 * A stranger cannot set a share, and nothing moves when one tries.
	 *
	 * @return void
	 */
	public function test_a_stranger_is_refused_and_changes_nothing(): void {
		wp_set_current_user( $this->owner );

		$first = $this->ad( 1 );
		$this->ad( 2 );
		$this->shares->set_share( $first, 80 );

		wp_set_current_user( $this->stranger );

		$refused = $this->shares->set_share( $first, 10 );

		$this->assertWPError( $refused );
		$this->assertSame( 'aggr_share_forbidden', $refused->get_error_code() );

		wp_set_current_user( $this->owner );
		$this->assertSame( array( 80, 20 ), $this->percentages(), 'A refused write moved the shares.' );
	}

	/**
	 * An ad with no assignment has no share to set, and says so.
	 *
	 * @return void
	 */
	public function test_an_ad_that_is_not_delivering_has_no_share(): void {
		wp_set_current_user( $this->owner );

		// Uploaded and never read back, so nothing has assigned it yet.
		$alone = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90, 1 ),
			'https://example.com/alone',
			'Ad'
		);

		$this->assertIsArray( $alone );

		$refused = $this->shares->set_share( (int) $alone['id'], 50 );

		$this->assertWPError( $refused );
		$this->assertSame( 'aggr_share_no_assignment', $refused->get_error_code() );
	}

	/**
	 * The portal's own entry point sets shares, and says what every card shows.
	 *
	 * @return void
	 */
	public function test_the_portal_entry_point_rebalances_and_patches(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->ad( 1 );
		$second = $this->ad( 2 );
		$result = Plugin::instance()->container()->get( Creative_Actions::class )->process_weight( $first, 25 );

		$this->assertSame(
			array(
				$first  => 25,
				$second => 75,
			),
			$result
		);
		$this->assertSame( array( 25, 75 ), $this->percentages() );
	}

	/**
	 * A stale row in the set stops the whole write, not part of it.
	 *
	 * Written a row at a time, a refusal half way through left the first ads
	 * moved and the rest as they were — a rotation adding to a total nobody
	 * chose. The repository is where that is decided, and it is asked here
	 * directly because `Share_Editor` reads the rows itself immediately
	 * before writing them: through it, the stale revision would never be the
	 * one used.
	 *
	 * @return void
	 */
	public function test_one_stale_row_stops_the_whole_write(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->ad( 1 );
		$second = $this->ad( 2 );

		$assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
		$rows        = array();

		foreach ( $assignments->for_campaign( $this->campaign_id ) as $row ) {
			$rows[ (int) $row['revision_id'] ] = $row;
		}

		$written = $assignments->set_weights(
			$this->campaign_id,
			array(
				(int) $rows[ $first ]['id']  => array(
					'weight'   => 70,
					'revision' => (int) $rows[ $first ]['revision'],
				),
				// Somebody else saved this one since the page was drawn.
				(int) $rows[ $second ]['id'] => array(
					'weight'   => 30,
					'revision' => (int) $rows[ $second ]['revision'] + 5,
				),
			)
		);

		$this->assertFalse( $written );
		$this->assertSame( array( 50, 50 ), $this->percentages(), 'A share moved although the write was refused.' );

		// And the same set, all current, is written whole.
		$this->assertTrue(
			$assignments->set_weights(
				$this->campaign_id,
				array(
					(int) $rows[ $first ]['id']  => array(
						'weight'   => 70,
						'revision' => (int) $rows[ $first ]['revision'],
					),
					(int) $rows[ $second ]['id'] => array(
						'weight'   => 30,
						'revision' => (int) $rows[ $second ]['revision'],
					),
				)
			)
		);
		$this->assertSame( array( 70, 30 ), $this->percentages() );
	}

	/**
	 * An ad that cannot run is not counted as taking a share of the traffic.
	 *
	 * @return void
	 */
	public function test_an_ad_that_cannot_run_is_marked_as_not_delivering(): void {
		wp_set_current_user( $this->owner );

		$first  = $this->ad( 1 );
		$second = $this->ad( 2 );

		$rows = array();

		foreach ( Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		// Neither is approved yet, so neither is delivering; the card says so
		// rather than promising a share of traffic that is not flowing.
		$this->assertFalse( $rows[ $first ]['delivering'] );
		$this->assertFalse( $rows[ $second ]['delivering'] );

		// Approved, and it can run.
		Plugin::instance()->container()->get( Creative_Attachment_Repository::class )
			->set_attachment_id( $first, self::factory()->attachment->create() );

		$rows = array();

		foreach ( Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id ) as $row ) {
			$rows[ (int) $row['id'] ] = $row;
		}

		$this->assertTrue( $rows[ $first ]['delivering'] );
		$this->assertFalse( $rows[ $second ]['delivering'], 'An unapproved ad was counted as running.' );
	}

	/**
	 * Uploads one ad and makes sure it is assigned, as opening the page does.
	 *
	 * @param int $variant Which image.
	 * @return int Creative id.
	 */
	private function ad( int $variant ): int {
		$result = $this->manager->upload(
			$this->campaign_id,
			$this->placement_id,
			$this->image_file( 728, 90, $variant ),
			'https://example.com/' . $variant,
			'Ad'
		);

		$this->assertIsArray( $result, is_wp_error( $result ) ? $result->get_error_code() : '' );

		// Assignments are made when the campaign is read; the Ads step reads it.
		$this->percentages();

		return (int) $result['id'];
	}

	/**
	 * What each card on the placement shows, in card order.
	 *
	 * @return array<int, int>
	 */
	private function percentages(): array {
		$percentages = array();

		foreach ( Plugin::instance()->container()->get( Creative_View_Data::class )->creative_rows( $this->campaign_id ) as $row ) {
			if ( null !== ( $row['share'] ?? null ) ) {
				$percentages[] = (int) round( (float) $row['share'] * 100 );
			}
		}

		return $percentages;
	}
}
