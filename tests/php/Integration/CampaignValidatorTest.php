<?php
/**
 * The campaign validator, against real data.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Campaign_Validator;
use WP_Error;
use WP_UnitTestCase;

/**
 * A campaign is only submittable when every part of it holds together, and
 * the parts live in four different post types. This is where that is checked.
 */
final class CampaignValidatorTest extends WP_UnitTestCase {

	/**
	 * The validator under test.
	 *
	 * @var Campaign_Validator
	 */
	private Campaign_Validator $validator;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * An advertiser in that organization.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * An active 728x90 placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * An active package covering that placement.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Builds an organization, an advertiser and one active placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->validator = Plugin::instance()->container()->get( Campaign_Validator::class );

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->placement_id = $this->placement( '728x90', true );
		$this->package_id   = $this->package( array( $this->placement_id ) );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Creates a placement.
	 *
	 * @param string $size   Declared size.
	 * @param bool   $active Whether it is offered.
	 * @return int
	 */
	private function placement( string $size, bool $active ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $id, Placement_Repository::META_SIZE, $size );
		update_post_meta( $id, Placement_Repository::META_IS_ACTIVE, $active ? 1 : 0 );

		return $id;
	}

	/**
	 * Creates a campaign with a valid window and the given placements.
	 *
	 * @param array<int, int> $placement_ids Placements to select.
	 * @return int
	 */
	private function campaign( array $placement_ids ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->advertiser,
			)
		);

		update_post_meta( $id, Campaign_Repository::META_ORG_ID, $this->org_id );
		$start = ( new \DateTimeImmutable( '+7 days', wp_timezone() ) )->setTime( 0, 0, 0 );
		$end   = ( new \DateTimeImmutable( '+14 days', wp_timezone() ) )->setTime( 23, 59, 59 );

		update_post_meta( $id, Campaign_Repository::META_START_TS, $start->getTimestamp() );
		update_post_meta( $id, Campaign_Repository::META_END_TS, $end->getTimestamp() );
		update_post_meta( $id, Campaign_Repository::META_PACKAGE_ID, $this->package_id );
		update_post_meta( $id, Campaign_Repository::META_BUDGET_CENTS, 45000 );
		update_post_meta( $id, Campaign_Repository::META_CURRENCY, 'USD' );

		foreach ( $placement_ids as $placement_id ) {
			add_post_meta( $id, Campaign_Repository::META_PLACEMENT_ID, $placement_id );
		}

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $id;
	}

	/**
	 * Creates an active package.
	 *
	 * @param array<int, int> $placement_ids Placements the package covers.
	 * @return int
	 */
	private function package( array $placement_ids ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $id, Package_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $id, Package_Repository::META_CURRENCY, 'USD' );

		foreach ( $placement_ids as $placement_id ) {
			add_post_meta( $id, Package_Repository::META_PLACEMENT_ID, $placement_id );
		}

		return $id;
	}

	/**
	 * Creates a creative on a campaign.
	 *
	 * @param int                  $campaign_id Owning campaign.
	 * @param array<string, mixed> $overrides   Meta overrides.
	 * @return int
	 */
	private function creative( int $campaign_id, array $overrides = array() ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$meta = array_merge(
			array(
				Creative_Repository::META_CAMPAIGN_ID  => $campaign_id,
				Creative_Repository::META_ORG_ID       => $this->org_id,
				Creative_Repository::META_PLACEMENT_ID => $this->placement_id,
				Creative_Repository::META_KIND         => 'image',
				Creative_Repository::META_WIDTH        => 728,
				Creative_Repository::META_HEIGHT       => 90,
				Creative_Repository::META_CLICK_URL    => 'https://example.com/tickets',
			),
			$overrides
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * A complete campaign validates.
	 *
	 * @return void
	 */
	public function test_a_complete_campaign_is_valid(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign );

		$result = $this->validator->validate( $campaign );

		$this->assertTrue( $result->is_valid(), 'Unexpected problems: ' . implode( ', ', $result->codes() ) );
	}

	/**
	 * **Submission now works end to end.**
	 *
	 * The state machine's validator guard has refused every submission until
	 * this point, by design. This is the test that proves the wiring, not just
	 * the validator.
	 *
	 * @return void
	 */
	public function test_a_complete_campaign_can_be_submitted(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign );

		$machine = Plugin::instance()->container()->get( Campaign_State_Machine::class );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign ) );
	}

	/**
	 * An incomplete campaign is refused by the same path, with the reasons.
	 *
	 * @return void
	 */
	public function test_an_incomplete_campaign_cannot_be_submitted(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( array( $this->placement_id ) );

		$machine = Plugin::instance()->container()->get( Campaign_State_Machine::class );
		$result  = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_campaign_invalid', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign ) );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertArrayHasKey( 'problems', $data );
	}

	/**
	 * A campaign with no creative is refused.
	 *
	 * @return void
	 */
	public function test_a_campaign_without_creatives_is_refused(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_NO_CREATIVES )
		);
	}

	/**
	 * A campaign with no placements is refused.
	 *
	 * @return void
	 */
	public function test_a_campaign_without_placements_is_refused(): void {
		$campaign = $this->campaign( array() );
		$this->creative( $campaign );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_NO_PLACEMENTS )
		);
	}

	/**
	 * A placement deactivated while the campaign sat in the queue is caught.
	 *
	 * This is why the validator runs again at approval rather than trusting
	 * the result from submission.
	 *
	 * @return void
	 */
	public function test_a_deactivated_placement_is_caught(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign );

		$this->assertTrue( $this->validator->validate( $campaign )->is_valid() );

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 0 );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_PLACEMENT_INACTIVE )
		);
	}

	/**
	 * A creative whose real dimensions do not match its placement is refused,
	 * and the message says exactly what to change.
	 *
	 * @return void
	 */
	public function test_a_size_mismatch_reports_both_dimensions(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative(
			$campaign,
			array(
				Creative_Repository::META_WIDTH  => 1200,
				Creative_Repository::META_HEIGHT => 400,
			)
		);

		$result = $this->validator->validate( $campaign );

		$this->assertTrue( $result->has( Campaign_Rules::ERROR_CREATIVE_SIZE ) );

		$message = $this->validator->to_wp_error( $result )->get_error_message();

		$this->assertStringContainsString( '1200 × 400', $message );
		$this->assertStringContainsString( '728x90', $message );
	}

	/**
	 * A selected placement with no creative is refused.
	 *
	 * @return void
	 */
	public function test_a_placement_without_a_creative_is_refused(): void {
		$second   = $this->placement( '300x250', true );
		$campaign = $this->campaign( array( $this->placement_id, $second ) );

		$this->creative( $campaign );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_PLACEMENT_UNCOVERED )
		);
	}

	/**
	 * A creative attached to a placement the campaign never selected is
	 * refused, rather than silently ignored.
	 *
	 * @return void
	 */
	public function test_a_creative_for_an_unselected_placement_is_refused(): void {
		$other    = $this->placement( '300x250', true );
		$campaign = $this->campaign( array( $this->placement_id ) );

		$this->creative( $campaign );
		$this->creative( $campaign, array( Creative_Repository::META_PLACEMENT_ID => $other ) );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_CREATIVE_PLACEMENT )
		);
	}

	/**
	 * Only image creatives pass. `code` and `html5` are arbitrary markup on a
	 * public page and require a reviewer.
	 *
	 * @param string $kind A creative kind an advertiser may not submit.
	 * @return void
	 *
	 * @dataProvider data_forbidden_kinds
	 */
	public function test_non_image_creatives_are_refused( string $kind ): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign, array( Creative_Repository::META_KIND => $kind ) );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_CREATIVE_KIND )
		);
	}

	/**
	 * Creative kinds an advertiser may not submit.
	 *
	 * @return array<string, array{string}>
	 */
	public static function data_forbidden_kinds(): array {
		return array(
			'code'  => array( 'code' ),
			'text'  => array( 'text' ),
			'html5' => array( 'html5' ),
			'empty' => array( '' ),
		);
	}

	/**
	 * A destination URL that is missing or hostile is refused.
	 *
	 * @return void
	 */
	public function test_bad_destination_urls_are_refused(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign, array( Creative_Repository::META_CLICK_URL => '' ) );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_CLICK_URL_MISSING )
		);

		$hostile = $this->campaign( array( $this->placement_id ) );
		$this->creative( $hostile, array( Creative_Repository::META_CLICK_URL => 'javascript:alert(1)' ) );

		$this->assertTrue(
			$this->validator->validate( $hostile )->has( Campaign_Rules::ERROR_CLICK_URL_INVALID )
		);
	}

	/**
	 * A suspended organization cannot submit.
	 *
	 * @return void
	 */
	public function test_a_suspended_organization_is_refused(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign );

		update_post_meta( $this->org_id, Org_Repository::META_ORG_STATE, Org_Repository::STATE_SUSPENDED );

		$this->assertTrue(
			$this->validator->validate( $campaign )->has( Campaign_Rules::ERROR_ORG_NOT_ACTIVE )
		);
	}

	/**
	 * An organization with no state set is treated as active.
	 *
	 * Suspension is a decision somebody makes, not a default somebody forgets
	 * to undo.
	 *
	 * @return void
	 */
	public function test_an_organization_without_a_state_is_active(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );
		$this->creative( $campaign );

		$this->assertSame( '', get_post_meta( $this->org_id, Org_Repository::META_ORG_STATE, true ) );
		$this->assertTrue( $this->validator->validate( $campaign )->is_valid() );
	}

	/**
	 * Every problem is reported at once, not one per attempt.
	 *
	 * @return void
	 */
	public function test_all_problems_are_reported_together(): void {
		$campaign = $this->campaign( array( $this->placement_id ) );

		update_post_meta( $campaign, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $this->org_id, Org_Repository::META_ORG_STATE, Org_Repository::STATE_SUSPENDED );

		$this->creative(
			$campaign,
			array(
				Creative_Repository::META_WIDTH     => 1,
				Creative_Repository::META_HEIGHT    => 1,
				Creative_Repository::META_CLICK_URL => 'javascript:alert(1)',
				Creative_Repository::META_KIND      => 'html5',
			)
		);

		$codes = $this->validator->validate( $campaign )->codes();

		foreach ( array(
			Campaign_Rules::ERROR_ORG_NOT_ACTIVE,
			Campaign_Rules::ERROR_START_IN_PAST,
			Campaign_Rules::ERROR_CREATIVE_KIND,
			Campaign_Rules::ERROR_CREATIVE_SIZE,
			Campaign_Rules::ERROR_CLICK_URL_INVALID,
		) as $expected ) {
			$this->assertContains( $expected, $codes );
		}
	}

	/**
	 * A campaign with no package cannot be submitted.
	 *
	 * The wizard refuses to save one without a package, and that was not
	 * enough: its steps are reachable by URL, so skipping step two and posting
	 * the submit form produced a submitted campaign with no package and no
	 * price. It reached the review queue looking complete, and staff would have
	 * approved commercial terms that did not exist.
	 *
	 * @return void
	 */
	public function test_a_campaign_without_a_package_is_rejected(): void {
		$campaign_id = $this->campaign( array( $this->placement_id ) );

		$this->creative( $campaign_id );

		delete_post_meta( $campaign_id, Campaign_Repository::META_PACKAGE_ID );
		delete_post_meta( $campaign_id, Campaign_Repository::META_BUDGET_CENTS );
		delete_post_meta( $campaign_id, Campaign_Repository::META_CURRENCY );

		$result = $this->validator->validate( $campaign_id );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has( Campaign_Rules::ERROR_PACKAGE_MISSING ) );
	}

	/**
	 * A package that has been withdrawn is caught before submission.
	 *
	 * @return void
	 */
	public function test_a_deactivated_package_is_caught(): void {
		$campaign_id = $this->campaign( array( $this->placement_id ) );

		$this->creative( $campaign_id );

		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 0 );

		$result = $this->validator->validate( $campaign_id );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has( Campaign_Rules::ERROR_PACKAGE_UNAVAILABLE ) );
	}

	/**
	 * A package reference without its price snapshot is caught.
	 *
	 * The id and the price are written separately, so a campaign carrying one
	 * without the other is what a half-completed write leaves behind. Checking
	 * only the id would call that campaign complete and let it be approved for
	 * nothing.
	 *
	 * @return void
	 */
	public function test_a_package_without_its_price_snapshot_is_caught(): void {
		$campaign_id = $this->campaign( array( $this->placement_id ) );

		$this->creative( $campaign_id );

		delete_post_meta( $campaign_id, Campaign_Repository::META_BUDGET_CENTS );

		$result = $this->validator->validate( $campaign_id );

		$this->assertFalse( $result->is_valid() );
		$this->assertTrue( $result->has( Campaign_Rules::ERROR_PRICE_MISSING ) );
	}
}
