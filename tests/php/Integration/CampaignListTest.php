<?php
/**
 * The campaigns list and the dashboard's attention card: counts, search, pages.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Filter;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * Every assertion that matters here has a negative half: what another
 * organization's campaigns, or a campaign outside the slice, must not do.
 */
final class CampaignListTest extends WP_UnitTestCase {

	/**
	 * The assembler under test.
	 *
	 * @var View_Data
	 */
	private View_Data $view;

	/**
	 * The caller's organization.
	 *
	 * @var int
	 */
	private int $org_a;

	/**
	 * An unrelated organization.
	 *
	 * @var int
	 */
	private int $org_b;

	/**
	 * The caller.
	 *
	 * @var int
	 */
	private int $advertiser_a;

	/**
	 * Two organizations that must never see each other.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->view         = Plugin::instance()->container()->get( View_Data::class );
		$this->advertiser_a = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$advertiser_b       = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_a        = $this->make_org( $this->advertiser_a );
		$this->org_b        = $this->make_org( $advertiser_b );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
		wp_set_current_user( $this->advertiser_a );
	}

	/**
	 * Clears request state a test set.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		unset( $_GET['days'] );

		parent::tear_down();
	}

	/**
	 * **The tiles counted one page.** Past twenty campaigns the count stopped
	 * while the list it links to kept going. Pinned one past a full page, and
	 * against the list's own total.
	 *
	 * @return void
	 */
	public function test_counts_do_not_stop_at_one_page(): void {
		$many = Campaign_Repository::PAGE_SIZE + 2;

		for ( $i = 0; $i < $many; $i++ ) {
			$this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Draft ' . $i );
		}

		$this->make_campaign( $this->org_b, Post_Statuses::DRAFT, 'Not theirs' );

		$counts = array();

		foreach ( $this->view->counts() as $stat ) {
			$counts[ (string) $stat['filter'] ] = (int) $stat['value'];
		}

		$this->assertSame( $many, $counts[ Campaign_Filter::ATTENTION ] );
		$this->assertSame( $many, (int) $this->view->campaigns( 1, Campaign_Filter::ATTENTION )['total'] );
		$this->assertSame( 0, $counts[ Campaign_Filter::RUNNING ] );
	}

	/**
	 * Every campaign waiting on the advertiser is listed with why: the review
	 * team's note for one sent back, "not submitted" for a draft — and nothing
	 * running, and nothing of another organization's.
	 *
	 * @return void
	 */
	public function test_attention_lists_every_waiting_campaign_with_its_reason(): void {
		$sent_back = $this->make_campaign( $this->org_a, Post_Statuses::CHANGES, 'Fall guide' );
		update_post_meta( $sent_back, Campaign_Repository::META_REVIEW_NOTES, 'Please send a new 300x250 file.' );
		update_post_meta( $sent_back, Campaign_Repository::META_INTERNAL_NOTES, 'Staff only: slow payer.' );

		$bare = $this->make_campaign( $this->org_a, Post_Statuses::CHANGES, 'No note left' );
		$this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Unfinished' );
		$this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Running fine' );
		$this->make_campaign( $this->org_b, Post_Statuses::CHANGES, 'Theirs to fix' );

		$attention = $this->view->attention();
		$by_title  = array();

		foreach ( $attention['rows'] as $row ) {
			$by_title[ $row['title'] ] = $row;
		}

		$this->assertSame( 3, $attention['total'] );
		$this->assertCount( 3, $attention['rows'] );
		$this->assertEqualsCanonicalizing( array( 'Fall guide', 'No note left', 'Unfinished' ), array_keys( $by_title ), 'A running or another organization’s campaign was listed as needing attention.' );

		$this->assertSame( 'Please send a new 300x250 file.', $by_title['Fall guide']['reason'] );
		$this->assertSame( 'Make changes', $by_title['Fall guide']['action'] );
		$this->assertStringNotContainsString( 'slow payer', implode( ' ', array_column( $attention['rows'], 'reason' ) ), 'An internal note reached the advertiser.' );
		$this->assertSame( 'The review team asked for changes.', $by_title['No note left']['reason'] );
		$this->assertSame( (int) $bare, $by_title['No note left']['id'] );
		$this->assertSame( 'Not submitted yet.', $by_title['Unfinished']['reason'] );
		$this->assertSame( 'Continue setup', $by_title['Unfinished']['action'] );
	}

	/**
	 * Search finds names, is scoped to the organization, and narrows a slice.
	 *
	 * @return void
	 */
	public function test_search_finds_names_within_the_organization_only(): void {
		$this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Spring season launch' );
		$this->make_campaign( $this->org_a, Post_Statuses::DRAFT, 'Spring gallery' );
		$this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Members drive' );
		$this->make_campaign( $this->org_b, Post_Statuses::LIVE, 'Spring sale' );
		$this->make_campaign( $this->org_b, Post_Statuses::LIVE, 'Winter only theirs' );

		$spring = $this->view->campaigns( 1, '', 'spring' );

		$this->assertSame( 2, (int) $spring['total'] );
		$this->assertEqualsCanonicalizing( array( 'Spring season launch', 'Spring gallery' ), array_column( $spring['rows'], 'title' ) );

		// A name only another organization has finds nothing, rather than their row.
		$this->assertSame( 0, (int) $this->view->campaigns( 1, '', 'Winter only' )['total'] );

		// The slice and the search both apply.
		$running_spring = $this->view->campaigns( 1, Campaign_Filter::RUNNING, 'Spring' );

		$this->assertSame( 1, (int) $running_spring['total'] );
		$this->assertSame( 'Spring season launch', (string) $running_spring['rows'][0]['title'] );

		// Blank is no search at all.
		$this->assertSame( 3, (int) $this->view->campaigns( 1, '', '   ' )['total'] );
	}

	/**
	 * The second page holds the rest, and nothing is shown twice.
	 *
	 * @return void
	 */
	public function test_the_second_page_holds_the_rest(): void {
		$many = Campaign_Repository::PAGE_SIZE + 3;

		for ( $i = 0; $i < $many; $i++ ) {
			$this->make_campaign( $this->org_a, Post_Statuses::LIVE, 'Campaign ' . $i );
		}

		$first  = $this->view->campaigns( 1 );
		$second = $this->view->campaigns( 2 );

		$this->assertSame( 2, (int) $first['pages'] );
		$this->assertCount( Campaign_Repository::PAGE_SIZE, $first['rows'] );
		$this->assertCount( 3, $second['rows'] );
		$this->assertSame( array(), array_intersect( array_column( $first['rows'], 'id' ), array_column( $second['rows'], 'id' ) ) );
	}

	/**
	 * A window within the export's cap is exported exactly: the CSV is what
	 * the chart above the button shows.
	 *
	 * @return void
	 */
	public function test_the_export_matches_a_selected_short_window(): void {
		$_GET['days'] = 7;

		$window = $this->view->delivery_window();

		$this->assertSame( 7, $window['days'] );
		$this->assertSame( 7, $window['export_days'] );
		$this->assertSame( $window['from'], $window['export_from'] );
		$this->assertSame( $window['to'], $window['export_to'] );
	}

	/**
	 * An organization owned by one user.
	 *
	 * @param int $owner Owning user id.
	 * @return int
	 */
	private function make_org( int $owner ): int {
		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $owner );

		return $org_id;
	}

	/**
	 * A campaign belonging to an organization.
	 *
	 * @param int    $org_id Owning organization.
	 * @param string $status Campaign status.
	 * @param string $title  Campaign title.
	 * @return int
	 */
	private function make_campaign( int $org_id, string $status, string $title ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => $title,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $org_id );

		return $campaign_id;
	}
}
