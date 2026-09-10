<?php
/**
 * The variant controls an advertiser reaches from the creative step.
 *
 * Pausing a variant and giving it its own dates were reachable only through
 * `PATCH /campaigns/{id}/creative-assignments/{id}` — the mechanism was
 * complete and had no surface. These tests drive the portal handlers, because a
 * control that is never posted to is the failure this phase already met once.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_Error;
use WP_UnitTestCase;

/**
 * Portal pause/resume and per-variant windows, against real rows.
 */
final class CreativeVariantControlsTest extends WP_UnitTestCase {

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
	 * Editable campaign id.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * Placement the variant competes on.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * The assignment under test.
	 *
	 * @var int
	 */
	private int $assignment_id;

	/**
	 * Portal form handlers.
	 *
	 * @var Creative_Actions
	 */
	private Creative_Actions $actions;

	/**
	 * Assignment rows.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Campaign window start, as a UTC timestamp.
	 *
	 * @var int
	 */
	private int $campaign_start;

	/**
	 * Campaign window end, as a UTC timestamp.
	 *
	 * @var int
	 */
	private int $campaign_end;

	/**
	 * Builds one editable campaign carrying one live assignment.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner    = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org_id );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		// A bounded campaign, so "narrowing" and "widening" both mean something.
		$this->campaign_start = strtotime( '2026-06-01 00:00:00 UTC' );
		$this->campaign_end   = strtotime( '2026-06-30 23:59:59 UTC' );
		update_post_meta( $this->campaign_id, Campaign_Repository::META_START_TS, $this->campaign_start );
		update_post_meta( $this->campaign_id, Campaign_Repository::META_END_TS, $this->campaign_end );

		$this->assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
		$this->assignments->install_table();
		$this->assignments->ensure(
			array(
				'line_item_id' => $this->campaign_id,
				'campaign_id'  => $this->campaign_id,
				'placement_id' => $this->placement_id,
				'revision_id'  => 4242,
			)
		);

		$row                 = $this->current_row();
		$this->assignment_id = (int) $row['id'];

		// Live, so pausing it is a real transition rather than a no-op.
		$this->assignments->update(
			$this->assignment_id,
			$this->campaign_id,
			array( 'status' => Assignment_Rules::LIVE ),
			(int) $row['revision']
		);

		$this->actions = Plugin::instance()->container()->get( Creative_Actions::class );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		wp_set_current_user( $this->owner );
	}

	/**
	 * The assignment as it currently stands.
	 *
	 * @return array<string, mixed>
	 */
	private function current_row(): array {
		foreach ( $this->assignments->for_campaign( $this->campaign_id ) as $row ) {
			if ( 4242 === (int) $row['revision_id'] ) {
				return $row;
			}
		}

		$this->fail( 'The assignment under test disappeared.' );
	}

	/**
	 * Pausing takes a variant out of rotation, and resuming puts it back.
	 *
	 * @return void
	 */
	public function test_a_variant_pauses_and_resumes(): void {
		$row = $this->current_row();

		$paused = $this->actions->process_status(
			$this->campaign_id,
			$this->assignment_id,
			'pause',
			(int) $row['revision']
		);

		$this->assertIsInt( $paused, 'Pausing was refused.' );

		$row = $this->current_row();
		$this->assertSame( Assignment_Rules::PAUSED, (string) $row['status'] );

		/*
		 * The flag, not just the status. `project_status()` reads
		 * `operator_paused` to keep a person's pause distinct from a campaign
		 * transition, so a pause that set the status and not the flag would be
		 * undone the next time the campaign moved.
		 */
		$this->assertSame( 1, (int) $row['operator_paused'], 'A person paused it; the flag says otherwise.' );

		$resumed = $this->actions->process_status(
			$this->campaign_id,
			$this->assignment_id,
			'resume',
			(int) $row['revision']
		);

		$this->assertIsInt( $resumed, 'Resuming was refused.' );

		$row = $this->current_row();
		$this->assertSame( Assignment_Rules::LIVE, (string) $row['status'] );
		$this->assertSame( 0, (int) $row['operator_paused'], 'Resuming must hand it back to its campaign.' );
	}

	/**
	 * The pause control cannot be talked into cancelling an assignment.
	 *
	 * `live → cancelled` is a legal edge, and `Assignment_Editor` would allow
	 * it, because other routes are meant to offer it. This control is not one
	 * of them: it posts an intent it maps itself, so a hand-made post carrying
	 * a status string reaches nothing. Withdrawal is terminal — an assignment
	 * cancelled here could not be resumed by the button that cancelled it.
	 *
	 * @return void
	 */
	public function test_the_pause_control_refuses_anything_but_pause_and_resume(): void {
		$row = $this->current_row();

		foreach ( array( Assignment_Rules::CANCELLED, Assignment_Rules::COMPLETED, 'live', '', 'PAUSE' ) as $hostile ) {
			$refused = $this->actions->process_status(
				$this->campaign_id,
				$this->assignment_id,
				(string) $hostile,
				(int) $row['revision']
			);

			$this->assertInstanceOf(
				WP_Error::class,
				$refused,
				sprintf( 'The pause control accepted "%s".', (string) $hostile )
			);
			$this->assertSame( 'aggr_creative_status_intent_invalid', $refused->get_error_code() );
		}

		// And the assignment is exactly where it started.
		$row = $this->current_row();
		$this->assertSame( Assignment_Rules::LIVE, (string) $row['status'] );
		$this->assertSame( 0, (int) $row['operator_paused'] );
	}

	/**
	 * A variant may narrow its window, and may not widen past its campaign.
	 *
	 * @return void
	 */
	public function test_a_window_narrows_but_never_widens(): void {
		$row = $this->current_row();

		$saved = $this->actions->process_window(
			$this->campaign_id,
			$this->assignment_id,
			'2026-06-10',
			'2026-06-20',
			(int) $row['revision']
		);

		$this->assertIsInt( $saved, 'A window inside the campaign was refused.' );

		$row = $this->current_row();
		$this->assertGreaterThan( 0, (int) $row['start_at_ts'] );
		$this->assertGreaterThan( (int) $row['start_at_ts'], (int) $row['end_at_ts'] );
		$this->assertGreaterThanOrEqual( $this->campaign_start, (int) $row['start_at_ts'] );
		$this->assertLessThanOrEqual( $this->campaign_end, (int) $row['end_at_ts'] );

		$narrowed_start = (int) $row['start_at_ts'];
		$narrowed_end   = (int) $row['end_at_ts'];

		// July, for a campaign sold for June.
		$refused = $this->actions->process_window(
			$this->campaign_id,
			$this->assignment_id,
			'2026-06-10',
			'2026-07-20',
			(int) $row['revision']
		);

		$this->assertInstanceOf( WP_Error::class, $refused, 'A creative outran its campaign.' );
		$this->assertSame( 'aggr_assignment_window_invalid', $refused->get_error_code() );

		// Refused rather than clamped: nothing moved.
		$row = $this->current_row();
		$this->assertSame( $narrowed_start, (int) $row['start_at_ts'] );
		$this->assertSame( $narrowed_end, (int) $row['end_at_ts'] );
	}

	/**
	 * An empty date means "inherit that end", not "leave it as it was".
	 *
	 * @return void
	 */
	public function test_an_empty_date_hands_that_end_back_to_the_campaign(): void {
		$row = $this->current_row();

		$this->assertIsInt(
			$this->actions->process_window(
				$this->campaign_id,
				$this->assignment_id,
				'2026-06-10',
				'2026-06-20',
				(int) $row['revision']
			)
		);

		$row = $this->current_row();

		$this->assertIsInt(
			$this->actions->process_window(
				$this->campaign_id,
				$this->assignment_id,
				'',
				'',
				(int) $row['revision']
			)
		);

		$row = $this->current_row();
		$this->assertSame( 0, (int) $row['start_at_ts'], 'An emptied date must inherit, not persist.' );
		$this->assertSame( 0, (int) $row['end_at_ts'] );
	}

	/**
	 * A malformed date is refused before it reaches the row.
	 *
	 * @return void
	 */
	public function test_a_date_that_is_not_a_date_is_refused(): void {
		$row = $this->current_row();

		// PHP rolls 31 February forward to 3 March rather than failing.
		$refused = $this->actions->process_window(
			$this->campaign_id,
			$this->assignment_id,
			'2026-02-31',
			'',
			(int) $row['revision']
		);

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 'aggr_start_date_invalid', $refused->get_error_code() );
	}

	/**
	 * A form rendered before somebody else's change does not overwrite it.
	 *
	 * @return void
	 */
	public function test_a_stale_form_is_refused_rather_than_winning(): void {
		$row   = $this->current_row();
		$stale = (int) $row['revision'];

		$this->assertIsInt(
			$this->actions->process_status( $this->campaign_id, $this->assignment_id, 'pause', $stale )
		);

		// The same revision a second time is a page that never saw the first.
		$refused = $this->actions->process_status( $this->campaign_id, $this->assignment_id, 'resume', $stale );

		$this->assertInstanceOf( WP_Error::class, $refused, 'A stale form overwrote a newer change.' );
		$this->assertSame( 'aggr_assignment_conflict', $refused->get_error_code() );

		$this->assertSame( Assignment_Rules::PAUSED, (string) $this->current_row()['status'] );
	}

	/**
	 * Another tenant cannot pause or re-date a campaign that is not theirs.
	 *
	 * @return void
	 */
	public function test_a_stranger_reaches_neither_control(): void {
		$row = $this->current_row();

		wp_set_current_user( $this->stranger );

		$paused = $this->actions->process_status(
			$this->campaign_id,
			$this->assignment_id,
			'pause',
			(int) $row['revision']
		);

		$this->assertInstanceOf( WP_Error::class, $paused, 'A stranger paused another tenant’s creative.' );
		$this->assertSame( 'aggr_not_found', $paused->get_error_code(), 'A refusal that enumerates is still a leak.' );

		$dated = $this->actions->process_window(
			$this->campaign_id,
			$this->assignment_id,
			'2026-06-10',
			'2026-06-20',
			(int) $row['revision']
		);

		$this->assertInstanceOf( WP_Error::class, $dated );
		$this->assertSame( 'aggr_not_found', $dated->get_error_code() );

		wp_set_current_user( $this->owner );

		// Nothing moved.
		$row = $this->current_row();
		$this->assertSame( Assignment_Rules::LIVE, (string) $row['status'] );
		$this->assertSame( 0, (int) $row['start_at_ts'] );
	}
}
