<?php
/**
 * The state machine, against real WordPress.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Transition_Table;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_State_Machine;
use Aggressive\Ads\Workflow\Transition_Guards;
use WP_Error;
use WP_UnitTestCase;

/**
 * The table says what is legal. This says what actually happens — including
 * the ordering, which is the part that keeps a campaign from being marked live
 * with nothing behind it.
 */
final class CampaignStateMachineTest extends WP_UnitTestCase {

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

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
	 * A staff reviewer.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Sets up roles, an organization and its users.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->campaigns = new Campaign_Repository();
		$this->audit     = new Audit_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		\Aggressive\Ads\Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Builds a state machine with the given guard and effect stand-ins.
	 *
	 * The validator and the publisher land in later phases; injecting them is
	 * how the ordering gets tested before they exist.
	 *
	 * @param array<string, callable> $guards  Guard overrides.
	 * @param array<string, callable> $effects Effect handlers.
	 * @return Campaign_State_Machine
	 */
	private function machine( array $guards = array(), array $effects = array() ): Campaign_State_Machine {
		return new Campaign_State_Machine(
			$this->campaigns,
			$this->audit,
			new Transition_Guards( $this->campaigns, $guards ),
			$effects
		);
	}

	/**
	 * A guard that always passes.
	 *
	 * @return callable
	 */
	private function passing_guard(): callable {
		return static fn (): bool => true;
	}

	/**
	 * Creates a campaign in the given status.
	 *
	 * @param string $status Campaign status.
	 * @return int
	 */
	private function campaign( string $status ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_author' => $this->advertiser,
			)
		);

		update_post_meta( $id, Campaign_Repository::META_ORG_ID, $this->org_id );

		\Aggressive\Ads\Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $id;
	}

	/**
	 * Counts audit rows for a campaign and event.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $event       Event name.
	 * @return int
	 */
	private function audit_rows( int $campaign_id, string $event ): int {
		global $wpdb;

		$table = $this->audit->table_name();

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE object_id = %d AND event = %s",
				$campaign_id,
				$event
			)
		);
	}

	/**
	 * A legal, authorized, guarded transition is applied.
	 *
	 * @return void
	 */
	public function test_a_legal_transition_is_applied(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$machine  = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );
		$this->assertGreaterThan( 0, $this->campaigns->submitted_at( $campaign ) );
		$this->assertSame( 1, $this->audit_rows( $campaign, 'campaign.transitioned' ) );
	}

	/**
	 * **Staff may submit a campaign on an advertiser's behalf.**
	 *
	 * Every holder of `aggr_review_campaigns` is classed as staff, so while
	 * submission was advertiser-only an administrator could not submit
	 * anything — including a campaign they had just created for an advertiser
	 * themselves. The campaign sat in draft with no route forward and the
	 * screen said only "You do not have permission to submit that campaign".
	 *
	 * @return void
	 */
	public function test_staff_may_submit_on_an_advertisers_behalf(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$machine  = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );

		// Who did it is recorded, which is what makes acting on someone's
		// behalf accountable rather than invisible.
		$this->assertSame( 1, $this->audit_rows( $campaign, 'campaign.transitioned' ) );
	}

	/**
	 * **Widening the actor did not widen permission.**
	 *
	 * The transition still requires `aggr_submit_campaign` and the edit meta
	 * capability, and both are checked against the campaign — so `map_meta_cap`
	 * answers the ownership question in the same call. An advertiser from
	 * another organization is refused exactly as before.
	 *
	 * This is the assertion that makes the change safe rather than convenient.
	 *
	 * @return void
	 */
	public function test_an_advertiser_from_another_organization_still_cannot_submit(): void {
		$outsider = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$other_org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $other_org, Org_Repository::META_OWNER_USER, $outsider );

		$campaign = $this->campaign( Post_Statuses::DRAFT );

		wp_set_current_user( $outsider );

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$result  = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
	}

	/**
	 * A user holding neither capability cannot submit, staff or not.
	 *
	 * A subscriber is the floor: no portal access, no submit capability, no
	 * ownership. If the actor widening had replaced the capability check
	 * rather than sat beside it, this is what would have started passing.
	 *
	 * @return void
	 */
	public function test_a_user_without_the_capability_cannot_submit(): void {
		$nobody = self::factory()->user->create( array( 'role' => 'subscriber' ) );

		$campaign = $this->campaign( Post_Statuses::DRAFT );

		wp_set_current_user( $nobody );

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$result  = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
	}

	/**
	 * **Withdrawal stays advertiser-only.**
	 *
	 * Submitting on someone's behalf is help. Pulling a campaign back out from
	 * under the reviewer working on it is not, so staff gained the first and
	 * not the second — and this is what proves the widening was scoped to one
	 * edge rather than applied to the table.
	 *
	 * @return void
	 */
	public function test_staff_still_cannot_withdraw_a_submitted_campaign(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::SUBMITTED );
		$machine  = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );

		$result = $machine->apply( $campaign, Post_Statuses::DRAFT );

		$this->assertInstanceOf( \WP_Error::class, $result );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );
	}

	/**
	 * A second request cannot evaluate from a status already being changed.
	 *
	 * @return void
	 */
	public function test_a_transition_refuses_a_campaign_owned_by_another_request(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$lock     = $this->campaigns->claim_transition_lock( $campaign );

		$this->assertNotSame( '', $lock );

		$result = $this->machine()->apply( $campaign, Post_Statuses::CANCELLED );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_transition_busy', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );

		$this->campaigns->release_transition_lock( $campaign, $lock );
	}

	/**
	 * An illegal transition returns WP_Error rather than throwing.
	 *
	 * An advertiser POSTing aggr_approved is an expected event. Throwing would
	 * turn every probe into a 500 and fill the error log with other people's
	 * curiosity.
	 *
	 * @return void
	 */
	public function test_an_illegal_transition_returns_an_error(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$result   = $this->machine()->apply( $campaign, Post_Statuses::APPROVED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_illegal_transition', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
		$this->assertSame( 1, $this->audit_rows( $campaign, 'campaign.transition_denied' ) );
	}

	/**
	 * An advertiser cannot make a staff transition, even a legal one.
	 *
	 * @return void
	 */
	public function test_an_advertiser_cannot_make_a_staff_transition(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::SUBMITTED );
		$result   = $this->machine()->apply( $campaign, Post_Statuses::REVIEW );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );
	}

	/**
	 * A reviewer cannot make an advertiser-only transition.
	 *
	 * Reviewers inherit advertiser primitives, so this proves the transition's
	 * actor declaration is enforced rather than treated as UI-only metadata.
	 *
	 * **Moved from submission to cancellation.** Submission now names both
	 * actors — a publisher entering a campaign on an advertiser's behalf is the
	 * workflow, and refusing staff left an administrator unable to submit
	 * anything at all. Cancelling somebody else's draft is not help, so that
	 * edge still names one actor and is where this property is now proven.
	 *
	 * The mechanism under test is unchanged; only the edge that still exercises
	 * it has moved.
	 *
	 * @return void
	 */
	public function test_a_reviewer_cannot_make_an_advertiser_transition(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$machine  = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$result   = $machine->apply( $campaign, Post_Statuses::CANCELLED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
	}

	/**
	 * An advertiser from another organization is refused.
	 *
	 * The capability is checked against the object, so the org-scoped
	 * map_meta_cap filter answers ownership in the same call — there is no
	 * separate ownership step to forget.
	 *
	 * @return void
	 */
	public function test_another_organizations_advertiser_is_refused(): void {
		$campaign = $this->campaign( Post_Statuses::DRAFT );

		$stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		wp_set_current_user( $stranger );

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$result  = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
	}

	/**
	 * A failing guard stops the transition and writes nothing.
	 *
	 * @return void
	 */
	public function test_a_failing_guard_prevents_the_transition(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );

		$machine = $this->machine(
			array(
				Transition_Table::GUARD_VALIDATOR => static fn (): WP_Error => new WP_Error(
					'aggr_invalid_campaign',
					'Creative missing.'
				),
			)
		);

		$result = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_invalid_campaign', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
		$this->assertSame( 0, $this->campaigns->submitted_at( $campaign ) );
	}

	/**
	 * An unimplemented guard fails closed.
	 *
	 * A guard that silently passed because nobody had built it yet would be
	 * indistinguishable from one that ran and approved.
	 *
	 * @return void
	 */
	public function test_an_unimplemented_guard_fails_closed(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );

		// No validator supplied — as in production, until Phase 2 builds it.
		$result = $this->machine()->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_guard_unavailable', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign ) );
	}

	/**
	 * **A failing side effect leaves the status alone.**
	 *
	 * This is the ordering the whole class exists for. Publication runs before
	 * the status write, so a failed publish leaves the campaign in review with
	 * an error rather than marked approved with no ads behind it.
	 *
	 * @return void
	 */
	public function test_a_failing_side_effect_leaves_the_status_untouched(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$machine = $this->machine(
			array(
				Transition_Table::GUARD_APPROVABLE => $this->passing_guard(),
			),
			array(
				Transition_Table::EFFECT_PUBLISH => static fn (): WP_Error => new WP_Error(
					'aggr_publish_failed',
					'The provider is unavailable.'
				),
			)
		);

		$result = $machine->apply( $campaign, Post_Statuses::APPROVED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_publish_failed', $result->get_error_code() );
		$this->assertSame(
			Post_Statuses::REVIEW,
			$this->campaigns->status( $campaign ),
			'The campaign was marked approved despite publication failing.'
		);
	}

	/**
	 * An unimplemented failable effect fails closed too.
	 *
	 * @return void
	 */
	public function test_an_unimplemented_effect_fails_closed(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$machine = $this->machine(
			array(
				Transition_Table::GUARD_APPROVABLE => $this->passing_guard(),
			)
		);

		$result = $machine->apply( $campaign, Post_Statuses::APPROVED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_effect_unavailable', $result->get_error_code() );
		$this->assertSame( Post_Statuses::REVIEW, $this->campaigns->status( $campaign ) );
	}

	/**
	 * Claiming a review records the reviewer; releasing it clears them.
	 *
	 * @return void
	 */
	public function test_claiming_and_releasing_a_review(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::SUBMITTED );
		$machine  = $this->machine();

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::REVIEW ) );
		$this->assertSame( $this->reviewer, $this->campaigns->reviewed_by( $campaign ) );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( 0, $this->campaigns->reviewed_by( $campaign ) );
	}

	/**
	 * A claimed campaign can no longer be withdrawn.
	 *
	 * @return void
	 */
	public function test_a_claimed_campaign_cannot_be_withdrawn(): void {
		$campaign = $this->campaign( Post_Statuses::SUBMITTED );

		wp_set_current_user( $this->reviewer );
		$this->assertTrue( $this->machine()->apply( $campaign, Post_Statuses::REVIEW ) );

		// Back to submitted by staff, but the reviewer stays recorded until
		// they explicitly release it.
		$this->campaigns->update_status( $campaign, Post_Statuses::SUBMITTED );
		$this->campaigns->set_reviewed_by( $campaign, $this->reviewer );

		wp_set_current_user( $this->advertiser );
		$result = $this->machine()->apply( $campaign, Post_Statuses::DRAFT );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_campaign_claimed', $result->get_error_code() );
	}

	/**
	 * Sending a campaign back without feedback is refused, and with feedback
	 * stores it.
	 *
	 * @return void
	 */
	public function test_sending_back_requires_and_stores_feedback(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$refused = $this->machine()->apply( $campaign, Post_Statuses::CHANGES );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'aggr_review_notes_required', $refused->get_error_code() );

		$accepted = $this->machine()->apply(
			$campaign,
			Post_Statuses::CHANGES,
			array( 'review_notes' => '  The leaderboard is 1200x400, not 1200x300.  ' )
		);

		$this->assertTrue( $accepted );
		$this->assertSame(
			'The leaderboard is 1200x400, not 1200x300.',
			$this->campaigns->review_notes( $campaign )
		);
	}

	/**
	 * Whitespace-only feedback does not count as feedback.
	 *
	 * @return void
	 */
	public function test_whitespace_only_feedback_is_refused(): void {
		wp_set_current_user( $this->reviewer );

		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$result = $this->machine()->apply(
			$campaign,
			Post_Statuses::REJECTED,
			array( 'review_notes' => "   \n\t " )
		);

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_review_notes_required', $result->get_error_code() );
	}

	/**
	 * Resubmission increments the revision; a first submission does not.
	 *
	 * @return void
	 */
	public function test_resubmission_increments_the_revision(): void {
		wp_set_current_user( $this->advertiser );

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );

		$first = $this->campaign( Post_Statuses::DRAFT );
		$this->assertTrue( $machine->apply( $first, Post_Statuses::SUBMITTED ) );
		$this->assertSame( 0, $this->campaigns->revision( $first ) );

		$again = $this->campaign( Post_Statuses::CHANGES );
		$this->assertTrue( $machine->apply( $again, Post_Statuses::SUBMITTED ) );
		$this->assertSame( 1, $this->campaigns->revision( $again ) );
	}

	/**
	 * A withdrawn or reopened draft is still a resubmission, not revision zero.
	 *
	 * @return void
	 */
	public function test_a_previously_submitted_draft_increments_the_revision(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$this->campaigns->set_submitted_at( $campaign, time() - 60 );

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame( 1, $this->campaigns->revision( $campaign ) );
	}

	/**
	 * A denial is recorded with outcome=denied, which is what makes an attack
	 * queryable rather than merely absent from the log.
	 *
	 * @return void
	 */
	public function test_denials_are_audited_as_denied(): void {
		global $wpdb;

		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$this->machine()->apply( $campaign, Post_Statuses::LIVE );

		$table = $this->audit->table_name();

		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE object_id = %d ORDER BY id DESC LIMIT 1", $campaign ),
			ARRAY_A
		);

		$this->assertNotNull( $row );
		$this->assertSame( 'denied', $row['outcome'] );
		$this->assertSame( Post_Statuses::DRAFT, $row['from_state'] );
		$this->assertSame( Post_Statuses::LIVE, $row['to_state'] );
		$this->assertSame( (string) $this->advertiser, $row['actor_user_id'] );
	}

	/**
	 * The domain event fires once, after the status is already written.
	 *
	 * A listener that reads the campaign must see the new status, or every
	 * notification describes the state the campaign just left.
	 *
	 * @return void
	 */
	public function test_the_domain_event_fires_after_the_status_is_written(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );
		$seen     = array();

		add_action(
			'aggr_campaign_transitioned',
			function ( int $id, string $from, string $to ) use ( &$seen ): void {
				$seen[] = array( $id, $from, $to, $this->campaigns->status( $id ) );
			},
			10,
			3
		);

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );

		$this->assertCount( 1, $seen );
		$this->assertSame(
			array( $campaign, Post_Statuses::DRAFT, Post_Statuses::SUBMITTED, Post_Statuses::SUBMITTED ),
			$seen[0]
		);
	}

	/**
	 * **A notification that throws does not reverse the transition.**
	 *
	 * A submitted campaign stays submitted when the mail server is down.
	 *
	 * @return void
	 */
	public function test_a_throwing_notification_does_not_reverse_the_transition(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::DRAFT );

		add_action(
			'aggr_notify_campaign_transitioned',
			static function (): void {
				throw new \RuntimeException( 'SMTP unreachable' );
			}
		);

		$machine = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$result  = $machine->apply( $campaign, Post_Statuses::SUBMITTED );

		$this->assertTrue( $result, 'A failed notification reversed a successful submission.' );
		$this->assertSame( Post_Statuses::SUBMITTED, $this->campaigns->status( $campaign ) );
		$this->assertSame( 1, $this->audit_rows( $campaign, 'campaign.notification_failed' ) );
	}

	/**
	 * A clock-driven edge applies with no acting user.
	 *
	 * @return void
	 */
	public function test_a_system_transition_applies_without_a_user(): void {
		wp_set_current_user( 0 );

		$campaign = $this->campaign( Post_Statuses::SCHEDULED );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, time() - 60 );

		$this->assertTrue( $this->machine()->apply_system( $campaign, Post_Statuses::LIVE ) );
		$this->assertSame( Post_Statuses::LIVE, $this->campaigns->status( $campaign ) );
	}

	/**
	 * A clock-driven edge still respects its guard.
	 *
	 * @return void
	 */
	public function test_a_system_transition_respects_its_guard(): void {
		wp_set_current_user( 0 );

		$campaign = $this->campaign( Post_Statuses::SCHEDULED );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, time() + 86400 );

		$result = $this->machine()->apply_system( $campaign, Post_Statuses::LIVE );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_not_started', $result->get_error_code() );
		$this->assertSame( Post_Statuses::SCHEDULED, $this->campaigns->status( $campaign ) );
	}

	/**
	 * **The system path cannot be used to make a person's transition.**
	 *
	 * Otherwise apply_system() is a way to reach any edge in the table without
	 * holding a capability — approval included.
	 *
	 * @return void
	 */
	public function test_the_system_path_refuses_a_person_transition(): void {
		wp_set_current_user( 0 );

		$campaign = $this->campaign( Post_Statuses::REVIEW );

		$machine = $this->machine(
			array(
				Transition_Table::GUARD_VALIDATOR => $this->passing_guard(),
			),
			array( Transition_Table::EFFECT_PUBLISH => static fn (): bool => true )
		);

		$result  = $machine->apply_system( $campaign, Post_Statuses::APPROVED );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_not_a_system_transition', $result->get_error_code() );
		$this->assertSame( Post_Statuses::REVIEW, $this->campaigns->status( $campaign ) );
	}

	/**
	 * **Context cannot buy a capability bypass.**
	 *
	 * The clock-driven edges are exactly the ones that put a campaign live, and
	 * they carry no capability requirement. An earlier version selected that
	 * bypass with a flag inside the caller-supplied context array, which would
	 * have handed every advertiser a one-key escalation the moment a controller
	 * forwarded request data into it.
	 *
	 * @return void
	 */
	public function test_context_cannot_grant_a_system_bypass(): void {
		wp_set_current_user( $this->advertiser );

		$campaign = $this->campaign( Post_Statuses::SCHEDULED );
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, time() - 60 );

		// Exactly what an advertiser would put in a request body.
		foreach ( array( 'system', 'is_system', 'actor' ) as $key ) {
			$result = $this->machine()->apply(
				$campaign,
				Post_Statuses::LIVE,
				array( $key => true )
			);

			$this->assertInstanceOf( WP_Error::class, $result, "context[{$key}] was honoured." );
			$this->assertSame( Post_Statuses::SCHEDULED, $this->campaigns->status( $campaign ) );
		}
	}

	/**
	 * A campaign that does not exist is denied rather than fatal.
	 *
	 * @return void
	 */
	public function test_a_missing_campaign_is_denied(): void {
		wp_set_current_user( $this->reviewer );

		$result = $this->machine()->apply( 999999, Post_Statuses::REVIEW );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'aggr_campaign_not_found', $result->get_error_code() );
	}

	/**
	 * A status written without the state machine is noticed.
	 *
	 * The listener cannot veto the write — transition_post_status has no veto
	 * — but the divergence becomes a queryable row instead of surfacing months
	 * later as a state nobody can explain.
	 *
	 * @return void
	 */
	public function test_a_status_written_outside_the_workflow_is_audited(): void {
		// The listener is already attached: the container's state machine is
		// initialized at boot. Calling init() on a second instance here would
		// register it twice and record every divergence twice, which is how
		// this test first found that the flag had to be request-global rather
		// than per-instance.
		$campaign = $this->campaign( Post_Statuses::DRAFT );

		// Somebody's bulk edit, or a script in a hurry.
		wp_update_post(
			array(
				'ID'          => $campaign,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$this->assertSame(
			1,
			$this->audit_rows( $campaign, 'campaign.status_changed_outside_workflow' )
		);
	}

	/**
	 * The listener stays quiet for the state machine's own writes.
	 *
	 * @return void
	 */
	public function test_the_listener_ignores_the_state_machines_own_writes(): void {
		wp_set_current_user( $this->advertiser );

		// Deliberately a different instance from the one listening, which is
		// the realistic case: the listener is the container's, the caller is
		// whatever built one. The suppression has to survive that.
		$machine  = $this->machine( array( Transition_Table::GUARD_VALIDATOR => $this->passing_guard() ) );
		$campaign = $this->campaign( Post_Statuses::DRAFT );

		$this->assertTrue( $machine->apply( $campaign, Post_Statuses::SUBMITTED ) );
		$this->assertSame(
			0,
			$this->audit_rows( $campaign, 'campaign.status_changed_outside_workflow' ),
			'The state machine flagged its own write as foreign.'
		);
	}
}
