<?php
/**
 * Withdrawing a submitted campaign back to an editable draft.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use WP_UnitTestCase;

/**
 * The portal told advertisers they could withdraw a submission long before
 * there was a control for it. These assert the control, and the guard that
 * stops it happening under an open review.
 */
final class CampaignWithdrawalTest extends WP_UnitTestCase {
	use CampaignEditorFixtures;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Unrelated advertiser user id.
	 *
	 * @var int
	 */
	private int $other_advertiser;

	/**
	 * Owning organization id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Active placement id.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Active package id.
	 *
	 * @var int
	 */
	private int $package_id;

	/**
	 * Shared draft workflow.
	 *
	 * @var Campaign_Editor
	 */
	private Campaign_Editor $editor;

	/**
	 * HTML form delivery.
	 *
	 * @var Campaign_Actions
	 */
	private Campaign_Actions $actions;

	/**
	 * Two tenants, one placement, one package.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser       = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->other_advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id           = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => 'Launch package',
			)
		);

		add_post_meta( $this->package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 30 );
		update_post_meta( $this->package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $this->package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 1 );

		$container     = Plugin::instance()->container();
		$this->editor  = $container->get( Campaign_Editor::class );
		$this->actions = $container->get( Campaign_Actions::class );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Withdrawal returns a submitted campaign to an editable draft.
	 *
	 * The portal told advertisers they could do this long before there was a
	 * control for it, so the assertion that matters is `editable` — the flag
	 * the wizard is actually gated on — and not merely the status.
	 *
	 * @return void
	 */
	public function test_withdrawal_reopens_the_campaign_for_editing(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Withdraw me' );

		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign_id ) );

		$this->assertTrue( $this->actions->process_withdraw( $campaign_id ) );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign_id ) );

		$this->assertContains(
			get_post_status( $campaign_id ),
			Post_Statuses::advertiser_editable(),
			'A withdrawn campaign must be editable again, or the button only moves it somewhere quieter.'
		);

		$view = Plugin::instance()->container()->get( View_Data::class )->campaign( $campaign_id );
		$this->assertTrue( $view['editable'] );
		$this->assertFalse( $view['can_withdraw'] );
	}

	/**
	 * A withdrawn campaign can be edited and submitted again.
	 *
	 * @return void
	 */
	public function test_a_withdrawn_campaign_can_be_resubmitted(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Round trip' );

		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );
		$this->assertTrue( $this->actions->process_withdraw( $campaign_id ) );

		$campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		$this->assertIsInt(
			$this->actions->process_save(
				$campaign_id,
				array(
					'title'         => 'Corrected title',
					'placement_ids' => array( $this->placement_id ),
				),
				$campaigns->autosave_revision( $campaign_id )
			)
		);

		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign_id ) );
		$this->assertSame( 'Corrected title', get_post_field( 'post_title', $campaign_id ) );
	}

	/**
	 * A campaign under review has no withdrawal edge at all.
	 *
	 * Named for the mechanism that actually refuses it. An earlier version of
	 * this test claimed to be exercising the `unclaimed` guard and was not:
	 * once a reviewer claims a campaign its status is `review`, and
	 * Transition_Table has no review → draft edge, so the guard never runs.
	 * Deleting the guard left the test green. The guard's own case is the test
	 * below.
	 *
	 * @return void
	 */
	public function test_a_campaign_under_review_has_no_withdrawal_edge(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Being reviewed' );
		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );

		$reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		wp_set_current_user( $reviewer );
		$this->assertTrue(
			Plugin::instance()->container()->get( Campaign_State_Machine::class )->apply( $campaign_id, Post_Statuses::REVIEW )
		);

		wp_set_current_user( $this->advertiser );
		$result = $this->actions->process_withdraw( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_illegal_transition', $result->get_error_code() );
		$this->assertSame( Post_Statuses::REVIEW, get_post_status( $campaign_id ) );
	}

	/**
	 * The `unclaimed` guard refuses withdrawal while a reviewer is recorded.
	 *
	 * The reviewer is written directly because that is exactly the state the
	 * guard exists to describe: a campaign still sitting in `submitted` that
	 * someone has nonetheless taken. Pulling it out from under them is how two
	 * people end up working from different versions of it.
	 *
	 * @return void
	 */
	public function test_withdrawal_is_refused_while_a_reviewer_is_recorded(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Quietly claimed' );
		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );

		$reviewer = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		update_post_meta( $campaign_id, Campaign_Repository::META_REVIEWED_BY, $reviewer );

		$result = $this->actions->process_withdraw( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_claimed', $result->get_error_code() );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign_id ) );
	}

	/**
	 * Withdrawal reauthorizes the object, like every other transition.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_withdraw_the_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Tenant protected withdrawal' );
		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );

		wp_set_current_user( $this->other_advertiser );
		$result = $this->actions->process_withdraw( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign_id ) );
	}

	/**
	 * A draft has nothing to withdraw from, and the offer is not made.
	 *
	 * @return void
	 */
	public function test_a_draft_offers_no_withdrawal(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Still a draft' );

		$view = Plugin::instance()->container()->get( View_Data::class )->campaign( $campaign_id );

		$this->assertFalse( $view['can_withdraw'] );
		$this->assertWPError( $this->actions->process_withdraw( $campaign_id ) );
	}

	/**
	 * An advertiser may end their own draft.
	 *
	 * Cancellation, not deletion: the row survives so the audit trail and any
	 * delivery figures survive with it.
	 *
	 * @return void
	 */
	public function test_an_advertiser_can_cancel_a_draft(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Never mind' );

		$this->assertTrue( $this->actions->process_cancel( $campaign_id ) );
		$this->assertSame( Post_Statuses::CANCELLED, get_post_status( $campaign_id ) );
		$this->assertNotNull( get_post( $campaign_id ), 'Cancelling must not delete the record.' );
	}

	/**
	 * A cancelled campaign is finished — there is nowhere left to go.
	 *
	 * @return void
	 */
	public function test_cancellation_is_terminal(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Done with this' );

		$this->assertTrue( $this->actions->process_cancel( $campaign_id ) );
		$this->assertSame( array(), Transition_Table::targets_from( Post_Statuses::CANCELLED ) );
		$this->assertWPError( $this->actions->process_submit( $campaign_id ) );
	}

	/**
	 * An advertiser may cancel a scheduled campaign, which has not started.
	 *
	 * @return void
	 */
	public function test_an_advertiser_can_cancel_a_scheduled_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Not started yet' );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::SCHEDULED,
			)
		);

		$this->assertTrue( $this->actions->process_cancel( $campaign_id ) );
		$this->assertSame( Post_Statuses::CANCELLED, get_post_status( $campaign_id ) );
	}

	/**
	 * A campaign that is actually serving is staff-only.
	 *
	 * Pulling a running advertisement is a conversation rather than a button,
	 * and Transition_Table says so by naming only staff on that edge. The
	 * portal derives its control from the table, so this is the one assertion
	 * that keeps the button and the policy in step.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_cancel_a_live_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Already running' );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$result = $this->actions->process_cancel( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );

		$view = Plugin::instance()->container()->get( View_Data::class )->campaign( $campaign_id );
		$this->assertFalse( $view['can_cancel'], 'The control must not be offered where the transition table refuses it.' );
	}

	/**
	 * Another organization cannot cancel somebody else's campaign.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_cancel_the_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Tenant protected' );

		wp_set_current_user( $this->other_advertiser );
		$result = $this->actions->process_cancel( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign_id ) );
	}

	/**
	 * The offer follows the transition table, not a list kept in the portal.
	 *
	 * @return void
	 */
	public function test_the_cancel_control_is_offered_on_a_draft(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Offer me' );

		$view = Plugin::instance()->container()->get( View_Data::class )->campaign( $campaign_id );

		$this->assertTrue( $view['can_cancel'] );
		$this->assertSame( 'Delete campaign', $view['cancel_label'] );
	}
}
