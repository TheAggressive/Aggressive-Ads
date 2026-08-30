<?php
/**
 * Campaign creation and draft editing.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use WP_UnitTestCase;

/**
 * The workflow shared by REST autosave and the HTML form.
 */
final class CampaignEditorTest extends WP_UnitTestCase {
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
	 * Creates two tenants and one active placement.
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

		$this->editor  = Plugin::instance()->container()->get( Campaign_Editor::class );
		$this->actions = Plugin::instance()->container()->get( Campaign_Actions::class );

		Plugin::instance()->container()->get( Org_Repository::class )->flush_cache();
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();
	}

	/**
	 * Clears request globals changed by handler tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_GET  = array();
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * A draft derives its organization and author from the signed-in user.
	 *
	 * @return void
	 */
	public function test_create_derives_ownership_server_side(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Fall gallery guide' );

		$this->assertIsInt( $campaign_id );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign_id ) );
		$this->assertSame( $this->org_id, (int) get_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, true ) );
		$this->assertSame( $this->advertiser, (int) get_post_field( 'post_author', $campaign_id ) );
		$this->assertSame( 'details', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );
		$line_item = Plugin::instance()->container()->get( Line_Item_Repository::class )->default_for_campaign( $campaign_id );
		$this->assertNotNull( $line_item );
		$this->assertSame( $campaign_id, $line_item['campaign_id'] );
		$this->assertSame( 'draft', $line_item['status'] );
	}

	/**
	 * A partial metadata write is detected and earlier fields are restored.
	 *
	 * @return void
	 */
	public function test_draft_persistence_rolls_back_a_partial_meta_failure(): void {
		$campaigns   = Plugin::instance()->container()->get( Campaign_Repository::class );
		$campaign_id = $campaigns->create_draft( $this->org_id, $this->advertiser, 'Original title' );
		$this->assertIsInt( $campaign_id );

		update_post_meta( $campaign_id, Campaign_Repository::META_START_TS, 100 );
		update_post_meta( $campaign_id, Campaign_Repository::META_END_TS, 200 );

		$fail_end = static fn ( $check, int $object_id, string $meta_key ) => Campaign_Repository::META_END_TS === $meta_key ? false : $check;
		add_filter( 'update_post_metadata', $fail_end, 10, 3 );

		try {
			$result = $campaigns->update_draft(
				$campaign_id,
				array(
					'start_ts' => 300,
					'end_ts'   => 400,
				)
			);
		} finally {
			remove_filter( 'update_post_metadata', $fail_end, 10 );
		}

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_write_failed', $result->get_error_code() );
		$this->assertSame( 100, $campaigns->start_ts( $campaign_id ) );
		$this->assertSame( 200, $campaigns->end_ts( $campaign_id ) );
	}

	/**
	 * A portal user without an organization cannot create unowned data.
	 *
	 * @return void
	 */
	public function test_create_refuses_a_user_without_an_organization(): void {
		wp_set_current_user( $this->other_advertiser );

		$result = $this->editor->create();

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_organization_missing', $result->get_error_code() );
	}

	/**
	 * The form workflow persists details and advances the concurrency token.
	 *
	 * @return void
	 */
	public function test_form_save_persists_the_first_wizard_step(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$result = $this->actions->process_save(
			$campaign_id,
			array(
				'title'            => 'Museum season launch',
				'placement_ids'    => array( $this->placement_id ),
				'advertiser_notes' => "Use the fall artwork.\nContact us with questions.",
			),
			0
		);

		$this->assertSame( 1, $result );
		$this->assertSame( 'Museum season launch', get_the_title( $campaign_id ) );
		$this->assertSame( array( $this->placement_id ), Plugin::instance()->container()->get( Campaign_Repository::class )->placement_ids( $campaign_id ) );
		$this->assertStringContainsString( 'fall artwork', (string) get_post_meta( $campaign_id, Campaign_Repository::META_ADVERTISER_NOTES, true ) );
		$this->assertSame( 'package', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );
	}

	/**
	 * Package selection writes an immutable commercial and placement snapshot.
	 *
	 * @return void
	 */
	public function test_package_selection_snapshots_catalogue_values(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Package snapshot' );
		$this->assertIsInt( $campaign_id );

		$result = $this->actions->process_save_package( $campaign_id, $this->package_id, 0 );

		$this->assertSame( 1, $result );
		$this->assertSame( $this->package_id, (int) get_post_meta( $campaign_id, Campaign_Repository::META_PACKAGE_ID, true ) );
		$this->assertSame( 45000, (int) get_post_meta( $campaign_id, Campaign_Repository::META_BUDGET_CENTS, true ) );
		$this->assertSame( 'USD', get_post_meta( $campaign_id, Campaign_Repository::META_CURRENCY, true ) );
		$this->assertSame( array( $this->placement_id ), Plugin::instance()->container()->get( Campaign_Repository::class )->placement_ids( $campaign_id ) );
		$this->assertSame( 'creative', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );

		update_post_meta( $this->package_id, Package_Repository::META_PRICE_CENTS, 99000 );

		$this->assertSame( 45000, (int) get_post_meta( $campaign_id, Campaign_Repository::META_BUDGET_CENTS, true ), 'Later catalogue edits must not silently reprice an existing draft.' );
	}

	/**
	 * A package may explicitly delegate its date window to the advertiser.
	 *
	 * Zero days without the flag remains malformed, so missing configuration
	 * cannot silently become a custom commercial term.
	 *
	 * @return void
	 */
	public function test_custom_duration_must_be_explicit(): void {
		wp_set_current_user( $this->advertiser );

		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 0 );
		$invalid = $this->editor->package_snapshot( $this->package_id );

		$this->assertWPError( $invalid );
		$this->assertSame( 'aggr_package_misconfigured', $invalid->get_error_code() );

		update_post_meta( $this->package_id, Package_Repository::META_CUSTOM_DURATION, 1 );

		$this->assertIsArray( $this->editor->package_snapshot( $this->package_id ) );
	}

	/**
	 * Inactive catalogue entries cannot be selected by posting their ids.
	 *
	 * @return void
	 */
	public function test_inactive_package_is_refused(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Protected package' );
		$this->assertIsInt( $campaign_id );

		update_post_meta( $this->package_id, Package_Repository::META_IS_ACTIVE, 0 );

		$result = $this->editor->save( $campaign_id, array( 'package_id' => $this->package_id ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_package_unavailable', $result->get_error_code() );
		$this->assertSame( 0, (int) get_post_meta( $campaign_id, Campaign_Repository::META_PACKAGE_ID, true ) );
	}

	/**
	 * A package is rejected when any included placement is no longer active.
	 *
	 * @return void
	 */
	public function test_package_with_inactive_placement_fails_closed(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Malformed package' );
		$this->assertIsInt( $campaign_id );

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 0 );

		$result = $this->editor->save( $campaign_id, array( 'package_id' => $this->package_id ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_package_misconfigured', $result->get_error_code() );
	}

	/**
	 * A placement without an exact machine-readable size cannot accept creative.
	 *
	 * @return void
	 */
	public function test_package_with_malformed_placement_size_fails_closed(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Malformed placement size' );
		$this->assertIsInt( $campaign_id );

		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, 'leaderboard' );

		$result = $this->editor->save( $campaign_id, array( 'package_id' => $this->package_id ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_package_misconfigured', $result->get_error_code() );
		$this->assertSame( array(), Plugin::instance()->container()->get( Campaign_Repository::class )->placement_ids( $campaign_id ) );
	}

	/**
	 * The form requires an explicit package selection.
	 *
	 * @return void
	 */
	public function test_package_form_requires_a_selection(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Required package' );
		$this->assertIsInt( $campaign_id );

		$result = $this->actions->process_save_package( $campaign_id, 0, 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_package_required', $result->get_error_code() );
	}

	/**
	 * Step four writes a submission-grade local date window and advances review.
	 *
	 * @return void
	 */
	public function test_schedule_step_persists_dates_and_advances_to_review(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Scheduled campaign' );
		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$start_date = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$end_date   = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->format( 'Y-m-d' );
		$result     = $this->actions->process_save_schedule( $campaign_id, $start_date, $end_date, 1 );

		$this->assertSame( 2, $result );
		$this->assertSame( $start_date, wp_date( 'Y-m-d', (int) get_post_meta( $campaign_id, Campaign_Repository::META_START_TS, true ), wp_timezone() ) );
		$this->assertSame( $end_date . ' 23:59:59', wp_date( 'Y-m-d H:i:s', (int) get_post_meta( $campaign_id, Campaign_Repository::META_END_TS, true ), wp_timezone() ) );
		$this->assertSame( 'review', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );
	}

	/**
	 * Scheduling cannot bypass the one-creative-per-placement contract.
	 *
	 * @return void
	 */
	public function test_schedule_step_requires_complete_creative_coverage(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Incomplete campaign' );
		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );

		$start_date = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$result     = $this->actions->process_save_schedule( $campaign_id, $start_date, '', 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creatives_incomplete', $result->get_error_code() );
		$this->assertSame( 0, (int) get_post_meta( $campaign_id, Campaign_Repository::META_START_TS, true ) );
		$this->assertSame( 'creative', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );
	}

	/**
	 * Step completion rejects missing, elapsed, and reversed date windows.
	 *
	 * @return void
	 */
	public function test_schedule_step_enforces_submission_grade_dates(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Invalid schedule' );
		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$missing = $this->actions->process_save_schedule( $campaign_id, '', '', 1 );
		$this->assertWPError( $missing );
		$this->assertSame( 'aggr_start_date_required', $missing->get_error_code() );

		$past = $this->actions->process_save_schedule( $campaign_id, '2020-01-01', '', 1 );
		$this->assertWPError( $past );
		$this->assertSame( 'aggr_start_date_past', $past->get_error_code() );

		$start_date = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->format( 'Y-m-d' );
		$end_date   = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$reversed   = $this->actions->process_save_schedule( $campaign_id, $start_date, $end_date, 1 );
		$this->assertWPError( $reversed );
		$this->assertSame( 'aggr_end_before_start', $reversed->get_error_code() );
		$this->assertSame( 1, Plugin::instance()->container()->get( Campaign_Repository::class )->autosave_revision( $campaign_id ) );
	}

	/**
	 * The shared editor prevents API clients from bypassing date-only form
	 * boundaries with arbitrary timestamps.
	 *
	 * @return void
	 */
	public function test_schedule_completion_rejects_partial_day_api_timestamps(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'API schedule boundaries' );
		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$zone   = wp_timezone();
		$start  = ( new \DateTimeImmutable( '+10 days', $zone ) )->setTime( 0, 0, 1 );
		$end    = ( new \DateTimeImmutable( '+20 days', $zone ) )->setTime( 23, 59, 59 );
		$result = $this->editor->save(
			$campaign_id,
			array(
				'start_ts'    => $start->getTimestamp(),
				'end_ts'      => $end->getTimestamp(),
				'wizard_step' => 'review',
			),
			1
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_start_date_not_midnight', $result->get_error_code() );
		$this->assertSame( 0, (int) get_post_meta( $campaign_id, Campaign_Repository::META_START_TS, true ) );
		$this->assertSame( 1, Plugin::instance()->container()->get( Campaign_Repository::class )->autosave_revision( $campaign_id ) );
	}

	/**
	 * **A campaign may start today**, through the real save path.
	 *
	 * The pure rule is asserted in `CampaignRulesTest`; this is the half that
	 * proves an advertiser can actually submit it. Both rules have to agree:
	 * the start must be midnight in the site timezone *and* not before today,
	 * and it was the combination that made today unreachable — the date input's
	 * `min` was set to tomorrow to match a restriction nobody had chosen.
	 *
	 * @return void
	 */
	public function test_a_campaign_can_start_today(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Starting today' );

		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( 'today', $zone ) );
		$end   = ( new \DateTimeImmutable( '+10 days', $zone ) )->setTime( 23, 59, 59 );

		$this->assertLessThanOrEqual(
			time(),
			$start->getTimestamp(),
			'Today midnight must already have passed, or this proves nothing about the old rule.'
		);

		$result = $this->editor->save(
			$campaign_id,
			array(
				'start_ts'    => $start->getTimestamp(),
				'end_ts'      => $end->getTimestamp(),
				'wizard_step' => 'review',
			),
			1
		);

		// `save()` answers with the new autosave revision, not a boolean.
		$this->assertIsInt( $result, 'An advertiser could not schedule a campaign to start today.' );
		$this->assertGreaterThan( 1, $result, 'The save did not advance the revision, so nothing was written.' );
		$this->assertSame(
			$start->getTimestamp(),
			(int) get_post_meta( $campaign_id, Campaign_Repository::META_START_TS, true )
		);
	}

	/**
	 * Yesterday is still refused, which is the half that keeps the rule a rule.
	 *
	 * @return void
	 */
	public function test_a_campaign_cannot_start_yesterday(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Starting yesterday' );

		$this->assertIsInt( $campaign_id );
		$this->assertSame( 1, $this->actions->process_save_package( $campaign_id, $this->package_id, 0 ) );
		$this->add_creative( $campaign_id );

		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( 'yesterday', $zone ) );
		$end   = ( new \DateTimeImmutable( '+10 days', $zone ) )->setTime( 23, 59, 59 );

		$result = $this->editor->save(
			$campaign_id,
			array(
				'start_ts'    => $start->getTimestamp(),
				'end_ts'      => $end->getTimestamp(),
				'wizard_step' => 'review',
			),
			1
		);

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_start_date_past', $result->get_error_code() );
	}

	/**
	 * A stale tab cannot overwrite a newer save.
	 *
	 * @return void
	 */
	public function test_a_stale_revision_is_refused_without_overwriting(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Original' );
		$this->assertIsInt( $campaign_id );

		$first = $this->editor->save( $campaign_id, array( 'title' => 'Fresh value' ), 0 );
		$stale = $this->editor->save( $campaign_id, array( 'title' => 'Stale value' ), 0 );

		$this->assertSame( 1, $first );
		$this->assertWPError( $stale );
		$this->assertSame( 'aggr_edit_conflict', $stale->get_error_code() );
		$this->assertSame( 'Fresh value', get_the_title( $campaign_id ) );
	}

	/**
	 * Final confirmation is a view, not a client-controlled resume mutation.
	 *
	 * @return void
	 */
	public function test_submit_confirmation_cannot_be_persisted_as_an_edit_step(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Query-only confirmation' );
		$this->assertIsInt( $campaign_id );

		$result = $this->editor->save( $campaign_id, array( 'wizard_step' => 'submit' ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_wizard_step_invalid', $result->get_error_code() );
		$this->assertSame( 'details', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );
		$this->assertSame( 0, Plugin::instance()->container()->get( Campaign_Repository::class )->autosave_revision( $campaign_id ) );
	}

	/**
	 * Placement ids are references and are revalidated before persistence.
	 *
	 * @return void
	 */
	public function test_an_inactive_placement_is_refused(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 0 );

		$result = $this->editor->save( $campaign_id, array( 'placement_ids' => array( $this->placement_id ) ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_placement_unavailable', $result->get_error_code() );
		$this->assertSame( array(), Plugin::instance()->container()->get( Campaign_Repository::class )->placement_ids( $campaign_id ) );
	}

	/**
	 * Another organization cannot edit the draft even with the feature cap.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_edit_the_draft(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create( 'Protected' );
		$this->assertIsInt( $campaign_id );

		wp_set_current_user( $this->other_advertiser );

		$result = $this->editor->save( $campaign_id, array( 'title' => 'Taken over' ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( 'Protected', get_the_title( $campaign_id ) );

		$start_date = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$schedule   = $this->actions->process_save_schedule( $campaign_id, $start_date, '', 0 );

		$this->assertWPError( $schedule );
		$this->assertSame( 'aggr_forbidden', $schedule->get_error_code(), 'Readiness checks must not reveal another tenant campaign state.' );
	}

	/**
	 * Submitted campaigns are immutable through the draft editor.
	 *
	 * @return void
	 */
	public function test_a_submitted_campaign_cannot_be_edited(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create( 'Submitted' );
		$this->assertIsInt( $campaign_id );

		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => Post_Statuses::SUBMITTED,
			)
		);

		$result = $this->editor->save( $campaign_id, array( 'title' => 'Changed' ), 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_not_editable', $result->get_error_code() );
	}

	/**
	 * The progressive form submits through the canonical lifecycle.
	 *
	 * @return void
	 */
	public function test_form_submission_applies_the_canonical_transition(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Ready to submit' );

		$result = $this->actions->process_submit( $campaign_id );

		$this->assertTrue( $result );
		$this->assertSame( Post_Statuses::SUBMITTED, get_post_status( $campaign_id ) );
		$this->assertGreaterThan( 0, (int) get_post_meta( $campaign_id, Campaign_Repository::META_SUBMITTED_AT, true ) );
		$this->assertSame( 'review', get_post_meta( $campaign_id, Campaign_Repository::META_WIZARD_STEP, true ) );

		$events = ( new Audit_Repository() )->for_object( 'campaign', $campaign_id, $this->org_id );
		$this->assertContains( 'campaign.transitioned', array_column( $events, 'event' ) );
	}

	/**
	 * Browser readiness cannot bypass transition-time validation.
	 *
	 * @return void
	 */
	public function test_form_submission_revalidates_current_campaign_data(): void {
		wp_set_current_user( $this->advertiser );

		$campaign_id = $this->editor->create( 'Not ready' );
		$this->assertIsInt( $campaign_id );

		$result = $this->actions->process_submit( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_campaign_invalid', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign_id ) );
		$error_data = $result->get_error_data();
		$this->assertIsArray( $error_data );
		$this->assertNotSame( array(), $error_data['problems'] );
	}

	/**
	 * Submission reauthorizes the object before exposing validation state.
	 *
	 * @return void
	 */
	public function test_another_organization_cannot_submit_the_campaign(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Tenant protected' );

		wp_set_current_user( $this->other_advertiser );
		$result = $this->actions->process_submit( $campaign_id );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_forbidden', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, get_post_status( $campaign_id ) );
	}

	/**
	 * Replaying the form cannot submit or notify twice.
	 *
	 * @return void
	 */
	public function test_replayed_submission_is_audited_and_refused(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->complete_campaign( 'Submit once' );

		$this->assertTrue( $this->actions->process_submit( $campaign_id ) );
		$replayed = $this->actions->process_submit( $campaign_id );

		$this->assertWPError( $replayed );
		$this->assertSame( 'aggr_illegal_transition', $replayed->get_error_code() );

		$events = ( new Audit_Repository() )->for_object( 'campaign', $campaign_id, $this->org_id );
		$this->assertSame( 1, count( array_filter( $events, static fn ( array $event ): bool => 'campaign.transitioned' === $event['event'] ) ) );
		$this->assertSame( 1, count( array_filter( $events, static fn ( array $event ): bool => 'campaign.transition_denied' === $event['event'] ) ) );
	}

	/**
	 * Query-only confirmation and submission notice are tightly allowlisted.
	 *
	 * @return void
	 */
	public function test_submission_display_state_is_allowlisted(): void {
		$_GET = array(
			'step'        => 'submit',
			'aggr_notice' => 'submitted',
		);

		$this->assertSame( 'submit', Campaign_Actions::request_step( 'review' ) );
		$this->assertSame( 'submitted', Campaign_Actions::request_notice() );

		$_GET['step']        = 'approved';
		$_GET['aggr_notice'] = 'campaign.transitioned';

		$this->assertSame( 'review', Campaign_Actions::request_step( 'review' ) );
		$this->assertSame( '', Campaign_Actions::request_notice() );
	}

	/**
	 * The create form cannot be submitted without its CSRF token.
	 *
	 * @return void
	 */
	public function test_create_handler_rejects_a_missing_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$_POST = array();

		$this->expectException( 'WPDieException' );
		$this->actions->handle_create();
	}

	/**
	 * A token for another campaign cannot authorize this draft edit.
	 *
	 * @return void
	 */
	public function test_save_handler_rejects_a_forged_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$_POST = array(
			'campaign_id' => (string) $campaign_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Actions::save_nonce_action( $campaign_id + 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_save();
	}

	/**
	 * A details nonce cannot authorize the distinct package write action.
	 *
	 * @return void
	 */
	public function test_package_handler_rejects_a_nonce_for_another_action(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$_POST = array(
			'campaign_id' => (string) $campaign_id,
			'package_id'  => (string) $this->package_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Actions::save_nonce_action( $campaign_id ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_save_package();
	}

	/**
	 * A package nonce cannot authorize the schedule write action.
	 *
	 * @return void
	 */
	public function test_schedule_handler_rejects_a_nonce_for_another_action(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$_POST = array(
			'campaign_id' => (string) $campaign_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Actions::package_nonce_action( $campaign_id ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_save_schedule();
	}

	/**
	 * Another campaign's submit nonce cannot authorize this transition.
	 *
	 * @return void
	 */
	public function test_submit_handler_requires_its_campaign_bound_nonce(): void {
		wp_set_current_user( $this->advertiser );
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$_POST = array(
			'campaign_id' => (string) $campaign_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Actions::submit_nonce_action( $campaign_id + 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_submit();
	}
}
