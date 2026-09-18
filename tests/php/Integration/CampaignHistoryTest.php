<?php
/**
 * A campaign's history as its advertiser reads it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Portal\Campaign_History_View_Data;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Security\Roles;
use WP_UnitTestCase;

/**
 * The allowlist against real rows, and the leaks that must not happen.
 *
 * `AdvertiserEventsTest` judges event names in isolation, which cannot catch
 * the thing that actually goes wrong here: agreeing with itself about a value
 * the rest of the plugin never writes. The outcome column is `ok`, not
 * `success`, and a unit test written against the wrong string passed while
 * the screen would have shown nothing at all. These rows are written the way
 * the workflow writes them.
 */
final class CampaignHistoryTest extends WP_UnitTestCase {

	/**
	 * The reader under test.
	 *
	 * @var Campaign_History_View_Data
	 */
	private Campaign_History_View_Data $history;

	/**
	 * The trail.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * The advertiser's organization.
	 *
	 * @var int
	 */
	private int $org_id;

	/**
	 * The advertiser.
	 *
	 * @var int
	 */
	private int $advertiser;

	/**
	 * A member of the review team.
	 *
	 * @var int
	 */
	private int $reviewer;

	/**
	 * Their campaign.
	 *
	 * @var int
	 */
	private int $campaign_id;

	public function set_up(): void {
		parent::set_up();

		$this->audit = new Audit_Repository();
		( new Installer( $this->audit, new Roles() ) )->install();

		$this->history    = new Campaign_History_View_Data( $this->audit );
		$this->advertiser = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );
		$this->reviewer   = self::factory()->user->create( array( 'role' => Roles::REVIEWER ) );

		$this->org_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::ORGANIZATION,
				'post_status' => 'publish',
				'post_title'  => 'History org',
			)
		);
		update_post_meta( $this->org_id, Org_Repository::META_OWNER_USER, $this->advertiser );

		$this->campaign_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
				'post_author' => $this->advertiser,
			)
		);
		update_post_meta( $this->campaign_id, Campaign_Repository::META_ORG_ID, $this->org_id );

		wp_set_current_user( $this->advertiser );
	}

	/**
	 * Writes one audit row the way the workflow does.
	 *
	 * The role is not passed: `Audit_Repository` derives it from the actor,
	 * which is the behaviour the reader has to survive.
	 *
	 * @param string $event   Event name.
	 * @param array  $overrides Row overrides.
	 * @return void
	 *
	 * @phpstan-param array<string, mixed> $overrides
	 */
	private function record( string $event, array $overrides = array() ): void {
		$this->audit->insert(
			new Audit_Event(
				event: $event,
				outcome: (string) ( $overrides['outcome'] ?? Audit_Event::OUTCOME_OK ),
				object_type: 'campaign',
				object_id: $this->campaign_id,
				org_id: $this->org_id,
				from_state: (string) ( $overrides['from'] ?? '' ),
				to_state: (string) ( $overrides['to'] ?? '' ),
				message: (string) ( $overrides['message'] ?? '' ),
				actor_user_id: (int) ( $overrides['actor'] ?? $this->advertiser )
			)
		);
	}

	/**
	 * The codes the advertiser would see, newest first.
	 *
	 * @return array<int, string>
	 */
	private function codes(): array {
		return array_map(
			static fn ( array $item ): string => (string) $item['code'],
			$this->history->history( $this->campaign_id, $this->org_id )['items']
		);
	}

	public function test_it_reads_the_trail_the_workflow_actually_writes(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		$items = $this->history->history( $this->campaign_id, $this->org_id )['items'];

		$this->assertCount( 1, $items, 'The outcome the workflow writes was not recognised.' );
		$this->assertSame( 'submitted', $items[0]['code'] );
		$this->assertSame( 'Submitted for review', $items[0]['text'] );
		$this->assertGreaterThan( 0, $items[0]['at'] );
	}

	public function test_internal_events_never_reach_the_advertiser(): void {
		$this->record(
			'campaign.internal_notes_updated',
			array(
				'actor'   => $this->reviewer,
				'message' => 'Advertiser is behind on payment.',
			)
		);
		$this->record(
			'campaign.notification_failed',
			array(
				'outcome' => Audit_Event::OUTCOME_FAILED,
				'message' => 'SMTP refused the message.',
			)
		);
		$this->record(
			'campaign.transition_denied',
			array(
				'outcome' => Audit_Event::OUTCOME_DENIED,
				'to'      => Post_Statuses::APPROVED,
			)
		);
		$this->record( 'campaign.status_changed_outside_workflow' );
		$this->record( 'creative.upload_failed', array( 'outcome' => Audit_Event::OUTCOME_FAILED ) );

		$history = $this->history->history( $this->campaign_id, $this->org_id );

		$this->assertSame( array(), $history['items'], 'An internal event was shown to the advertiser.' );
		$this->assertSame( array(), $history['stages'] );
	}

	public function test_it_never_repeats_an_internal_message(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from'    => Post_Statuses::SUBMITTED,
				'to'      => Post_Statuses::REVIEW,
				'actor'   => $this->reviewer,
				'message' => 'Claimed by Dana, who thinks the artwork is off-brand.',
			)
		);

		$items = $this->history->history( $this->campaign_id, $this->org_id )['items'];

		$this->assertCount( 1, $items );
		$this->assertSame( 'Review started', $items[0]['text'] );
		$this->assertStringNotContainsString( 'Dana', (string) wp_json_encode( $items ) );
		$this->assertStringNotContainsString( 'off-brand', (string) wp_json_encode( $items ) );
	}

	public function test_it_names_the_actor_in_three_terms_and_no_others(): void {
		$colleague = self::factory()->user->create( array( 'role' => Roles::ADVERTISER ) );

		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::SUBMITTED,
				'to'    => Post_Statuses::REVIEW,
				'actor' => $this->reviewer,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::REVIEW,
				'to'    => Post_Statuses::APPROVED,
				'actor' => $colleague,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::APPROVED,
				'to'    => Post_Statuses::SCHEDULED,
				'actor' => 0,
			)
		);

		$who = array_map(
			static fn ( array $item ): string => (string) $item['who'],
			$this->history->history( $this->campaign_id, $this->org_id )['items']
		);

		// Newest first: scheduled, approved, review, submitted.
		$this->assertSame( array( 'Automatically', 'Your team', 'The review team', 'You' ), $who );

		$names = array( get_userdata( $this->reviewer )->user_login, get_userdata( $colleague )->user_login );

		foreach ( $names as $name ) {
			$this->assertStringNotContainsString( $name, (string) wp_json_encode( $who ), 'A person was named.' );
		}
	}

	public function test_returning_to_draft_reads_differently_by_where_it_came_from(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::SUBMITTED,
				'to'   => Post_Statuses::DRAFT,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::REVIEW,
				'to'    => Post_Statuses::DRAFT,
				'actor' => $this->reviewer,
			)
		);

		$this->assertSame( array( 'changes_requested', 'withdrawn' ), $this->codes() );
	}

	public function test_a_stage_is_dated_from_the_first_time_it_was_reached(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		$first = $this->history->history( $this->campaign_id, $this->org_id )['stages'][ Post_Statuses::SUBMITTED ];

		// Sent back, edited, and submitted again a while later.
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::REVIEW,
				'to'   => Post_Statuses::DRAFT,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		$stages = $this->history->history( $this->campaign_id, $this->org_id )['stages'];

		$this->assertSame( $first, $stages[ Post_Statuses::SUBMITTED ], 'Resubmitting rewrote when the campaign was first submitted.' );
		$this->assertArrayNotHasKey( Post_Statuses::DRAFT, $stages, 'Draft is not a dated stage.' );
	}

	public function test_another_organizations_campaign_has_no_history_here(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		$other = $this->history->history( $this->campaign_id, $this->org_id + 1_000 );

		$this->assertSame( array(), $other['items'] );
		$this->assertSame( array(), $other['stages'] );
	}

	public function test_the_list_is_bounded_and_newest_first(): void {
		for ( $i = 0; $i < Campaign_History_View_Data::SHOWN + 5; $i++ ) {
			$this->record( 'creative.uploaded' );
		}

		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		$items = $this->history->history( $this->campaign_id, $this->org_id )['items'];

		$this->assertCount( Campaign_History_View_Data::SHOWN, $items );
		$this->assertSame( 'submitted', $items[0]['code'], 'The newest entry was not first.' );
	}

	public function test_a_run_of_hidden_events_does_not_empty_the_list(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);

		// More refusals than the screen holds, all of them invisible.
		for ( $i = 0; $i < 40; $i++ ) {
			$this->record( 'campaign.transition_denied', array( 'outcome' => Audit_Event::OUTCOME_DENIED ) );
		}

		$this->assertSame( array( 'submitted' ), $this->codes(), 'The visible entry was scrolled off by hidden ones.' );
	}

	/**
	 * Renders the status partial for the campaign under test.
	 *
	 * The reader and the screen have to meet somewhere: a history nothing
	 * renders is a list of arrays, and the timeline's dates come from the same
	 * call as the activity entries.
	 *
	 * @return string
	 */
	private function render(): string {
		$aggr_campaign = array(
			'id'                  => $this->campaign_id,
			'status'              => Post_Statuses::LIVE,
			'status_text'         => 'Live',
			'pill'                => 'live',
			'start_date'          => '2030-06-01',
			'end_date'            => '2030-06-30',
			'submitted_at'        => 0,
			'advertiser_notes'    => '',
			'package_name'        => 'Launch bundle',
			'can_withdraw'        => false,
			'can_request_updates' => false,
			'dates'               => 'Jun 1 – Jun 30, 2030',
			'default_click_url'   => 'https://example.com/offer',
			'package_price'       => '$450.00',
			'history'             => $this->history->history( $this->campaign_id, $this->org_id ),
		);

		$aggr_creatives        = array();
		$aggr_run_days         = 30;
		$aggr_package_duration = '30 days';
		$aggr_size_labels      = array( '728×90' );

		ob_start();
		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-status.php';

		return (string) ob_get_clean();
	}

	public function test_the_screen_dates_its_stages_and_lists_what_happened(): void {
		$this->record(
			'campaign.transitioned',
			array(
				'from' => Post_Statuses::DRAFT,
				'to'   => Post_Statuses::SUBMITTED,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::SUBMITTED,
				'to'    => Post_Statuses::REVIEW,
				'actor' => $this->reviewer,
			)
		);
		$this->record(
			'campaign.transitioned',
			array(
				'from'  => Post_Statuses::REVIEW,
				'to'    => Post_Statuses::APPROVED,
				'actor' => $this->reviewer,
			)
		);

		$html     = $this->render();
		$today    = (string) wp_date( 'M j' );
		$timeline = $this->timeline( $html );

		$this->assertStringContainsString( 'Review started', $html );
		$this->assertStringContainsString( 'The review team', $html );
		$this->assertStringContainsString( 'Submitted for review', $html );

		/*
		 * Four, not three: Draft is dated from the post itself, which these
		 * fixtures create a moment before the rows. What matters is that
		 * Submitted, In review and Approved are no longer dashes.
		 */
		$this->assertSame( 4, substr_count( $timeline, $today ) );
		$this->assertSame( 0, substr_count( $timeline, '—' ), 'A stage the trail dates was still showing a dash.' );

		/*
		 * Still there, and correctly: no event records a campaign being
		 * created, so the post's own date is the only source for it.
		 */
		$this->assertStringContainsString( 'Draft started from Launch bundle', $html );
		$this->assertSame( 1, substr_count( $html, 'Submitted for review' ), 'The fallback entry was shown beside the trail.' );
	}

	public function test_a_campaign_older_than_the_trail_still_shows_its_own_dates(): void {
		$html = $this->render();

		$this->assertStringContainsString( 'Draft started from Launch bundle', $html );
		// Submitted, In review and Approved have no rows and must not invent any.
		$this->assertSame( 3, substr_count( $this->timeline( $html ), '—' ) );
	}

	/**
	 * Just the dated stage list, so activity entries do not count as stages.
	 *
	 * @param string $html The rendered partial.
	 * @return string
	 */
	private function timeline( string $html ): string {
		$start = strpos( $html, '<ol class="aggr-timeline">' );
		$end   = false === $start ? false : strpos( $html, '</ol>', $start );

		$this->assertIsInt( $start, 'The timeline is not on the screen at all.' );
		$this->assertIsInt( $end );

		return substr( $html, (int) $start, (int) $end - (int) $start );
	}
}
