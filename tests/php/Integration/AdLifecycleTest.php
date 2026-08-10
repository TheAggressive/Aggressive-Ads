<?php
/**
 * Cancelling, pausing and resuming a published campaign.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Integration\Adsanity\Ad_Publisher;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use WP_Error;
use WP_UnitTestCase;

/**
 * The provider effects behind cancellation, pause and resume.
 *
 * All three work through AdSanity's own read-time scheduling rather than
 * through a filter of ours, so nothing depends on a hook running on every
 * front-end request. An ad whose `_end_date` is in the past is excluded by
 * every display path AdSanity has.
 */
final class AdLifecycleTest extends WP_UnitTestCase {

	/**
	 * The subject.
	 *
	 * @var Ad_Publisher
	 */
	private Ad_Publisher $publisher;

	/**
	 * Campaign persistence.
	 *
	 * @var Campaign_Repository
	 */
	private Campaign_Repository $campaigns;

	/**
	 * The campaign's end time, comfortably in the future.
	 *
	 * @var int
	 */
	private int $end_ts = 0;

	/**
	 * A mapped, active placement.
	 *
	 * @var int
	 */
	private int $placement_id;

	/**
	 * Builds the subject and a mapped placement.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->campaigns = new Campaign_Repository();
		$this->end_ts    = time() + ( 30 * DAY_IN_SECONDS );
		$placements      = new Placement_Repository();

		$this->publisher = new Ad_Publisher(
			$this->campaigns,
			new Creative_Repository(),
			$placements,
			new Placement_Mapping( $placements )
		);

		$term = wp_insert_term( '728x90 Header', Adsanity::TAXONOMY );
		$this->assertIsArray( $term );

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);

		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_ADGROUP_TERM, (int) $term['term_id'] );
	}

	/**
	 * A campaign with one published ad.
	 *
	 * @return array{campaign: int, ad: int}
	 */
	private function published_campaign(): array {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::LIVE,
				'post_title'  => 'Spring Season',
			)
		);

		// Relative to now, deliberately: fixed epochs quietly stop being in the
		// past or the future as time passes, and a start date that has not
		// arrived makes every assertion below pass for the wrong reason.
		update_post_meta( $campaign, Campaign_Repository::META_START_TS, time() - DAY_IN_SECONDS );
		update_post_meta( $campaign, Campaign_Repository::META_END_TS, $this->end_ts );
		add_post_meta( $campaign, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		$attachment = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'creative.png',
				'post_mime_type' => 'image/png',
			)
		);

		$creative = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		foreach (
			array(
				Creative_Repository::META_CAMPAIGN_ID   => $campaign,
				Creative_Repository::META_PLACEMENT_ID  => $this->placement_id,
				Creative_Repository::META_KIND          => 'image',
				Creative_Repository::META_CLICK_URL     => 'https://example.com/tickets',
				Creative_Repository::META_ATTACHMENT_ID => $attachment,
			) as $key => $value
		) {
			update_post_meta( $creative, $key, $value );
		}

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result->is_complete() );

		return array(
			'campaign' => $campaign,
			'ad'       => $result->ad_ids()[0],
		);
	}

	/**
	 * Whether an ad would be shown right now, by AdSanity's own rule.
	 *
	 * @param int $ad_id Ad post id.
	 * @return bool
	 */
	private function would_render( int $ad_id ): bool {
		$start = (int) get_post_meta( $ad_id, Adsanity::META_START_DATE, true );
		$end   = (int) get_post_meta( $ad_id, Adsanity::META_END_DATE, true );

		return 'publish' === get_post_status( $ad_id ) && $start <= time() && $end >= time();
	}

	/**
	 * A published campaign's ad is renderable to begin with.
	 *
	 * Asserted so the tests below are measuring a change rather than a state
	 * that was already true.
	 *
	 * @return void
	 */
	public function test_a_published_ad_would_render(): void {
		$published = $this->published_campaign();

		$this->assertTrue( $this->would_render( $published['ad'] ) );
	}

	/**
	 * Cancelling takes the ad out of rotation and drafts it.
	 *
	 * @return void
	 */
	public function test_cancelling_withdraws_the_ad(): void {
		$published = $this->published_campaign();

		$this->assertTrue( $this->publisher->unpublish_campaign( $published['campaign'] ) );

		$this->assertFalse( $this->would_render( $published['ad'] ) );
		$this->assertSame( 'draft', get_post_status( $published['ad'] ) );
	}

	/**
	 * Cancelling keeps the ad post, and its counters with it.
	 *
	 * AdSanity stores per-day views and clicks on the ad. A cancelled campaign
	 * is still something the business billed for and will be asked about.
	 *
	 * @return void
	 */
	public function test_cancelling_does_not_delete_the_ad(): void {
		$published = $this->published_campaign();

		$this->publisher->unpublish_campaign( $published['campaign'] );

		$this->assertSame( Adsanity::POST_TYPE, get_post_type( $published['ad'] ) );
	}

	/**
	 * Pausing takes the ad out of rotation but leaves it published, so it can
	 * be put back.
	 *
	 * @return void
	 */
	public function test_pausing_suspends_without_drafting(): void {
		$published = $this->published_campaign();

		$this->assertTrue( $this->publisher->suppress_campaign( $published['campaign'] ) );

		$this->assertFalse( $this->would_render( $published['ad'] ) );
		$this->assertSame( 'publish', get_post_status( $published['ad'] ) );
	}

	/**
	 * **Pause and resume round-trips.**
	 *
	 * @return void
	 */
	public function test_resuming_puts_the_ad_back(): void {
		$published = $this->published_campaign();

		$this->publisher->suppress_campaign( $published['campaign'] );
		$this->assertFalse( $this->would_render( $published['ad'] ) );

		$this->assertTrue( $this->publisher->resume_campaign( $published['campaign'] ) );

		$this->assertTrue( $this->would_render( $published['ad'] ), 'The ad did not come back after resuming.' );
		$this->assertSame(
			$this->end_ts,
			(int) get_post_meta( $published['ad'], Adsanity::META_END_DATE, true )
		);
	}

	/**
	 * Resuming reads the window from the campaign, so a window edited while
	 * paused is the one that takes effect.
	 *
	 * @return void
	 */
	public function test_resuming_uses_the_campaigns_current_window(): void {
		$published = $this->published_campaign();

		$this->publisher->suppress_campaign( $published['campaign'] );

		$extended = $this->end_ts + ( 30 * DAY_IN_SECONDS );

		update_post_meta( $published['campaign'], Campaign_Repository::META_END_TS, $extended );

		$this->publisher->resume_campaign( $published['campaign'] );

		$this->assertSame(
			$extended,
			(int) get_post_meta( $published['ad'], Adsanity::META_END_DATE, true )
		);
	}

	/**
	 * An open-ended campaign resumes to the sentinel, not to zero.
	 *
	 * @return void
	 */
	public function test_an_open_ended_campaign_resumes_to_the_sentinel(): void {
		$published = $this->published_campaign();

		update_post_meta( $published['campaign'], Campaign_Repository::META_END_TS, 0 );

		$this->publisher->suppress_campaign( $published['campaign'] );
		$this->publisher->resume_campaign( $published['campaign'] );

		$this->assertSame(
			Adsanity::end_of_life(),
			(int) get_post_meta( $published['ad'], Adsanity::META_END_DATE, true )
		);
		$this->assertTrue( $this->would_render( $published['ad'] ) );
	}

	/**
	 * A campaign that never published can still be cancelled.
	 *
	 * Refusing here would leave a campaign stuck in a state it cannot leave,
	 * which is worse than the nothing there is to withdraw.
	 *
	 * @return void
	 */
	public function test_a_campaign_with_no_ads_can_still_be_cancelled(): void {
		$campaign = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::SCHEDULED,
			)
		);

		$this->assertTrue( $this->publisher->unpublish_campaign( $campaign ) );
	}

	/**
	 * An ad deleted by hand does not stop a cancellation.
	 *
	 * @return void
	 */
	public function test_a_deleted_ad_does_not_block_cancellation(): void {
		$published = $this->published_campaign();

		wp_delete_post( $published['ad'], true );

		$this->assertTrue( $this->publisher->unpublish_campaign( $published['campaign'] ) );
	}

	/**
	 * Every lifecycle effect the transition table names is implemented.
	 *
	 * The state machine fails closed on an effect it has no handler for, so a
	 * missing one would show up as a transition that simply refuses.
	 *
	 * @return void
	 */
	public function test_every_lifecycle_effect_is_implemented(): void {
		$effects = $this->publisher->lifecycle_effects();

		foreach (
			array(
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_UNPUBLISH,
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_SUPPRESS,
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_RESUME,
			) as $effect
		) {
			$this->assertArrayHasKey( $effect, $effects );
			$this->assertIsCallable( $effects[ $effect ] );
		}
	}
}
