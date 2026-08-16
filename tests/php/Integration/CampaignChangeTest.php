<?php
/**
 * Advertiser-proposed changes to a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Campaign_Change_Actions;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Settings_Schema;
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
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_UnitTestCase;

/**
 * The promise this workflow makes is that a running campaign keeps serving the
 * approved version until staff say otherwise, so that is what most of these
 * assert: not merely that a proposal was stored, but that the campaign itself
 * did not move.
 */
final class CampaignChangeTest extends WP_UnitTestCase {
	use CampaignEditorFixtures;

	private const DAY = 86400;

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
	 * Change workflow.
	 *
	 * @var Campaign_Change_Manager
	 */
	private Campaign_Change_Manager $changes;

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

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

		$container       = Plugin::instance()->container();
		$this->editor    = $container->get( Campaign_Editor::class );
		$this->actions   = $container->get( Campaign_Actions::class );
		$this->changes   = $container->get( Campaign_Change_Manager::class );
		$this->settings  = $container->get( Settings::class );
		$this->campaigns = $container->get( Campaign_Repository::class );

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();
	}

	/**
	 * Settings must not leak into later tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		delete_option( Settings::OPTION );

		parent::tear_down();
	}

	/**
	 * Enables an allowlist of live-edit fields.
	 *
	 * @param array<int, string> $fields Settings_Schema::edit_keys() subset.
	 * @return void
	 */
	private function allow( array $fields ): void {
		$document = $this->settings->get();

		foreach ( Settings_Schema::edit_keys() as $key ) {
			$document['live_edits'][ $key ] = in_array( $key, $fields, true );
		}

		$this->assertTrue( $this->settings->save( $document ) );
	}

	/**
	 * A campaign already running.
	 *
	 * @param string $title Campaign title.
	 * @return int
	 */
	private function running_campaign( string $title = 'Running flight' ): int {
		$campaign_id = $this->complete_campaign( $title );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, time() - self::DAY );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, time() + self::DAY );

		return $campaign_id;
	}

	/**
	 * Stages a proposal and sends it for review, the way the wizard does.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $proposed    Fields.
	 * @return void
	 */
	private function propose( int $campaign_id, array $proposed ): void {
		$this->assertIsArray( $this->changes->stage( $campaign_id, $proposed ) );
		$this->assertTrue( $this->changes->submit( $campaign_id ) );
	}

	/**
	 * Edits cannot be approved onto a campaign that stopped running.
	 *
	 * A proposal can sit in the review queue while the campaign underneath it
	 * completes or is cancelled. Approving it then rewrites the title and the
	 * date window of a finished campaign, busts the fill cache for it, and
	 * records the change in the audit log as applied — all against something no
	 * longer serving. The status is therefore re-checked at approval and the
	 * staged edits are dropped when the campaign transitions.
	 *
	 * @return void
	 */
	public function test_edits_cannot_be_approved_after_the_campaign_stops(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Renamed mid-flight' ) );

		$this->assertTrue( $this->campaigns->has_pending_edits( $campaign_id ) );

		// The campaign ends underneath the queued proposal.
		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::COMPLETE,
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$result = $this->changes->approve( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_not_running', $result->get_error_code() );
		$this->assertSame( 'Running flight', get_post_field( 'post_title', $campaign_id ) );
	}

	/**
	 * A transition drops staged edits along with the action request.
	 *
	 * @return void
	 */
	public function test_a_transition_clears_staged_edits(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Renamed mid-flight' ) );

		$this->assertTrue( $this->campaigns->has_pending_edits( $campaign_id ) );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::CANCELLED,
			)
		);

		do_action( 'aggr_campaign_transitioned', $campaign_id );

		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * With nothing enabled the workflow refuses, whatever is posted.
	 *
	 * @return void
	 */
	public function test_the_feature_is_absent_until_a_field_is_enabled(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array() );

		$this->assertFalse( $this->changes->accepts_changes( $campaign_id ) );

		$result = $this->changes->stage( $campaign_id, array( 'title' => 'Renamed' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_live_edits_disabled', $result->get_error_code() );
	}

	/**
	 * A field the site did not enable cannot be changed by posting it anyway.
	 *
	 * This is the permission boundary, so it is asserted at the workflow rather
	 * than only in the pure rules: the form not rendering an input is a UI
	 * detail, and a hand-built POST does not use the form.
	 *
	 * @return void
	 */
	public function test_a_disabled_field_cannot_be_smuggled_in(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		$result = $this->changes->stage(
			$campaign_id,
			array(
				'title'    => 'Renamed',
				'end_ts'   => time() + ( 30 * self::DAY ),
				'start_ts' => time() + self::DAY,
			)
		);

		$this->assertIsArray( $result );
		$this->assertSame( array( 'title' => 'Renamed' ), $result );
		$this->assertSame( array( 'title' => 'Renamed' ), $this->campaigns->pending_edits( $campaign_id ) );
	}

	/**
	 * Requesting changes does not change the campaign.
	 *
	 * @return void
	 */
	public function test_a_request_leaves_the_running_campaign_untouched(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id  = $this->running_campaign( 'Original name' );
		$original_end = $this->campaigns->end_ts( $campaign_id );

		$this->allow( array( Settings_Schema::EDIT_TITLE, Settings_Schema::EDIT_SCHEDULE ) );

		$this->propose(
			$campaign_id,
			array(
				'title'  => 'Proposed name',
				'end_ts' => time() + ( 30 * self::DAY ),
			) 
		);

		$this->assertSame( 'Original name', get_post_field( 'post_title', $campaign_id ) );
		$this->assertSame( $original_end, $this->campaigns->end_ts( $campaign_id ) );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
	}

	/**
	 * Approval writes the values and clears the proposal.
	 *
	 * @return void
	 */
	public function test_approval_applies_the_change(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign( 'Original name' );
		$new_end     = time() + ( 30 * self::DAY );

		$this->allow( array( Settings_Schema::EDIT_TITLE, Settings_Schema::EDIT_SCHEDULE ) );
		$this->propose(
			$campaign_id,
			array(
				'title'  => 'Approved name',
				'end_ts' => $new_end,
			) 
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertTrue( $this->changes->approve( $campaign_id ) );
		$this->assertSame( 'Approved name', get_post_field( 'post_title', $campaign_id ) );
		$this->assertSame( $new_end, $this->campaigns->end_ts( $campaign_id ) );
		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
	}

	/**
	 * A destination change repoints the click without touching the artwork.
	 *
	 * @return void
	 */
	public function test_approving_a_destination_change_repoints_the_creative(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$creatives   = Plugin::instance()->container()->get( Creative_Repository::class );
		$creative    = $creatives->for_campaign( $campaign_id )[0];
		$creative_id = (int) $creative['id'];
		$size        = (string) $creative['size'];

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$this->propose( $campaign_id, array( 'click_urls' => array( $creative_id => 'https://example.com/new-landing' ) ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		$updated = $creatives->details( $creative_id );

		$this->assertSame( 'https://example.com/new-landing', $updated['click_url'] );
		$this->assertSame( $size, $updated['size'], 'Approving a destination change must not touch the reviewed size.' );
	}

	/**
	 * A hostile destination is refused before it is ever stored.
	 *
	 * @return void
	 */
	public function test_a_javascript_destination_is_refused(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$creative_id = (int) Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $campaign_id )[0]['id'];

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$result = $this->changes->stage( $campaign_id, array( 'click_urls' => array( $creative_id => 'javascript:alert(1)' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_live_edit_invalid', $result->get_error_code() );
		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * Rejection keeps the campaign as it was and records the reason.
	 *
	 * @return void
	 */
	public function test_rejection_keeps_the_campaign_and_records_feedback(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign( 'Original name' );

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Rejected name' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertTrue( $this->changes->reject( $campaign_id, 'Please keep the original name.' ) );
		$this->assertSame( 'Original name', get_post_field( 'post_title', $campaign_id ) );
		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
		$this->assertStringContainsString( 'original name', $this->campaigns->review_notes( $campaign_id ) );
	}

	/**
	 * Rejecting without a reason is refused, and the proposal survives.
	 *
	 * @return void
	 */
	public function test_rejection_requires_feedback(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Renamed' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$result = $this->changes->reject( $campaign_id, '   ' );

		$this->assertWPError( $result );
		$this->assertTrue( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * An advertiser may take their own proposal back.
	 *
	 * @return void
	 */
	public function test_the_advertiser_can_withdraw_a_proposal(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Renamed' ) );

		$this->assertTrue( $this->changes->withdraw( $campaign_id ) );
		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * One proposal at a time, so a reviewer is never deciding a moving target.
	 *
	 * @return void
	 */
	public function test_a_second_proposal_is_refused_while_one_is_pending(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'First' ) );

		$second = $this->changes->stage( $campaign_id, array( 'title' => 'Second' ) );

		$this->assertWPError( $second );
		$this->assertSame( 'aggr_edits_pending', $second->get_error_code() );
		$this->assertSame( array( 'title' => 'First' ), $this->campaigns->pending_edits( $campaign_id ) );
	}

	/**
	 * Another organization cannot propose changes to somebody else's campaign.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_propose_changes(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		wp_set_current_user( $this->other_advertiser );
		$result = $this->changes->stage( $campaign_id, array( 'title' => 'Not yours' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertFalse( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * An advertiser cannot approve their own proposal.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_approve_their_own_change(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign( 'Original name' );

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Self approved' ) );

		$result = $this->changes->approve( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( 'Original name', get_post_field( 'post_title', $campaign_id ) );
	}

	/**
	 * A draft has no proposal workflow — it is simply editable.
	 *
	 * @return void
	 */
	public function test_a_draft_does_not_use_this_workflow(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Still a draft' );

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		$this->assertFalse( $this->changes->accepts_changes( $campaign_id ) );

		$result = $this->changes->stage( $campaign_id, array( 'title' => 'Renamed' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_not_running', $result->get_error_code() );
	}

	/**
	 * A proposal that has gone stale is refused at approval rather than
	 * written. Time passes between a request and a decision, and an end date
	 * that was next week when it was asked for can be last week by the time
	 * somebody clicks approve.
	 *
	 * @return void
	 */
	public function test_a_stale_proposal_cannot_be_approved(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$original    = $this->campaigns->end_ts( $campaign_id );

		$this->allow( array( Settings_Schema::EDIT_SCHEDULE ) );
		$this->propose( $campaign_id, array( 'end_ts' => time() + ( 7 * self::DAY ) ) );

		// The proposal sat in the queue until its own end date passed.
		$this->campaigns->set_pending_edits( $campaign_id, array( 'end_ts' => time() - self::DAY ), $this->advertiser, true );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$result = $this->changes->approve( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_live_edit_stale', $result->get_error_code() );
		$this->assertSame( $original, $this->campaigns->end_ts( $campaign_id ) );
	}

	/**
	 * The staff decision edge refuses an unknown decision rather than guessing.
	 *
	 * @return void
	 */
	public function test_an_unknown_staff_decision_is_refused(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Renamed' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$actions = Plugin::instance()->container()->get( Campaign_Change_Actions::class );
		$result  = $actions->process( $campaign_id, 'approve_quietly' );

		$this->assertWPError( $result );
		$this->assertTrue( $this->campaigns->has_pending_edits( $campaign_id ) );
	}

	/**
	 * A proposal still being assembled is invisible to the review team.
	 *
	 * Without the submitted flag a reviewer would see — and could approve —
	 * a half-finished edit the advertiser was still moving between steps.
	 *
	 * @return void
	 */
	public function test_a_staged_proposal_is_not_yet_visible_to_review(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'title' => 'Half finished' ) ) );

		$this->assertNotSame( array(), $this->changes->draft_summary( $campaign_id ) );
		$this->assertSame( array(), $this->changes->pending_summary( $campaign_id ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$result = $this->changes->approve( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_no_pending_edits', $result->get_error_code() );
	}

	/**
	 * Successive steps accumulate rather than replacing each other.
	 *
	 * @return void
	 */
	public function test_steps_accumulate_into_one_proposal(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$new_end     = time() + ( 30 * self::DAY );

		$this->allow( array( Settings_Schema::EDIT_TITLE, Settings_Schema::EDIT_SCHEDULE ) );

		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'title' => 'Renamed' ) ) );
		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'end_ts' => $new_end ) ) );

		$this->assertSame(
			array(
				'title'  => 'Renamed',
				'end_ts' => $new_end,
			),
			$this->campaigns->pending_edits( $campaign_id )
		);
	}

	/**
	 * Putting a field back to its original value removes it from the proposal
	 * rather than leaving a no-op row for a reviewer to read and dismiss.
	 *
	 * @return void
	 */
	public function test_reverting_a_field_drops_it_from_the_proposal(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign( 'Original name' );

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );

		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'title' => 'Renamed' ) ) );
		$this->assertSame( array( 'title' => 'Renamed' ), $this->campaigns->pending_edits( $campaign_id ) );

		$this->assertIsArray( $this->changes->stage( $campaign_id, array( 'title' => 'Original name' ) ) );
		$this->assertSame( array(), $this->campaigns->pending_edits( $campaign_id ) );
	}

	/**
	 * A malformed value is refused at the step that produced it.
	 *
	 * @return void
	 */
	public function test_a_bad_value_is_refused_at_its_own_step(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$creative_id = (int) Plugin::instance()->container()->get( Creative_Repository::class )->for_campaign( $campaign_id )[0]['id'];

		$this->allow( array( Settings_Schema::EDIT_DESTINATION ) );

		$result = $this->changes->stage( $campaign_id, array( 'click_urls' => array( $creative_id => 'javascript:alert(1)' ) ) );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->campaigns->pending_edits( $campaign_id ) );
	}

	/**
	 * Pause and cancel are requestable on a live campaign, because staff can
	 * drive them and the advertiser cannot.
	 *
	 * Derived from Transition_Table, so a staff edge added later becomes
	 * requestable without anyone remembering to list it here.
	 *
	 * @return void
	 */
	public function test_staff_only_transitions_are_requestable(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$actions = array_column( $this->changes->requestable_actions( $campaign_id ), 'action' );

		$this->assertContains( Post_Statuses::PAUSED, $actions );
		$this->assertContains( Post_Statuses::CANCELLED, $actions );
	}

	/**
	 * A request records the ask and changes nothing about the campaign.
	 *
	 * @return void
	 */
	public function test_a_request_does_not_change_the_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->assertTrue( $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, 'Product sold out.' ) );

		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
		$this->assertSame( Post_Statuses::PAUSED, $this->campaigns->action_request( $campaign_id )['action'] );
		$this->assertSame( 'Product sold out.', $this->campaigns->action_request( $campaign_id )['reason'] );
	}

	/**
	 * A request needs a reason — staff are being asked to do something and
	 * deserve to know why.
	 *
	 * @return void
	 */
	public function test_a_request_requires_a_reason(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$result = $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, '   ' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->campaigns->action_request( $campaign_id ) );
	}

	/**
	 * A transition the advertiser could perform themselves is not requestable,
	 * so there is never a slow path to something with a fast one.
	 *
	 * @return void
	 */
	public function test_a_self_service_transition_is_not_requestable(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Scheduled soon' );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::SCHEDULED,
			)
		);

		$actions = array_column( $this->changes->requestable_actions( $campaign_id ), 'action' );

		$this->assertNotContains( Post_Statuses::CANCELLED, $actions, 'An advertiser can already cancel a scheduled campaign.' );
	}

	/**
	 * Requesting something outside that list is refused rather than stored.
	 *
	 * @return void
	 */
	public function test_an_unrequestable_action_is_refused(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$result = $this->changes->request_action( $campaign_id, Post_Statuses::APPROVED, 'Please approve.' );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_action_not_requestable', $result->get_error_code() );
	}

	/**
	 * Staff declining clears the request and tells the advertiser why.
	 *
	 * @return void
	 */
	public function test_staff_can_decline_a_request(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$this->assertTrue( $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, 'Please pause.' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertTrue( $this->changes->resolve_action( $campaign_id, 'Your flight ends in two days anyway.' ) );
		$this->assertSame( array(), $this->campaigns->action_request( $campaign_id ) );
		$this->assertStringContainsString( 'two days', $this->campaigns->review_notes( $campaign_id ) );
		$this->assertSame( Post_Statuses::LIVE, get_post_status( $campaign_id ) );
	}

	/**
	 * A request cannot outlive the status it asked about.
	 *
	 * @return void
	 */
	public function test_a_request_clears_when_staff_act(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();
		$this->assertTrue( $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, 'Please pause.' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );

		$this->assertTrue(
			Plugin::instance()->container()->get( Campaign_State_Machine::class )->apply( $campaign_id, Post_Statuses::PAUSED )
		);

		$this->assertSame( Post_Statuses::PAUSED, get_post_status( $campaign_id ) );
		$this->assertSame( array(), $this->campaigns->action_request( $campaign_id ), 'A request must not survive the transition it asked for.' );
	}

	/**
	 * An advertiser may take their own request back.
	 *
	 * @return void
	 */
	public function test_the_advertiser_can_withdraw_a_request(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->assertTrue( $this->changes->request_action( $campaign_id, Post_Statuses::CANCELLED, 'Changed our minds.' ) );
		$this->assertTrue( $this->changes->withdraw_action( $campaign_id ) );
		$this->assertSame( array(), $this->campaigns->action_request( $campaign_id ) );
	}

	/**
	 * Another organization cannot request anything about someone else's campaign.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_request_an_action(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		wp_set_current_user( $this->other_advertiser );
		$result = $this->changes->request_action( $campaign_id, Post_Statuses::PAUSED, 'Not mine.' );

		$this->assertWPError( $result );
		$this->assertSame( array(), $this->campaigns->action_request( $campaign_id ) );
	}

	/**
	 * Every decision is auditable.
	 *
	 * @return void
	 */
	public function test_decisions_are_audited(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->running_campaign();

		$this->allow( array( Settings_Schema::EDIT_TITLE ) );
		$this->propose( $campaign_id, array( 'title' => 'Audited' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => Roles::REVIEWER ) ) );
		$this->assertTrue( $this->changes->approve( $campaign_id ) );

		$events = array_column( ( new Audit_Repository() )->for_object( 'campaign', $campaign_id, $this->org_id ), 'event' );

		$this->assertContains( 'campaign.changes_requested', $events );
		$this->assertContains( 'campaign.changes_approved', $events );
	}
}
