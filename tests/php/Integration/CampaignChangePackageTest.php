<?php
/**
 * Upgrading or downgrading a running campaign's package.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Change_Form;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Workflow\Live_Package_Change;
use WP_UnitTestCase;

/**
 * A package change is a proposal like any other live edit: nothing about the
 * running campaign moves until it is approved, and approval writes exactly
 * what buying that package at creation would. Both prices are recorded, since
 * billing (#263) settles the difference from them.
 */
final class CampaignChangePackageTest extends WP_UnitTestCase {

	use RunningCampaignFixtures;

	public function set_up(): void {
		parent::set_up();
		$this->set_up_running_campaign();
	}

	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * A second package: the fixture's placement plus a sidebar, for 14 days.
	 *
	 * @param int  $price_cents Its price.
	 * @param bool $on_sale     Whether it is offered.
	 * @return array{0: int, 1: int} Package id, and the sidebar it adds.
	 */
	private function premium_package( int $price_cents = 90000, bool $on_sale = true ): array {
		$sidebar = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Article sidebar',
			)
		);

		update_post_meta( $sidebar, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $sidebar, Placement_Repository::META_SIZE, '300x250' );

		$package = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Premium package',
			)
		);

		add_post_meta( $package, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		add_post_meta( $package, Package_Repository::META_PLACEMENT_ID, $sidebar );
		update_post_meta( $package, Package_Repository::META_DURATION_DAYS, 14 );
		update_post_meta( $package, Package_Repository::META_PRICE_CENTS, $price_cents );
		update_post_meta( $package, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $package, Package_Repository::META_IS_ACTIVE, $on_sale ? 1 : 0 );

		return array( $package, $sidebar );
	}

	/**
	 * The newest audit context for an event on a campaign.
	 *
	 * Read from the table: the repository's reader does not return context,
	 * and an assertion through it would pass over an empty column.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $event       Audit event.
	 * @return array<string, mixed>
	 */
	private function audit_context( int $campaign_id, string $event ): array {
		global $wpdb;

		$context = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT context FROM %i WHERE object_type = %s AND object_id = %d AND event = %s ORDER BY id DESC LIMIT 1',
				( new Audit_Repository() )->table_name(),
				'campaign',
				$campaign_id,
				$event
			)
		);

		$this->assertIsString( $context, "No {$event} row was recorded, so this assertion covers nothing." );

		return (array) json_decode( $context, true );
	}

	/**
	 * The whole path of an upgrade: proposed, waiting, approved, applied.
	 *
	 * @return void
	 */
	public function test_an_upgrade_changes_nothing_until_approved_then_applies_the_package(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$start       = ( new Campaign_Repository() )->start_ts( $campaign_id );
		$end         = ( new Campaign_Repository() )->end_ts( $campaign_id );

		list( $premium, $sidebar ) = $this->premium_package();

		$this->allow( Settings_Schema::structural_edit_keys() );

		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'package_id' => $premium ) ) );
		$this->assertTrue( $this->changes->submit( $campaign_id ) );

		// Waiting for review, the campaign keeps running on what it bought.
		$campaigns = new Campaign_Repository();
		$this->assertSame( $this->package_id, $campaigns->package_id( $campaign_id ) );
		$this->assertSame( 45000, $campaigns->budget_cents( $campaign_id ) );
		$this->assertSame( array( $this->placement_id ), $campaigns->placement_ids( $campaign_id ) );
		$this->assertSame( $end, $campaigns->end_ts( $campaign_id ) );

		$expected_prices = array(
			'from_package'  => $this->package_id,
			'from_cents'    => 45000,
			'from_currency' => 'USD',
			'to_package'    => $premium,
			'to_cents'      => 90000,
			'to_currency'   => 'USD',
		);

		$this->assertSame( $expected_prices, $this->audit_context( $campaign_id, 'campaign.changes_requested' )['package_change'] ?? null );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		// Exactly what buying the package at creation writes.
		$placements = $campaigns->placement_ids( $campaign_id );
		sort( $placements );
		$expected = array( $this->placement_id, $sidebar );
		sort( $expected );

		$this->assertSame( $premium, $campaigns->package_id( $campaign_id ) );
		$this->assertSame( 90000, $campaigns->budget_cents( $campaign_id ) );
		$this->assertSame( 'USD', $campaigns->currency( $campaign_id ) );
		$this->assertSame( $expected, $placements, 'The new package\'s placements were not applied.' );
		$this->assertSame( $start, $campaigns->start_ts( $campaign_id ), 'A package change moved the start.' );
		$this->assertSame( Campaign_Rules::fixed_end_ts( $start, 14, wp_timezone()->getName() ), $campaigns->end_ts( $campaign_id ), 'The fixed-length end was not derived.' );

		// The old price is recorded before approval overwrote it.
		$this->assertSame( $expected_prices, $this->audit_context( $campaign_id, 'campaign.changes_approved' )['package_change'] ?? null );
	}

	/**
	 * A package that is not on sale, or cannot be bought, is refused where a
	 * hand-built post arrives, and nothing is staged.
	 *
	 * @return void
	 */
	public function test_a_package_that_cannot_be_bought_is_refused(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		list( $retired )  = $this->premium_package( 90000, false );
		list( $unpriced ) = $this->premium_package();

		// On sale, but with no currency: creation refuses it as misconfigured.
		delete_post_meta( $unpriced, Package_Repository::META_CURRENCY );

		$this->allow( Settings_Schema::structural_edit_keys() );

		$refused = $this->changes->stage( $campaign_id, array( 'package_id' => $retired ) );

		$this->assertWPError( $refused );
		$this->assertContains(
			Live_Edit_Rules::ERROR_PACKAGE_NOT_OFFERED,
			array_column( (array) ( $refused->get_error_data()['problems'] ?? array() ), 'code' ),
			'A retired package was refused for some other reason, so this proves nothing.'
		);

		$this->assertWPError( $this->changes->stage( $campaign_id, array( 'package_id' => $unpriced ) ), 'A package creation refuses was accepted as an upgrade.' );
		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ), 'A refused package was staged anyway.' );
		$this->assertSame( $this->package_id, ( new Campaign_Repository() )->package_id( $campaign_id ) );
	}

	/**
	 * An upgrade brings every placement it sells, and a placement list posted
	 * beside it is ignored: as at creation, the package decides them.
	 *
	 * @return void
	 */
	public function test_the_package_decides_the_placements(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		list( $premium, $sidebar ) = $this->premium_package();

		$this->allow( Settings_Schema::structural_edit_keys() );

		$this->assertSame(
			array( 'package_id' => $premium ),
			$this->changes->stage(
				$campaign_id,
				array(
					'package_id'    => $premium,
					'placement_ids' => array( $sidebar ),
				)
			),
			'A hand-picked placement list rode along with the package.'
		);

		$this->assertTrue( $this->changes->submit( $campaign_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		$placements = ( new Campaign_Repository() )->placement_ids( $campaign_id );
		sort( $placements );
		$expected = array( $this->placement_id, $sidebar );
		sort( $expected );

		$this->assertSame( $expected, $placements, 'The upgrade did not bring all of its placements.' );
	}

	/**
	 * Creation's rule on a scheduled campaign: a fixed package's end follows
	 * the start, because the end field is not posted for one. Putting the
	 * start back leaves nothing to review.
	 *
	 * @return void
	 */
	public function test_a_new_start_on_a_fixed_package_carries_its_end(): void {
		wp_set_current_user( $this->advertiser );

		$start       = ( new \DateTimeImmutable( '+10 days midnight', wp_timezone() ) )->getTimestamp();
		$end         = Campaign_Rules::fixed_end_ts( $start, 30, wp_timezone()->getName() );
		$campaign_id = $this->running_campaign( Post_Statuses::SCHEDULED, $start, $end );
		$later       = ( new \DateTimeImmutable( '+15 days midnight', wp_timezone() ) )->getTimestamp();

		$this->allow( array( Settings_Schema::EDIT_SCHEDULE ) );

		$staged = $this->changes->stage( $campaign_id, array( 'start_ts' => $later ) );

		$this->assertIsArray( $staged );
		$this->assertSame( Campaign_Rules::fixed_end_ts( $later, 30, wp_timezone()->getName() ), $staged['end_ts'] ?? null, 'The end stayed behind when the start moved.' );

		$this->assertSame( array(), $this->changes->stage( $campaign_id, array( 'start_ts' => $start ) ), 'Putting the start back left an end change behind.' );
	}

	/**
	 * A downgrade whose derived end has already passed is refused, and said
	 * so in words — including on a site where the dates are not the
	 * advertiser's to change, where the end is implied rather than posted.
	 *
	 * @return void
	 */
	public function test_a_downgrade_that_would_end_in_the_past_is_refused_in_words(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->running_campaign( Post_Statuses::LIVE, time() - 20 * self::DAY, time() + 9 * self::DAY );

		list( $short ) = $this->premium_package();

		$this->allow( array( Settings_Schema::EDIT_PACKAGE ) );

		$refused = $this->changes->stage( $campaign_id, array( 'package_id' => $short ) );

		$this->assertWPError( $refused );
		$this->assertContains( Live_Edit_Rules::ERROR_END_IN_PAST, array_column( (array) ( $refused->get_error_data()['problems'] ?? array() ), 'code' ) );
		$this->assertSame( array(), $this->requests->pending_edits( $campaign_id ) );

		$worded = Campaign_Change_Form::refusal( $refused );

		$this->assertSame( Live_Edit_Rules::ERROR_END_IN_PAST, $worded->get_error_code() );
		$this->assertStringContainsString( 'already passed', $worded->get_error_message() );
		$this->assertSame( $worded->get_error_message(), Campaign_Actions::error_message( $worded->get_error_code() ), 'The banner would say something else.' );
	}

	/**
	 * Where only the package may change, the end it implies is still shown to
	 * the advertiser and the reviewer, and it is what approval writes.
	 *
	 * @return void
	 */
	public function test_the_end_a_package_implies_is_shown_before_it_is_written(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->running_campaign( Post_Statuses::LIVE, time() - self::DAY, time() + 29 * self::DAY );
		$start       = ( new Campaign_Repository() )->start_ts( $campaign_id );

		list( $premium ) = $this->premium_package();

		$this->allow( array( Settings_Schema::EDIT_PACKAGE ) );

		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'package_id' => $premium ) ) );

		$fields = array_column( $this->changes->draft_summary( $campaign_id ), 'field' );

		$this->assertContains( 'package_id', $fields );
		$this->assertContains( 'end_ts', $fields, 'The reviewer would not see the end move.' );

		$this->assertTrue( $this->changes->submit( $campaign_id ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		$this->assertSame( Campaign_Rules::fixed_end_ts( $start, 14, wp_timezone()->getName() ), ( new Campaign_Repository() )->end_ts( $campaign_id ) );
	}

	/**
	 * The reviewer is told an upgrade changes the ad sizes and what it does
	 * to the price. The warning used to look for a placement row only.
	 *
	 * @return void
	 */
	public function test_the_reviewer_sees_the_sizes_and_the_price_move(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		list( $premium ) = $this->premium_package();

		$this->allow( Settings_Schema::structural_edit_keys() );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'package_id' => $premium ) ) );
		$this->assertTrue( $this->changes->submit( $campaign_id ) );

		$facts = $this->changes->pending_review_facts( $campaign_id );

		$this->assertTrue( $facts['structural'], 'A package change was not flagged as changing the sizes.' );
		$this->assertStringContainsString( 'USD 450.00', $facts['price'] );
		$this->assertStringContainsString( 'USD 900.00', $facts['price'] );
		$this->assertStringContainsString( '+USD 450.00', $facts['price'] );
	}
}
