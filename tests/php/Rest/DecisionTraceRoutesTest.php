<?php
/**
 * Staff decision trace route.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Missing, forbidden and anonymous must look identical to a caller.
 */
final class DecisionTraceRoutesTest extends WP_UnitTestCase {

	/**
	 * Fixture placement.
	 *
	 * @var int
	 */
	private int $placement_id = 0;

	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );

		do_action( 'rest_api_init' );
	}

	public function test_anonymous_request_is_refused_as_missing(): void {
		$response = rest_do_request(
			new WP_REST_Request(
				'GET',
				'/aggr/v1/placements/' . $this->placement_id . '/decision'
			)
		);

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_advertiser_is_refused_like_anonymous(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => Roles::ADVERTISER ) );
		wp_set_current_user( (int) $user->ID );

		$response = rest_do_request(
			new WP_REST_Request(
				'GET',
				'/aggr/v1/placements/' . $this->placement_id . '/decision'
			)
		);

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_reviewer_receives_a_trace_for_a_real_placement(): void {
		$user = self::factory()->user->create_and_get( array( 'role' => Roles::REVIEWER ) );
		wp_set_current_user( (int) $user->ID );

		$this->assertTrue( user_can( $user, Capabilities::REVIEW_CAMPAIGNS ) );

		$response = rest_do_request(
			new WP_REST_Request(
				'GET',
				'/aggr/v1/placements/' . $this->placement_id . '/decision'
			)
		);

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( $this->placement_id, (int) ( $data['placement_id'] ?? 0 ) );
		$this->assertArrayHasKey( 'trace', $data );
		$this->assertArrayHasKey( 'entries', $data['trace'] );

		/*
		 * The trace endpoint passes `$record_metrics = false`, so looking at a
		 * decision must not count as one having happened. Asserted against the
		 * durable table rather than the option this used to check: a staff
		 * request that inflated the request count would make fill rate a
		 * function of how often somebody debugged the placement.
		 */
		$rollups = Plugin::instance()->container()->get( \Aggressive\Ads\Repository\Decision_Rollup_Repository::class );
		$rollups->install_table();

		$this->assertSame( array(), $rollups->totals_for_placement( $this->placement_id, gmdate( 'Y-m-d' ), gmdate( 'Y-m-d' ) ) );
	}
}
