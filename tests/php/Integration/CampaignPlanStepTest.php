<?php
/**
 * The three-step wizard's plan, creative gate, naming and rename.
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
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Campaign_Validator;
use WP_UnitTestCase;

/**
 * What the portal's first two steps promise, through the real save path.
 */
final class CampaignPlanStepTest extends WP_UnitTestCase {
	use CampaignEditorFixtures;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

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
	 * Active 30-day package id.
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
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * One tenant, one placement, one fixed 30-day package.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id     = (int) self::factory()->post->create(
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

		$this->package_id = $this->make_package( 'Launch package', 30 );

		$this->editor    = Plugin::instance()->container()->get( Campaign_Editor::class );
		$this->actions   = Plugin::instance()->container()->get( Campaign_Actions::class );
		$this->campaigns = Plugin::instance()->container()->get( Campaign_Repository::class );

		Plugin::instance()->container()->get( Org_Repository::class )->flush_cache();
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->advertiser );
	}

	/**
	 * Clears request globals changed by handler tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		$_POST = array();

		parent::tear_down();
	}

	/**
	 * A fixed package sets the end date: the start day is day one of thirty.
	 *
	 * @return void
	 */
	public function test_a_fixed_package_derives_the_end_date(): void {
		$campaign_id = $this->draft( 'Scheduled campaign' );
		$start       = $this->local_date( '+10 days' );
		$last_day    = ( new \DateTimeImmutable( $start, wp_timezone() ) )->modify( '+29 days' )->format( 'Y-m-d' );

		$this->assertSame( 1, $this->save_plan( $campaign_id, $start, 0 ) );

		$this->assertSame( $start . ' 00:00:00', wp_date( 'Y-m-d H:i:s', $this->campaigns->start_ts( $campaign_id ), wp_timezone() ) );
		$this->assertSame( $last_day . ' 23:59:59', wp_date( 'Y-m-d H:i:s', $this->campaigns->end_ts( $campaign_id ), wp_timezone() ) );
		$this->assertSame( 'creative', $this->campaigns->wizard_step( $campaign_id ) );
	}

	/**
	 * **An end somebody names is not replaced.** The negative half of the
	 * derivation: a REST client or a staff correction that sends `end_ts` keeps
	 * it, even on a fixed package.
	 *
	 * @return void
	 */
	public function test_an_explicit_end_survives_a_fixed_package(): void {
		$campaign_id = $this->draft( 'Explicit end' );
		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 0 ) );

		$zone  = wp_timezone();
		$start = ( new \DateTimeImmutable( '+10 days', $zone ) )->setTime( 0, 0 );
		$end   = ( new \DateTimeImmutable( '+15 days', $zone ) )->setTime( 23, 59, 59 );

		$this->assertSame(
			2,
			$this->editor->save(
				$campaign_id,
				array(
					'start_ts' => $start->getTimestamp(),
					'end_ts'   => $end->getTimestamp(),
				),
				1
			)
		);
		$this->assertSame( $end->getTimestamp(), $this->campaigns->end_ts( $campaign_id ) );
	}

	/**
	 * A custom package derives nothing: the posted end, or no end at all.
	 *
	 * @return void
	 */
	public function test_a_custom_package_takes_the_posted_end_or_none(): void {
		update_post_meta( $this->package_id, Package_Repository::META_DURATION_DAYS, 0 );
		update_post_meta( $this->package_id, Package_Repository::META_CUSTOM_DURATION, 1 );

		$campaign_id = $this->draft( 'Custom run' );
		$start       = $this->local_date( '+10 days' );

		$this->assertSame(
			1,
			$this->actions->process_save(
				$campaign_id,
				array(
					'package_id' => $this->package_id,
					'start_date' => $start,
					'end_date'   => '',
				),
				0
			)
		);
		$this->assertSame( 0, $this->campaigns->end_ts( $campaign_id ), 'A custom package invented an end date.' );

		$end = $this->local_date( '+20 days' );

		$this->assertSame(
			2,
			$this->actions->process_save(
				$campaign_id,
				array(
					'start_date' => $start,
					'end_date'   => $end,
				),
				1
			)
		);
		$this->assertSame( $end . ' 23:59:59', wp_date( 'Y-m-d H:i:s', $this->campaigns->end_ts( $campaign_id ), wp_timezone() ) );
	}

	/**
	 * Leaving details with a past start is refused there, and writes nothing.
	 *
	 * @return void
	 */
	public function test_leaving_details_refuses_a_past_start(): void {
		$campaign_id = $this->draft( 'Past start' );
		$result      = $this->save_plan( $campaign_id, '2020-01-01', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_start_date_past', $result->get_error_code() );
		$this->assertSame( 0, $this->campaigns->start_ts( $campaign_id ) );
		$this->assertSame( 0, $this->campaigns->autosave_revision( $campaign_id ) );
		$this->assertSame( 'details', $this->campaigns->wizard_step( $campaign_id ) );
	}

	/**
	 * A draft can still leave details before its dates are decided.
	 *
	 * @return void
	 */
	public function test_a_draft_leaves_details_without_a_date(): void {
		$campaign_id = $this->draft( 'Undecided' );

		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 0 ) );
		$this->assertSame( 0, $this->campaigns->start_ts( $campaign_id ) );
		$this->assertSame( 0, $this->campaigns->end_ts( $campaign_id ), 'An end was derived from a start nobody chose.' );
		$this->assertSame( 'creative', $this->campaigns->wizard_step( $campaign_id ) );
	}

	/**
	 * The creative step cannot be left with a size still empty.
	 *
	 * @return void
	 */
	public function test_leaving_the_creative_step_requires_every_size(): void {
		$campaign_id = $this->draft( 'Missing ad' );
		$this->assertSame( 1, $this->save_plan( $campaign_id, $this->local_date( '+10 days' ), 0 ) );

		$result = $this->actions->process_complete_creative( $campaign_id, 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_creatives_incomplete', $result->get_error_code() );
		$this->assertSame( 'creative', $this->campaigns->wizard_step( $campaign_id ) );
		$this->assertSame( 1, $this->campaigns->autosave_revision( $campaign_id ) );
	}

	/**
	 * Nor without the date that details let it skip.
	 *
	 * @return void
	 */
	public function test_leaving_the_creative_step_requires_a_start_date(): void {
		$campaign_id = $this->draft( 'Missing date' );
		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 0 ) );
		$this->add_creative( $campaign_id );

		$result = $this->actions->process_complete_creative( $campaign_id, 1 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_start_date_required', $result->get_error_code() );
		$this->assertSame( 'creative', $this->campaigns->wizard_step( $campaign_id ) );
	}

	/**
	 * A complete plan with every size covered moves to review.
	 *
	 * @return void
	 */
	public function test_leaving_the_creative_step_advances_to_review(): void {
		$campaign_id = $this->draft( 'Complete' );
		$this->assertSame( 1, $this->save_plan( $campaign_id, $this->local_date( '+10 days' ), 0 ) );
		$this->add_creative( $campaign_id );

		$this->assertSame( 2, $this->actions->process_complete_creative( $campaign_id, 1 ) );
		$this->assertSame( 'review', $this->campaigns->wizard_step( $campaign_id ) );
	}

	/**
	 * **An unnamed draft is named after its plan, and may then be submitted.**
	 *
	 * The title rule is asserted to fire first, so the second assertion is
	 * about the naming and not about a validator that stopped checking.
	 *
	 * @return void
	 */
	public function test_an_unnamed_campaign_is_named_after_its_plan(): void {
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );
		$this->assertContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->problem_codes( $campaign_id ) );

		$start = $this->local_date( '+10 days' );
		$this->assertSame( 1, $this->save_plan( $campaign_id, $start, 0 ) );

		$this->assertSame(
			'Launch package – ' . wp_date( 'F Y', $this->campaigns->start_ts( $campaign_id ) ),
			get_the_title( $campaign_id )
		);
		$this->assertFalse( $this->campaigns->title_is_placeholder( $campaign_id ) );
		$this->assertTrue( $this->campaigns->title_is_automatic( $campaign_id ) );
		$this->assertNotContains( Campaign_Rules::ERROR_TITLE_MISSING, $this->problem_codes( $campaign_id ) );
	}

	/**
	 * The automatic name follows the package until somebody renames it, and
	 * after that nothing the wizard does touches it.
	 *
	 * @return void
	 */
	public function test_an_automatic_name_follows_the_plan_until_renamed(): void {
		$sidebar     = $this->make_package( 'Sidebar package', 7 );
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 0 ) );
		$this->assertSame( 'Launch package', get_the_title( $campaign_id ) );

		$this->assertSame( 2, $this->actions->process_save( $campaign_id, array( 'package_id' => $sidebar ), 1 ) );
		$this->assertSame( 'Sidebar package', get_the_title( $campaign_id ), 'The automatic name kept a package that was swapped out.' );

		$this->assertSame( 3, $this->actions->process_rename( $campaign_id, 'Museum season', 2 ) );
		$this->assertFalse( $this->campaigns->title_is_automatic( $campaign_id ) );

		$this->assertSame( 4, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 3 ) );
		$this->assertSame( 'Museum season', get_the_title( $campaign_id ), 'A chosen name was overwritten by the plan.' );
	}

	/**
	 * A name given at creation is the advertiser's and is never replaced.
	 *
	 * @return void
	 */
	public function test_a_name_given_at_creation_is_never_replaced(): void {
		$campaign_id = $this->draft( 'Chosen name' );

		$this->assertSame( 1, $this->save_plan( $campaign_id, $this->local_date( '+10 days' ), 0 ) );
		$this->assertSame( 'Chosen name', get_the_title( $campaign_id ) );
		$this->assertFalse( $this->campaigns->title_is_automatic( $campaign_id ) );
	}

	/**
	 * Renaming to nothing is refused and keeps the name.
	 *
	 * @return void
	 */
	public function test_a_rename_needs_a_name(): void {
		$campaign_id = $this->draft( 'Kept' );
		$result      = $this->actions->process_rename( $campaign_id, '   ', 0 );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_title_required', $result->get_error_code() );
		$this->assertSame( 'Kept', get_the_title( $campaign_id ) );
	}

	/**
	 * A draft started from the dashboard carries its package snapshot.
	 *
	 * @return void
	 */
	public function test_a_draft_can_start_from_a_package(): void {
		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$this->assertSame( 1, $this->actions->process_choose_package( $campaign_id, $this->package_id ) );
		$this->assertSame( $this->package_id, $this->campaigns->package_id( $campaign_id ) );
		$this->assertSame( array( $this->placement_id ), $this->campaigns->placement_ids( $campaign_id ) );
		$this->assertSame( 45000, $this->campaigns->budget_cents( $campaign_id ) );
		$this->assertSame( 'details', $this->campaigns->wizard_step( $campaign_id ), 'Starting from a package skipped the dates.' );
	}

	/**
	 * **A package that cannot be applied leaves the draft, not nothing.** The
	 * advertiser just created it; the refusal is reported on it.
	 *
	 * @return void
	 */
	public function test_an_unavailable_package_leaves_the_draft_in_place(): void {
		$retired = $this->make_package( 'Retired package', 30 );
		update_post_meta( $retired, Package_Repository::META_IS_ACTIVE, 0 );

		$campaign_id = $this->editor->create();
		$this->assertIsInt( $campaign_id );

		$result = $this->actions->process_choose_package( $campaign_id, $retired );

		$this->assertWPError( $result );
		$this->assertSame( 'aggr_package_unavailable', $result->get_error_code() );
		$this->assertSame( Post_Statuses::DRAFT, $this->campaigns->status( $campaign_id ) );
		$this->assertSame( 0, $this->campaigns->package_id( $campaign_id ) );
	}

	/**
	 * Another campaign's creative-step nonce cannot move this one to review.
	 *
	 * @return void
	 */
	public function test_the_creative_handler_requires_its_campaign_bound_nonce(): void {
		$campaign_id = $this->draft( 'Guarded' );

		$_POST = array(
			'campaign_id' => (string) $campaign_id,
			'_wpnonce'    => wp_create_nonce( Campaign_Nonces::creative_nonce_action( $campaign_id + 1 ) ),
		);

		$this->expectException( 'WPDieException' );
		$this->actions->handle_complete_creative();
	}

	/**
	 * A named draft owned by the advertiser.
	 *
	 * @param string $title Campaign title.
	 * @return int
	 */
	private function draft( string $title ): int {
		$campaign_id = $this->editor->create( $title );
		$this->assertIsInt( $campaign_id );

		return $campaign_id;
	}

	/**
	 * Saves details the way the form posts it: package and start date.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $start       Local start date.
	 * @param int    $revision    Last-seen revision.
	 * @return int|\WP_Error
	 */
	private function save_plan( int $campaign_id, string $start, int $revision ): int|\WP_Error {
		return $this->actions->process_save(
			$campaign_id,
			array(
				'package_id' => $this->package_id,
				'start_date' => $start,
			),
			$revision
		);
	}

	/**
	 * A local `Y-m-d` date relative to today.
	 *
	 * @param string $relative Relative date string.
	 * @return string
	 */
	private function local_date( string $relative ): string {
		return ( new \DateTimeImmutable( $relative, wp_timezone() ) )->format( 'Y-m-d' );
	}

	/**
	 * Submission problem codes for a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, string>
	 */
	private function problem_codes( int $campaign_id ): array {
		return array_column( Plugin::instance()->container()->get( Campaign_Validator::class )->validate( $campaign_id )->problems(), 'code' );
	}

	/**
	 * An active fixed-length package over the fixture placement.
	 *
	 * @param string $name Package name.
	 * @param int    $days Duration in days.
	 * @return int
	 */
	private function make_package( string $name, int $days ): int {
		$package_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PACKAGE,
				'post_status' => 'publish',
				'post_title'  => $name,
			)
		);

		add_post_meta( $package_id, Package_Repository::META_PLACEMENT_ID, $this->placement_id );
		update_post_meta( $package_id, Package_Repository::META_DURATION_DAYS, $days );
		update_post_meta( $package_id, Package_Repository::META_PRICE_CENTS, 45000 );
		update_post_meta( $package_id, Package_Repository::META_CURRENCY, 'USD' );
		update_post_meta( $package_id, Package_Repository::META_IS_ACTIVE, 1 );

		return $package_id;
	}
	/**
	 * The campaign's link saves on its own, and the size cards start from it
	 * rather than from whatever the first uploaded ad happened to carry.
	 *
	 * @return void
	 */
	public function test_the_campaign_link_saves_and_leads_the_cards(): void {
		$campaign_id = $this->draft( 'Linked campaign' );

		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'default_click_url' => ' https://example.com/spring ' ), 0 ) );
		$this->assertSame( 'https://example.com/spring', $this->campaigns->default_click_url( $campaign_id ) );

		$row = Plugin::instance()->container()->get( View_Data::class )->campaign( $campaign_id );

		$this->assertIsArray( $row );
		$this->assertSame( 'https://example.com/spring', $row['default_click_url'] );
	}

	/**
	 * **A refused link leaves the stored one alone**, names its own field, and
	 * sends the no-script form back to the ads step rather than the first.
	 *
	 * @return void
	 */
	public function test_an_invalid_campaign_link_is_refused_without_touching_the_stored_one(): void {
		$campaign_id = $this->draft( 'Guarded link' );
		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'default_click_url' => 'https://example.com/' ), 0 ) );

		foreach ( array( 'https://', 'ftp://example.com/', 'javascript:alert(1)', 'https://user:pass@example.com/' ) as $link ) {
			$result = $this->actions->process_save( $campaign_id, array( 'default_click_url' => $link ), 1 );

			$this->assertWPError( $result, $link );
			$this->assertSame( 'aggr_default_click_url_invalid', $result->get_error_code(), $link );
		}

		$this->assertSame( 'https://example.com/', $this->campaigns->default_click_url( $campaign_id ) );
		$this->assertSame( 'aggr-campaign-link', Campaign_Actions::error_field( 'aggr_default_click_url_invalid' ) );
	}

	/**
	 * Empty clears it, and a save that does not send the field keeps it.
	 *
	 * @return void
	 */
	public function test_an_empty_campaign_link_clears_and_an_absent_one_keeps(): void {
		$campaign_id = $this->draft( 'Cleared link' );

		$this->assertSame( 1, $this->actions->process_save( $campaign_id, array( 'default_click_url' => 'https://example.com/' ), 0 ) );
		$this->assertSame( 2, $this->actions->process_save( $campaign_id, array( 'package_id' => $this->package_id ), 1 ) );
		$this->assertSame( 'https://example.com/', $this->campaigns->default_click_url( $campaign_id ) );

		$this->assertSame( 3, $this->actions->process_save( $campaign_id, array( 'default_click_url' => '' ), 2 ) );
		$this->assertSame( '', $this->campaigns->default_click_url( $campaign_id ) );

		// Typed the way people type links, and saved the way a click needs it.
		$this->assertSame( 4, $this->actions->process_save( $campaign_id, array( 'default_click_url' => 'example.com/spring' ), 3 ) );
		$this->assertSame( 'https://example.com/spring', $this->campaigns->default_click_url( $campaign_id ) );
	}
}
