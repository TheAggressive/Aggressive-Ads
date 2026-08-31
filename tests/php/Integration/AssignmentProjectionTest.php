<?php
/**
 * Assignments follow their campaign, or nothing ever serves.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Assignment_Editor;
use Aggressive\Ads\Workflow\Assignment_Projection;
use WP_UnitTestCase;

/**
 * The read half and the write half, meeting.
 *
 * `candidates_for_placement()` selects on `status = 'live'`. Before this, the
 * only production code that wrote that value from a campaign was the one-time
 * P2 backfill, so an assignment froze at whatever the campaign was during the
 * migration and every campaign that went live afterwards served nothing.
 *
 * Twelve tests covered the read and stayed green, because each one wrote
 * `'status' => Assignment_Rules::LIVE` into its own fixture. **Nothing here may
 * set that column by hand.** Every assertion below arranges a draft row, moves
 * the campaign, and then asks the delivery query — which is the only way this
 * class of defect is visible.
 */
final class AssignmentProjectionTest extends WP_UnitTestCase {

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	/**
	 * Projection under test.
	 *
	 * @var Assignment_Projection
	 */
	private Assignment_Projection $projection;

	/**
	 * Placement being filled.
	 *
	 * @var int
	 */
	private int $placement_id;

	public function set_up(): void {
		parent::set_up();

		$container = Plugin::instance()->container();

		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->assignments->install_table();

		$this->projection = $container->get( Assignment_Projection::class );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
	}

	/**
	 * A campaign in one status, with one draft assignment and a promoted creative.
	 *
	 * @param string $status     Campaign post status.
	 * @param int    $attachment Promoted attachment id, or 0 for an unpromoted creative.
	 * @return array{campaign: int, assignment: int, creative: int}
	 */
	private function fixture( string $status, int $attachment = 4242 ): array {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, 77 );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		if ( $attachment > 0 ) {
			update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment );
		}

		global $wpdb;

		// Inserted as a draft with no attachment: the state a real assignment
		// is in before its campaign has been anywhere.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Fixture for this plugin's own table.
		$wpdb->insert(
			$this->assignments->table_name(),
			array(
				'line_item_id'  => 1,
				'campaign_id'   => $campaign_id,
				'placement_id'  => $this->placement_id,
				'revision_id'   => $creative_id,
				'status'        => Assignment_Rules::DRAFT,
				'weight'        => 100,
				'click_url'     => 'https://example.com/landing',
				'attachment_id' => 0,
				'alt_text'      => 'Advertisement',
				'width'         => 728,
				'height'        => 90,
				'revision'      => 1,
			)
		);

		return array(
			'campaign'   => $campaign_id,
			'assignment' => (int) $wpdb->insert_id,
			'creative'   => $creative_id,
		);
	}

	/**
	 * One assignment's stored row.
	 *
	 * @param int $assignment_id Assignment id.
	 * @return array<string, mixed>
	 */
	private function row( int $assignment_id ): array {
		global $wpdb;

		$table = $this->assignments->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $assignment_id ), ARRAY_A );

		$this->assertIsArray( $row );

		return $row;
	}

	/**
	 * **The projection is registered on campaign transitions.**
	 *
	 * The defect was never that the mapping was wrong — it was that nothing
	 * called it. A service that projects perfectly and is not hooked is the
	 * same outage, so the wiring is asserted before the behaviour.
	 *
	 * This also proves the service boots: the hook is added in `init()`, which
	 * only runs if the container registered it and `service_init_order()` names
	 * it. A separate boot-order assertion would be the same fact twice.
	 */
	public function test_the_projection_is_hooked_to_campaign_transitions(): void {
		$this->assertNotFalse(
			has_action( 'aggr_campaign_transitioned', array( $this->projection, 'on_transition' ) ),
			'Nothing re-derives assignment status, so no campaign can ever start serving.'
		);
	}

	/**
	 * **A campaign going live makes its assignment deliverable.**
	 *
	 * Asserted through `candidates_for_placement()` rather than by reading the
	 * status column, because the column agreeing with the mapper is not the
	 * thing that matters — the fill query returning the row is.
	 */
	public function test_going_live_makes_the_assignment_a_candidate(): void {
		$fixture = $this->fixture( Post_Statuses::DRAFT );

		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'A draft campaign must not be serving, or this proves nothing.'
		);

		wp_update_post(
			array(
				'ID'          => $fixture['campaign'],
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$this->projection->project( $fixture['campaign'] );

		$candidates = $this->assignments->candidates_for_placement( $this->placement_id, time() );

		$this->assertCount( 1, $candidates, 'A live campaign served nothing.' );
		$this->assertSame( $fixture['assignment'], (int) $candidates[0]['id'] );
		$this->assertSame( 4242, (int) $candidates[0]['attachment_id'], 'The promoted attachment must reach the row delivery reads.' );
	}

	/**
	 * Pausing stops delivery, and resuming restarts it.
	 */
	public function test_pause_and_resume_move_the_assignment_with_the_campaign(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->assertCount( 1, $this->assignments->candidates_for_placement( $this->placement_id, time() ) );

		wp_update_post(
			array(
				'ID'          => $fixture['campaign'],
				'post_status' => Post_Statuses::PAUSED,
			)
		);
		$this->projection->project( $fixture['campaign'] );

		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'A paused campaign kept serving.'
		);

		wp_update_post(
			array(
				'ID'          => $fixture['campaign'],
				'post_status' => Post_Statuses::LIVE,
			)
		);
		$this->projection->project( $fixture['campaign'] );

		$this->assertCount(
			1,
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'A resumed campaign did not start serving again.'
		);
	}

	/**
	 * **An assignment a person paused stays paused through its campaign's own
	 * pause and resume.**
	 *
	 * A publisher who stops one advertisement finds it serving again after an
	 * unrelated pause and resume of its campaign, with nothing in the interface
	 * saying it moved. Both kinds of pause leave the identical row, which is why
	 * this needed a stored flag rather than a cleverer rule — and why the entry
	 * in open-work.md said so rather than guessing.
	 *
	 * The pause is made through `Assignment_Editor`, not written into the row.
	 * The whole claim is that what a person actually does is distinguishable
	 * afterwards, and a fixture that set the flag itself would be asserting its
	 * own arrangement.
	 *
	 * @return void
	 */
	public function test_an_assignment_a_person_paused_is_not_resumed_with_its_campaign(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->assertCount( 1, $this->assignments->candidates_for_placement( $this->placement_id, time() ) );

		$this->pause_by_hand( $fixture['campaign'], $fixture['assignment'] );

		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'Pausing one assignment did not stop it serving.'
		);

		$this->move_campaign( $fixture['campaign'], Post_Statuses::PAUSED );
		$this->move_campaign( $fixture['campaign'], Post_Statuses::LIVE );

		$this->assertSame(
			Assignment_Rules::PAUSED,
			$this->row( $fixture['assignment'] )['status'],
			'A campaign resume restarted an advertisement somebody had deliberately stopped.'
		);
		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'The deliberately stopped advertisement is serving again.'
		);
	}

	/**
	 * **Resuming it by hand gives it back to its campaign.**
	 *
	 * The flag is cleared on the way out as well as set on the way in. Without
	 * that it would pin the assignment: paused for ever by its own flag, or —
	 * worse — live for ever, ignoring a campaign that has since been paused.
	 *
	 * @return void
	 */
	public function test_resuming_it_by_hand_hands_it_back_to_the_campaign(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->pause_by_hand( $fixture['campaign'], $fixture['assignment'] );
		$this->resume_by_hand( $fixture['campaign'], $fixture['assignment'] );

		$this->assertCount(
			1,
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'Resuming the assignment by hand did not put it back on the page.'
		);

		$this->move_campaign( $fixture['campaign'], Post_Statuses::PAUSED );

		$this->assertSame(
			Assignment_Rules::PAUSED,
			$this->row( $fixture['assignment'] )['status'],
			'A resumed assignment stopped following its campaign into a pause.'
		);

		$this->move_campaign( $fixture['campaign'], Post_Statuses::LIVE );

		$this->assertCount(
			1,
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'A resumed assignment stopped following its campaign back out of a pause.'
		);
	}

	/**
	 * **A campaign that ends takes a hand-paused assignment with it.**
	 *
	 * An operator's pause says "not now", not "never mind what happens to the
	 * campaign". A row left `paused` under a cancelled campaign is a candidate
	 * the engine keeps considering for a campaign that has ended.
	 *
	 * @return void
	 */
	public function test_a_terminal_campaign_status_still_reaches_a_hand_paused_assignment(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->pause_by_hand( $fixture['campaign'], $fixture['assignment'] );
		$this->move_campaign( $fixture['campaign'], Post_Statuses::CANCELLED );

		$this->assertSame(
			Assignment_Rules::CANCELLED,
			$this->row( $fixture['assignment'] )['status'],
			'A hand-paused assignment outlived the campaign it belongs to.'
		);
	}

	/**
	 * Moves the campaign and re-projects, as a real transition does.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      Target campaign status.
	 * @return void
	 */
	private function move_campaign( int $campaign_id, string $status ): void {
		wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => $status,
			)
		);

		$this->projection->project( $campaign_id );
	}

	/**
	 * Pauses one assignment the way a person does, through the editor.
	 *
	 * @param int $campaign_id   Campaign post id.
	 * @param int $assignment_id Assignment id.
	 * @return void
	 */
	private function pause_by_hand( int $campaign_id, int $assignment_id ): void {
		$this->edit( $campaign_id, $assignment_id, Assignment_Rules::PAUSED );
	}

	/**
	 * Resumes one assignment the way a person does.
	 *
	 * @param int $campaign_id   Campaign post id.
	 * @param int $assignment_id Assignment id.
	 * @return void
	 */
	private function resume_by_hand( int $campaign_id, int $assignment_id ): void {
		$this->edit( $campaign_id, $assignment_id, Assignment_Rules::LIVE );
	}

	/**
	 * One status edit through the production workflow.
	 *
	 * @param int    $campaign_id   Campaign post id.
	 * @param int    $assignment_id Assignment id.
	 * @param string $status        Target assignment status.
	 * @return void
	 */
	private function edit( int $campaign_id, int $assignment_id, string $status ): void {
		/*
		 * Staff, because `Edit_Window` opens a live campaign only to
		 * REVIEW_CAMPAIGNS — which is also who would be pausing one
		 * advertisement of a running campaign in the first place.
		 */
		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();
		wp_set_current_user( (int) self::factory()->user->create( array( 'role' => 'administrator' ) ) );

		$editor   = Plugin::instance()->container()->get( Assignment_Editor::class );
		$revision = (int) $this->row( $assignment_id )['revision'];

		$result = $editor->update( $campaign_id, $assignment_id, array( 'status' => $status ), $revision );

		$this->assertIsInt(
			$result,
			'The editor refused the change, so nothing below is being tested.'
		);
	}

	/**
	 * **A withdrawn assignment is not resurrected.**
	 *
	 * The one thing a campaign transition must never undo. Without the terminal
	 * guard, retiring a creative and then pausing and resuming the campaign
	 * would put it back on the page.
	 */
	public function test_a_withdrawn_assignment_stays_withdrawn(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->assertNotFalse( $this->assignments->retire( $fixture['assignment'], $fixture['campaign'], 2 ) );
		$this->assertSame( Assignment_Rules::CANCELLED, $this->row( $fixture['assignment'] )['status'] );

		// A pause and a resume, the ordinary way a live campaign transitions twice.
		foreach ( array( Post_Statuses::PAUSED, Post_Statuses::LIVE ) as $status ) {
			wp_update_post(
				array(
					'ID'          => $fixture['campaign'],
					'post_status' => $status,
				)
			);
			$this->projection->project( $fixture['campaign'] );
		}

		$this->assertSame(
			Assignment_Rules::CANCELLED,
			$this->row( $fixture['assignment'] )['status'],
			'A withdrawal was undone by a campaign transition.'
		);

		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'A withdrawn creative came back onto the page.'
		);
	}

	/**
	 * An unpromoted creative does not blank an attachment that is already there.
	 *
	 * Projecting a zero would take a serving ad down the next time its campaign
	 * transitioned for any reason at all.
	 */
	public function test_an_unpromoted_creative_does_not_clear_the_attachment(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE, 4242 );
		$this->projection->project( $fixture['campaign'] );

		$this->assertSame( 4242, (int) $this->row( $fixture['assignment'] )['attachment_id'] );

		delete_post_meta( $fixture['creative'], Creative_Repository::META_ATTACHMENT_ID );

		wp_update_post(
			array(
				'ID'          => $fixture['campaign'],
				'post_status' => Post_Statuses::PAUSED,
			)
		);
		$this->projection->project( $fixture['campaign'] );

		$this->assertSame(
			4242,
			(int) $this->row( $fixture['assignment'] )['attachment_id'],
			'A creative with no attachment blanked one that was already serving.'
		);
	}

	/**
	 * The organization is projected too, so a moved campaign does not report
	 * deliveries against its old owner.
	 */
	public function test_the_organization_follows_the_campaign(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );
		$this->projection->project( $fixture['campaign'] );

		$this->assertSame( 77, (int) $this->row( $fixture['assignment'] )['organization_id'] );
	}

	/**
	 * **The repair, for rows that froze before any of this existed.**
	 *
	 * `Assignment_Projection` only helps from the next transition onwards. A
	 * site whose campaigns went live months ago needs the migration, and the
	 * assertion is again the delivery query rather than the column.
	 */
	public function test_the_repair_makes_a_frozen_row_deliverable(): void {
		$fixture = $this->fixture( Post_Statuses::LIVE );

		// Never projected: exactly the state every site was in.
		$this->assertSame(
			array(),
			$this->assignments->candidates_for_placement( $this->placement_id, time() )
		);

		$this->assertGreaterThan( 0, $this->assignments->reproject_all() );

		$candidates = $this->assignments->candidates_for_placement( $this->placement_id, time() );

		$this->assertCount( 1, $candidates, 'The repair did not make a live campaign serve.' );
		$this->assertSame( 4242, (int) $candidates[0]['attachment_id'] );
	}

	/**
	 * The repair fixes rows carrying a campaign status written into the column.
	 *
	 * `Assignment_Rules` records that defect for new writes and nothing ever
	 * cleaned up the rows it left — `aggr_draft` matches no assignment status
	 * and no transition accepts it.
	 */
	public function test_the_repair_fixes_a_campaign_status_written_into_the_column(): void {
		global $wpdb;

		$fixture = $this->fixture( Post_Statuses::LIVE );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Recreating the corruption the repair exists to fix.
		$wpdb->update(
			$this->assignments->table_name(),
			array( 'status' => Post_Statuses::DRAFT ),
			array( 'id' => $fixture['assignment'] ),
			array( '%s' ),
			array( '%d' )
		);

		$this->assertSame( 'aggr_draft', $this->row( $fixture['assignment'] )['status'], 'The corruption fixture must be real.' );

		$this->assignments->reproject_all();

		$this->assertSame( Assignment_Rules::LIVE, $this->row( $fixture['assignment'] )['status'] );
	}

	/**
	 * The repair is idempotent and leaves terminal rows alone.
	 */
	public function test_the_repair_is_idempotent_and_spares_withdrawals(): void {
		$live      = $this->fixture( Post_Statuses::LIVE );
		$withdrawn = $this->fixture( Post_Statuses::LIVE );

		$this->assertNotFalse( $this->assignments->retire( $withdrawn['assignment'], $withdrawn['campaign'], 1 ) );

		$this->assignments->reproject_all();
		$this->assignments->reproject_all();

		$this->assertSame( Assignment_Rules::LIVE, $this->row( $live['assignment'] )['status'] );
		$this->assertSame( Assignment_Rules::CANCELLED, $this->row( $withdrawn['assignment'] )['status'] );

		$this->assertCount(
			1,
			$this->assignments->candidates_for_placement( $this->placement_id, time() ),
			'Running the repair twice must not duplicate or resurrect anything.'
		);
	}
}
