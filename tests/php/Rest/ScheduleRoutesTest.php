<?php
/**
 * The campaign schedule, over REST.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Rest;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Start and end dates on the campaign PATCH route.
 *
 * Split out of WriteRoutesTest when it reached the file-length gate. These
 * share one responsibility the rest of that file does not: the schedule is the
 * only part of a campaign whose value depends on a timezone, and the browser
 * cannot be trusted to resolve it.
 */
final class ScheduleRoutesTest extends WP_UnitTestCase {

	/**
	 * An advertiser who owns the campaign.
	 *
	 * @var int
	 */
	private int $owner;

	/**
	 * The campaign under test.
	 *
	 * @var int
	 */
	private int $campaign_id;

	/**
	 * An active, selected placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Builds an owner, a campaign and the placement it has selected.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		( new Installer( new Audit_Repository(), new Roles() ) )->install_roles();

		$this->owner = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$org = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
			)
		);
		update_post_meta( $org, Org_Repository::META_OWNER_USER, $this->owner );

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
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $org );
		add_post_meta( $this->campaign_id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		do_action( 'rest_api_init', rest_get_server() );
	}

	/**
	 * REST and HTML share successful Step 4 completion validation.
	 *
	 * @return void
	 */
	public function test_rest_can_complete_destination_and_schedule_step(): void {
		wp_set_current_user( $this->owner );

		$creative_id = Plugin::instance()->container()->get( Creative_Repository::class )->create(
			$this->campaign_id,
			(int) get_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, true ),
			$this->placement_id,
			array(
				'kind'      => 'image',
				'click_url' => 'https://example.com/exhibition',
				'alt_text'  => 'Visitors viewing an exhibition',
				'size'      => '728x90',
			)
		);
		$this->assertGreaterThan( 0, $creative_id );

		$start   = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->setTime( 0, 0, 0 )->getTimestamp();
		$end     = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->setTime( 23, 59, 59 )->getTimestamp();
		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'start_ts'     => $start,
				'end_ts'       => $end,
				'wizard_step'  => 'review',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'review', $data['wizard_step'] );
		$this->assertSame( $start, $data['start_ts'] );
		$this->assertSame( $end, $data['end_ts'] );
		$this->assertSame( 1, $data['autosave_rev'] );
	}

	/**
	 * The schedule autosaves as local dates, converted in the site's timezone.
	 *
	 * A `type="date"` input holds a calendar day with no zone attached. The
	 * browser could turn it into a timestamp, and that timestamp would be the
	 * visitor's zone — for an advertiser a few hours from the site, a campaign
	 * starting "on the 14th" that begins on the 13th. So the route takes the
	 * date string and converts it the same way the no-JS form does, and this
	 * asserts the stamp that comes back is midnight *site* time.
	 *
	 * @return void
	 */
	public function test_the_schedule_autosaves_from_local_dates(): void {
		update_option( 'timezone_string', 'America/Los_Angeles' );

		wp_set_current_user( $this->owner );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$start_day = ( new \DateTimeImmutable( '+10 days', wp_timezone() ) )->format( 'Y-m-d' );
		$end_day   = ( new \DateTimeImmutable( '+20 days', wp_timezone() ) )->format( 'Y-m-d' );

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'start_date'   => $start_day,
				'end_date'     => $end_day,
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame(
			( new \DateTimeImmutable( $start_day . ' 00:00:00', wp_timezone() ) )->getTimestamp(),
			$data['start_ts'],
			'The start has to be midnight in the site timezone, not the caller\'s.'
		);
		$this->assertSame(
			( new \DateTimeImmutable( $end_day . ' 23:59:59', wp_timezone() ) )->getTimestamp(),
			$data['end_ts'],
			'The end has to close the last day, not open it.'
		);
	}

	/**
	 * An impossible date is refused rather than rolled forward.
	 *
	 * PHP turns 31 February into 3 March rather than failing, so a route that
	 * only checked for a parse failure would store a day nobody typed.
	 *
	 * @return void
	 */
	public function test_an_impossible_local_date_is_refused(): void {
		wp_set_current_user( $this->owner );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$request = new WP_REST_Request( 'PATCH', '/aggr/v1/campaigns/' . $this->campaign_id );
		$request->set_body_params(
			array(
				'start_date'   => '2026-02-31',
				'autosave_rev' => 0,
			)
		);

		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 422, $response->get_status() );
		$this->assertSame(
			0,
			(int) get_post_meta( $this->campaign_id, Campaign_Repository::META_START_TS, true ),
			'A refused date must not be partially written.'
		);
	}
}
