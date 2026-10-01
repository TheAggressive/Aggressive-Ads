<?php
/**
 * The campaign view's server data: readiness, labels and the audit trail.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Admin\Review_Data;
use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Ownership;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Campaign_Validator;
use WP_Error;
use WP_UnitTestCase;

/**
 * Split out of AdminReviewTest when it crossed the length gate. That file
 * covers the queue, authorization and the writes; this one covers what the
 * campaign view is given to draw — and in particular that the readiness list
 * agrees with the approval guard, which is the claim the list exists to make.
 */
final class ReviewDetailTest extends WP_UnitTestCase {

	/**
	 * Review data presenter under test.
	 *
	 * @var Review_Data
	 */
	private Review_Data $data;

	/**
	 * Audit repository used to write fixtures.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * Reviewer user id.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Advertiser user id.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * Fixture organization post id.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * Installs roles and creates one organization.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit = new Audit_Repository();

		( new Installer( $this->audit, new Roles() ) )->install_roles();

		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->org_id     = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'Bright Angle Media',
			)
		);

		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		$this->data = Plugin::instance()->container()->get( Review_Data::class );
	}

	/**
	 * Makes a campaign owned by the fixture organization.
	 *
	 * @param string $status Campaign status.
	 * @return int
	 */
	private function campaign( string $status ): int {
		$campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => $status,
				'post_title'  => 'Spring launch',
				'post_author' => $this->advertiser,
			)
		);

		update_post_meta( $campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );
		Plugin::instance()->container()->get( Ownership::class )->flush_cache();

		return $campaign_id;
	}

	/**
	 * **The readiness list and the approval guard say the same thing.**
	 *
	 * The list exists so a reviewer learns why approval would be refused
	 * before clicking. If it ran its own checks it could say "ready" while the
	 * guard refused — the one failure that makes it worse than no list — so
	 * this runs the guard itself on the same campaign and requires every reason
	 * the guard gives to be on the list, under a check marked blocked.
	 *
	 * @return void
	 */
	public function test_readiness_reports_every_reason_the_approval_guard_refuses(): void {
		$campaign = $this->campaign( Post_Statuses::REVIEW );

		wp_set_current_user( $this->reviewer );

		$row = $this->data->campaign( $campaign );
		$this->assertIsArray( $row );
		$this->assertIsArray( $row['readiness'] );

		$guard = Plugin::instance()->container()->get( Campaign_Validator::class )->as_approval_guard();
		$error = $guard( $campaign, array() );

		$this->assertInstanceOf( WP_Error::class, $error, 'The fixture must be one the guard refuses, or this proves nothing.' );
		$this->assertFalse( $row['readiness']['ready'] );

		$listed = array();

		foreach ( $row['readiness']['checks'] as $check ) {
			$this->assertSame( array() === $check['problems'], $check['ok'], $check['key'] . ' is ticked while carrying problems, or the reverse.' );
			$listed = array_merge( $listed, $check['problems'] );
		}

		$data = $error->get_error_data();
		$this->assertIsArray( $data );
		$this->assertNotSame( array(), $data['problems'] );

		foreach ( $data['problems'] as $problem ) {
			$this->assertContains(
				Campaign_Validator::message_for( $problem['code'], $problem['context'] ),
				$listed,
				'The guard refuses for "' . $problem['code'] . '" and the list does not say so.'
			);
		}

		// Six checks, always, in the order a reviewer reads them.
		$this->assertSame( Campaign_Rules::CHECK_GROUPS, array_column( $row['readiness']['checks'], 'key' ) );
	}

	/**
	 * Outside a waiting decision there is no list, and no validator run for it.
	 *
	 * @return void
	 */
	public function test_readiness_is_absent_once_no_decision_is_waiting(): void {
		wp_set_current_user( $this->reviewer );

		foreach ( array( Post_Statuses::DRAFT, Post_Statuses::LIVE, Post_Statuses::COMPLETE ) as $status ) {
			$row = $this->data->campaign( $this->campaign( $status ) );

			$this->assertIsArray( $row );
			$this->assertNull( $row['readiness'], $status );
		}

		$row = $this->data->campaign( $this->campaign( Post_Statuses::SUBMITTED ) );
		$this->assertIsArray( $row );
		$this->assertIsArray( $row['readiness'], 'A submitted campaign is waiting for a decision.' );
	}

	/**
	 * The strategy reads in words: labels beside the stored slugs, never the slugs.
	 *
	 * @return void
	 */
	public function test_line_items_carry_readable_labels_beside_their_values(): void {
		$campaign = $this->campaign( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$row = $this->data->campaign( $campaign );
		$this->assertIsArray( $row );

		$item = $row['line_items'][0] ?? null;
		$this->assertIsArray( $item );

		// The stored values stay, because the delivery form edits them.
		$this->assertSame( 'flat', $item['pricing_model'] );
		$this->assertSame( 'Flat fee', $item['pricing_label'] );
		$this->assertSame( 'No delivery goal', $item['goal_label'] );
		$this->assertNotSame( $item['status'], $item['status_label'] );
	}

	/**
	 * Each timeline row carries what the timeline draws: a status change's two
	 * ends as labels and pill tones, a refusal's outcome in words, and whether
	 * a person or the system acted.
	 *
	 * The negatives matter as much: a successful entry must carry no outcome
	 * word — the timeline printed "ok" and "denied" raw before this — and an
	 * entry that is not a status change must carry no pills.
	 *
	 * @return void
	 */
	public function test_timeline_rows_carry_transition_ends_and_outcome_words(): void {
		$campaign = $this->campaign( Post_Statuses::LIVE );

		wp_set_current_user( $this->reviewer );

		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.transition_denied',
				outcome: Audit_Event::OUTCOME_DENIED,
				object_type: 'campaign',
				object_id: $campaign,
				org_id: $this->org_id,
				from_state: Post_Statuses::LIVE,
				to_state: Post_Statuses::COMPLETE,
				message: 'This campaign has not reached its end date.'
			)
		);
		$this->audit->insert(
			new Audit_Event(
				event: 'campaign.note',
				object_type: 'campaign',
				object_id: $campaign,
				org_id: $this->org_id,
				message: 'A note.',
				actor_user_id: $this->reviewer
			)
		);

		$row = $this->data->campaign( $campaign );

		$this->assertIsArray( $row );

		$by_event = array_column( $row['audit'], null, 'event' );

		$refused = $by_event['campaign.transition_denied'] ?? null;
		$this->assertIsArray( $refused );
		$this->assertSame( 'Refused', $refused['outcome_label'] );
		$this->assertNotSame( '', $refused['from_label'] );
		$this->assertStringNotContainsString( 'aggr_', $refused['from_label'] . $refused['to_label'] );
		$this->assertSame( 'live', $refused['from_pill'] );
		$this->assertTrue( $refused['system'] );
		$this->assertNotSame( '', $refused['day_text'] );
		$this->assertNotSame( '', $refused['time_text'] );

		$note = $by_event['campaign.note'] ?? null;
		$this->assertIsArray( $note );
		$this->assertSame( '', $note['outcome_label'], 'A success carries no outcome word.' );
		$this->assertSame( '', $note['from_label'] . $note['to_label'] . $note['from_pill'] . $note['to_pill'], 'Not a status change, so no pills.' );
		$this->assertFalse( $note['system'] );
	}
}
