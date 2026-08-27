<?php
/**
 * Creative assignment isolation, concurrency and the window rule.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Install\Creative_Assignment_Migrator;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The rule this exists for is the window.
 *
 * Weight and pause/resume are ordinary edits; getting them wrong is annoying.
 * Getting the window wrong means a campaign sold for June carries a creative
 * that runs into July, and the publisher finds out from a bill. So the widening
 * cases are asserted from the transport, not only from the pure rules — a
 * domain rule nothing calls is a rule that is not enforced.
 */
final class AssignmentRoutesTest extends WP_UnitTestCase {

	private const PARENT_START = 1000000;
	private const PARENT_END   = 2000000;

	/**
	 * Owning advertiser.
	 *
	 * @var int
	 */
	private int $owner = 0;

	/**
	 * Unrelated advertiser.
	 *
	 * @var int
	 */
	private int $stranger = 0;

	/**
	 * Campaign under test.
	 *
	 * @var int
	 */
	private int $campaign = 0;

	/**
	 * Assignment under test.
	 *
	 * @var int
	 */
	private int $assignment = 0;

	/**
	 * Owning organization.
	 *
	 * @var int
	 */
	private int $org_id = 0;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$container = Plugin::instance()->container();

		$this->assignments = $container->get( Creative_Assignment_Repository::class );
		$this->assignments->install_table();
		$container->get( Line_Item_Repository::class )->install_table();
		$container->get( Audit_Repository::class )->install_table();

		$this->owner    = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->stranger = (int) self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->owner );

		$placement = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);

		$this->campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);

		update_post_meta( $this->campaign, Campaign_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $this->campaign, Campaign_Repository::META_START_TS, self::PARENT_START );
		update_post_meta( $this->campaign, Campaign_Repository::META_END_TS, self::PARENT_END );
		add_post_meta( $this->campaign, Campaign_Repository::META_PLACEMENT_ID, $placement );

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
				'post_author' => $this->owner,
			)
		);

		update_post_meta( $creative, Creative_Repository::META_CAMPAIGN_ID, $this->campaign );
		update_post_meta( $creative, Creative_Repository::META_ORG_ID, $this->org_id );
		update_post_meta( $creative, Creative_Repository::META_PLACEMENT_ID, $placement );
		update_post_meta( $creative, Creative_Repository::META_KIND, 'image' );

		$row = $container->get( Creative_Assignment_Migrator::class )->migrate_one( $creative );

		$this->assertIsArray( $row, 'The fixture produced no assignment to edit.' );

		$this->assignment = (int) $row['id'];

		$container->get( Org_Repository::class )->flush_cache();
		$container->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * Dispatches a REST request.
	 *
	 * @param string               $method Method.
	 * @param string               $path   Path after the namespace.
	 * @param array<string, mixed> $body   Body parameters.
	 * @return \WP_REST_Response
	 */
	private function request( string $method, string $path, array $body = array() ) {
		$request = new WP_REST_Request( $method, '/aggr/v1' . $path );

		foreach ( $body as $key => $value ) {
			$request->set_param( $key, $value );
		}

		return rest_get_server()->dispatch( $request );
	}

	/** The path to this campaign's assignment. */
	private function path(): string {
		return "/campaigns/{$this->campaign}/creative-assignments/{$this->assignment}";
	}

	public function test_the_owner_can_read_the_campaign_scoped_shape(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request( 'GET', "/campaigns/{$this->campaign}/creative-assignments" );
		$rows     = $response->get_data()['creative_assignments'];

		$this->assertSame( 200, $response->get_status() );
		$this->assertCount( 1, $rows );

		// Tenancy and migration detail never reach a client.
		$this->assertArrayNotHasKey( 'organization_id', $rows[0] );
		$this->assertArrayNotHasKey( 'compat_key', $rows[0] );
	}

	public function test_a_stranger_and_a_missing_campaign_get_the_same_answer(): void {
		wp_set_current_user( $this->stranger );

		$forbidden = $this->request( 'GET', "/campaigns/{$this->campaign}/creative-assignments" );
		$missing   = $this->request( 'GET', '/campaigns/999999/creative-assignments' );

		$this->assertSame( 404, $forbidden->get_status() );
		$this->assertSame(
			wp_json_encode( $missing->get_data() ),
			wp_json_encode( $forbidden->get_data() )
		);
	}

	public function test_the_owner_can_set_a_weight(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => 250,
			) 
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 250, (int) $response->get_data()['weight'] );
		$this->assertSame( 2, (int) $response->get_data()['revision'] );
	}

	/**
	 * A window inside the campaign's is accepted.
	 *
	 * The positive half. Without it the widening assertions below would pass on
	 * a route that refused every date.
	 */
	public function test_a_narrower_window_is_accepted(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision'    => 1,
				'start_at_ts' => self::PARENT_START + 100,
				'end_at_ts'   => self::PARENT_END - 100,
			) 
		);

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( self::PARENT_START + 100, (int) $response->get_data()['start_at_ts'] );
	}

	/**
	 * A window that runs past the campaign is refused, not clamped.
	 *
	 * The rule this whole file exists for. Storing a silently different date
	 * than the one submitted is how an advertiser discovers months later that
	 * their creative never ran when they thought.
	 */
	public function test_a_window_wider_than_the_campaign_is_refused(): void {
		wp_set_current_user( $this->owner );

		$late = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision'  => 1,
				'end_at_ts' => self::PARENT_END + 1,
			) 
		);

		$this->assertSame( 422, $late->get_status() );
		$this->assertSame( 'aggr_assignment_window_invalid', $late->get_data()['code'] );

		$early = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision'    => 1,
				'start_at_ts' => self::PARENT_START - 1,
			) 
		);

		$this->assertSame( 422, $early->get_status() );

		// And nothing was stored: a refusal that half-applies is worse than one
		// that fails.
		$row = $this->assignments->find_for_campaign( $this->assignment, $this->campaign );
		$this->assertSame( 0, (int) $row['end_at_ts'] );
		$this->assertSame( 1, (int) $row['revision'] );
	}

	/** Pause and resume are both reachable through the route. */
	public function test_pause_and_resume_round_trip(): void {
		wp_set_current_user( $this->owner );

		$this->assignments->update( $this->assignment, $this->campaign, array( 'status' => Assignment_Rules::LIVE ), 1 );

		$paused = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 2,
				'status'   => Assignment_Rules::PAUSED,
			) 
		);

		$this->assertSame( 200, $paused->get_status() );
		$this->assertSame( Assignment_Rules::PAUSED, (string) $paused->get_data()['status'] );

		$resumed = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 3,
				'status'   => Assignment_Rules::LIVE,
			) 
		);

		$this->assertSame( 200, $resumed->get_status() );
		$this->assertSame( Assignment_Rules::LIVE, (string) $resumed->get_data()['status'] );
	}

	/** A transition the table does not declare is refused. */
	public function test_an_undeclared_transition_is_refused(): void {
		wp_set_current_user( $this->owner );

		$this->assignments->update( $this->assignment, $this->campaign, array( 'status' => Assignment_Rules::CANCELLED ), 1 );

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 2,
				'status'   => Assignment_Rules::LIVE,
			) 
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'aggr_assignment_transition_invalid', $response->get_data()['code'] );
	}

	/** A stale revision loses, and is told what the current one is. */
	public function test_a_stale_revision_conflicts(): void {
		wp_set_current_user( $this->owner );

		$this->assertSame(
			200,
			$this->request(
				'PATCH',
				$this->path(),
				array(
					'revision' => 1,
					'weight'   => 200,
				) 
			)->get_status() 
		);

		$stale = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => 300,
			) 
		);

		$this->assertSame( 409, $stale->get_status() );
		$this->assertSame( 2, (int) $stale->get_data()['data']['current_revision'] );

		// The losing write changed nothing.
		$row = $this->assignments->find_for_campaign( $this->assignment, $this->campaign );
		$this->assertSame( 200, (int) $row['weight'] );
	}

	/** An empty update is refused rather than bumping the revision. */
	public function test_an_empty_update_is_refused(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request( 'PATCH', $this->path(), array( 'revision' => 1 ) );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame( 'aggr_assignment_fields_required', $response->get_data()['code'] );
	}

	/**
	 * Lossy numeric forms are refused before anything coerces them.
	 *
	 * The same rule the line-item route already enforces, asserted here because
	 * a second controller is a second chance to forget it: `is_numeric()`
	 * accepts `"1.5"` and `absint()` makes it 1, which is a lossy write
	 * reported as a successful one.
	 */
	public function test_a_fractional_weight_is_refused(): void {
		wp_set_current_user( $this->owner );

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => '2.5',
			) 
		);

		$this->assertSame( 400, $response->get_status() );

		$row = $this->assignments->find_for_campaign( $this->assignment, $this->campaign );
		$this->assertSame( 100, (int) $row['weight'] );
	}

	/** A stranger cannot edit, and cannot tell edit from missing. */
	public function test_a_stranger_cannot_edit(): void {
		wp_set_current_user( $this->stranger );

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => 500,
			) 
		);

		$this->assertSame( 404, $response->get_status() );

		$row = $this->assignments->find_for_campaign( $this->assignment, $this->campaign );
		$this->assertSame( 100, (int) $row['weight'] );
	}

	/** An assignment id from another campaign does not resolve. */
	public function test_a_cross_campaign_assignment_id_is_not_found(): void {
		wp_set_current_user( $this->owner );

		$other = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::DRAFT,
				'post_author' => $this->owner,
			)
		);

		/*
		 * Same organization, deliberately.
		 *
		 * Without it the campaign-level capability check refuses first and this
		 * asserts the wrong gate — it would pass with the assignment lookup's
		 * campaign scoping removed entirely, which is exactly what it exists to
		 * cover. Sabotage found that.
		 */
		update_post_meta( $other, Campaign_Repository::META_ORG_ID, $this->org_id );

		$response = $this->request(
			'PATCH',
			"/campaigns/{$other}/creative-assignments/{$this->assignment}",
			array(
				'revision' => 1,
				'weight'   => 500,
			) 
		);

		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * A campaign outside its edit window refuses the change.
	 *
	 * The gate is `Edit_Window`, the same one the line-item and creative paths
	 * ask. Without a non-editable fixture this is untested: every other case
	 * here uses a draft, which is editable, so removing the check changes
	 * nothing observable. Sabotage found that.
	 */
	public function test_a_campaign_outside_its_edit_window_refuses_the_change(): void {
		wp_set_current_user( $this->owner );

		// Live is not advertiser-editable; the change-request flow exists for
		// exactly that reason.
		wp_update_post(
			array(
				'ID'          => $this->campaign,
				'post_status' => Post_Statuses::LIVE,
			)
		);

		$response = $this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => 700,
			)
		);

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'aggr_campaign_not_editable', $response->get_data()['code'] );

		$row = $this->assignments->find_for_campaign( $this->assignment, $this->campaign );
		$this->assertSame( 100, (int) $row['weight'] );
	}

	/**
	 * The change is audited, with what changed and who changed it.
	 *
	 * A count as well as the fields: a route that audited the refusals too
	 * would satisfy "an audit row exists".
	 */
	public function test_the_change_is_audited(): void {
		wp_set_current_user( $this->owner );

		$this->request(
			'PATCH',
			$this->path(),
			array(
				'revision' => 1,
				'weight'   => 400,
			) 
		);

		global $wpdb;
		$table = Plugin::instance()->container()->get( Audit_Repository::class )->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test assertion against this plugin's own table.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT actor_user_id, event, context FROM %i WHERE object_type = %s AND object_id = %d',
				$table,
				'creative_assignment',
				$this->assignment
			),
			ARRAY_A
		);

		$this->assertCount( 1, $rows, 'Exactly the accepted update should have been audited.' );
		$this->assertSame( $this->owner, (int) $rows[0]['actor_user_id'] );
		$this->assertSame( 'creative_assignment.updated', $rows[0]['event'] );

		$context = json_decode( (string) $rows[0]['context'], true );
		$this->assertSame( array( 'weight' ), $context['fields'] );
		$this->assertSame( 2, (int) $context['revision'] );
	}
}
