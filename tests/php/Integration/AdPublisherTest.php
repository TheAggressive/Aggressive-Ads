<?php
/**
 * Publishing into AdSanity.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Integration;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;
use LAAO_Advertiser_Portal\Integration\Ad_Provider_Interface;
use LAAO_Advertiser_Portal\Integration\Adsanity\Ad_Publisher;
use LAAO_Advertiser_Portal\Integration\Adsanity\Adsanity;
use LAAO_Advertiser_Portal\Integration\Adsanity\Placement_Mapping;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use LAAO_Advertiser_Portal\Storage\Private_Storage;
use LAAO_Advertiser_Portal\Workflow\Creative_Promoter;
use WP_Error;
use WP_UnitTestCase;

/**
 * The publisher, against the AdSanity contract stub.
 *
 * The two properties worth most here are idempotency and partial-failure
 * recovery: approval can be clicked twice, and a campaign with four creatives
 * can fail on the third with two real ads already live.
 */
final class AdPublisherTest extends WP_UnitTestCase {

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
	 * Creative persistence.
	 *
	 * @var Creative_Repository
	 */
	private Creative_Repository $creatives;

	/**
	 * The ad group placements resolve to.
	 *
	 * @var int
	 */
	private int $term_id;

	/**
	 * An active 728x90 placement mapped to that group.
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
		$this->creatives = new Creative_Repository();
		$placements      = new Placement_Repository();
		$this->publisher = new Ad_Publisher(
			$this->campaigns,
			$this->creatives,
			$placements,
			new Placement_Mapping( $placements ),
			new Creative_Promoter( new Creative_Repository(), new Private_Storage() )
		);

		$term = wp_insert_term( '728x90 Header', Adsanity::TAXONOMY );
		$this->assertIsArray( $term );
		$this->term_id = (int) $term['term_id'];

		$this->placement_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::PLACEMENT,
				'post_status' => 'publish',
				'post_title'  => 'Homepage Leaderboard',
			)
		);
		update_post_meta( $this->placement_id, Placement_Repository::META_IS_ACTIVE, 1 );
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '728x90' );
		update_post_meta( $this->placement_id, Placement_Repository::META_ADGROUP_TERM, $this->term_id );
	}

	/**
	 * Creates a campaign with a window and the placement selected.
	 *
	 * @param int $end_ts End time, or 0 for open-ended.
	 * @return int
	 */
	private function campaign( int $end_ts = 0 ): int {
		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CAMPAIGN,
				'post_status' => Post_Statuses::APPROVED,
				'post_title'  => 'Spring Season',
			)
		);

		update_post_meta( $id, Campaign_Repository::META_START_TS, 1_900_000_000 );
		update_post_meta( $id, Campaign_Repository::META_END_TS, $end_ts );
		add_post_meta( $id, Campaign_Repository::META_PLACEMENT_ID, $this->placement_id );

		return $id;
	}

	/**
	 * Creates a creative with a real attachment behind it.
	 *
	 * @param int                  $campaign_id Owning campaign.
	 * @param array<string, mixed> $overrides   Meta overrides.
	 * @return int
	 */
	private function creative( int $campaign_id, array $overrides = array() ): int {
		$attachment_id = (int) self::factory()->attachment->create_object(
			array(
				'file'           => 'creative.png',
				'post_mime_type' => 'image/png',
			)
		);

		$id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$meta = array_merge(
			array(
				Creative_Repository::META_CAMPAIGN_ID   => $campaign_id,
				Creative_Repository::META_PLACEMENT_ID  => $this->placement_id,
				Creative_Repository::META_KIND          => 'image',
				Creative_Repository::META_WIDTH         => 728,
				Creative_Repository::META_HEIGHT        => 90,
				Creative_Repository::META_CLICK_URL     => 'https://example.com/tickets',
				Creative_Repository::META_ATTACHMENT_ID => $attachment_id,
			),
			$overrides
		);

		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, $key, $value );
		}

		return $id;
	}

	/**
	 * A creative becomes a fully configured ad.
	 *
	 * @return void
	 */
	public function test_a_creative_becomes_a_configured_ad(): void {
		$campaign = $this->campaign( 1_900_100_000 );
		$creative = $this->creative( $campaign );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result->is_complete() );
		$this->assertCount( 1, $result->created_ids() );

		$ad_id = $result->created_ids()[ $creative ];

		$this->assertSame( Adsanity::POST_TYPE, get_post_type( $ad_id ) );
		$this->assertSame( 'publish', get_post_status( $ad_id ) );
		$this->assertSame( 'https://example.com/tickets', get_post_meta( $ad_id, Adsanity::META_URL, true ) );
		$this->assertSame( '728x90', get_post_meta( $ad_id, Adsanity::META_SIZE, true ) );
		$this->assertGreaterThan( 0, (int) get_post_thumbnail_id( $ad_id ) );
		$this->assertContains(
			$this->term_id,
			array_map( 'intval', wp_get_object_terms( $ad_id, Adsanity::TAXONOMY, array( 'fields' => 'ids' ) ) )
		);
	}

	/**
	 * Campaign orchestration can depend on the provider contract, not AdSanity.
	 *
	 * @return void
	 */
	public function test_the_publisher_implements_the_provider_boundary(): void {
		$this->assertInstanceOf( Ad_Provider_Interface::class, $this->publisher );

		$container = \LAAO_Advertiser_Portal\Plugin::instance()->container();

		$this->assertSame(
			$container->get( Ad_Publisher::class ),
			$container->get( Ad_Provider_Interface::class )
		);

		$effects = $this->publisher->transition_effects();

		foreach (
			array(
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_PUBLISH,
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_UNPUBLISH,
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_SUPPRESS,
				\LAAO_Advertiser_Portal\Domain\Transition_Table::EFFECT_RESUME,
			) as $effect
		) {
			$this->assertArrayHasKey( $effect, $effects );
			$this->assertIsCallable( $effects[ $effect ] );
		}
	}

	/**
	 * **Both dates are always written, as integers.**
	 *
	 * AdSanity has no cron: scheduling is a read-time meta_query requiring
	 * both keys, so an ad missing either is invisible everywhere rather than
	 * expired. That is the failure that produces "we billed for a campaign
	 * nobody ever saw".
	 *
	 * @return void
	 */
	public function test_both_dates_are_written_as_integers(): void {
		$campaign = $this->campaign( 1_900_100_000 );
		$this->creative( $campaign );

		$result = $this->publisher->publish_campaign( $campaign );
		$ad_id  = $result->ad_ids()[0];

		$start = get_post_meta( $ad_id, Adsanity::META_START_DATE, true );
		$end   = get_post_meta( $ad_id, Adsanity::META_END_DATE, true );

		$this->assertNotSame( '', $start, 'The start date is missing; the ad renders nowhere.' );
		$this->assertNotSame( '', $end, 'The end date is missing; the ad renders nowhere.' );
		$this->assertSame( 1_900_000_000, (int) $start );
		$this->assertSame( 1_900_100_000, (int) $end );
	}

	/**
	 * An open-ended campaign stores AdSanity's sentinel rather than nothing.
	 *
	 * @return void
	 */
	public function test_an_open_ended_campaign_uses_the_sentinel(): void {
		$campaign = $this->campaign( 0 );
		$this->creative( $campaign );

		$result = $this->publisher->publish_campaign( $campaign );
		$ad_id  = $result->ad_ids()[0];

		$this->assertSame( Adsanity::end_of_life(), (int) get_post_meta( $ad_id, Adsanity::META_END_DATE, true ) );
	}

	/**
	 * **Publishing twice does not create a second ad.**
	 *
	 * Approval can be clicked twice, and a retry after a partial failure runs
	 * over creatives that already succeeded.
	 *
	 * @return void
	 */
	public function test_publishing_twice_reuses_the_same_ad(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign );

		$first = $this->publisher->publish_campaign( $campaign );
		$this->assertCount( 1, $first->created_ids() );

		$second = $this->publisher->publish_campaign( $campaign );

		$this->assertCount( 0, $second->created_ids(), 'A duplicate ad was created.' );
		$this->assertCount( 1, $second->reused_ids() );
		$this->assertSame( $first->created_ids()[ $creative ], $second->reused_ids()[ $creative ] );

		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => Adsanity::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => 10,
					'fields'      => 'ids',
				)
			)
		);
	}

	/**
	 * The campaign records each ad exactly once, however often it publishes.
	 *
	 * @return void
	 */
	public function test_provider_ids_are_recorded_once(): void {
		$campaign = $this->campaign();
		$this->creative( $campaign );

		$this->publisher->publish_campaign( $campaign );
		$this->publisher->publish_campaign( $campaign );
		$this->publisher->publish_campaign( $campaign );

		$this->assertCount( 1, $this->campaigns->provider_ad_ids( $campaign ) );
	}

	/**
	 * **A failure partway through keeps what already succeeded.**
	 *
	 * The retry then reconciles those and creates only what is missing. This
	 * is what stops one bad publish from producing duplicate ads on the next
	 * attempt.
	 *
	 * @return void
	 */
	public function test_a_partial_failure_keeps_the_successes(): void {
		$campaign = $this->campaign();

		$good = $this->creative( $campaign );
		$bad  = $this->creative( $campaign, array( Creative_Repository::META_ATTACHMENT_ID => 0 ) );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertFalse( $result->is_complete() );
		$this->assertTrue( $result->has_published() );
		$this->assertArrayHasKey( $bad, $result->failures() );
		$this->assertSame( 'laao_ads_creative_file_missing', $result->failures()[ $bad ] );

		$ad_id = $result->created_ids()[ $good ];
		$this->assertGreaterThan( 0, $ad_id );
		$this->assertSame( $ad_id, $this->creatives->provider_ad_id( $good ) );

		// The retry reuses the one that worked rather than duplicating it.
		update_post_meta( $bad, Creative_Repository::META_ATTACHMENT_ID, $this->creative( $campaign ) );

		$retry = $this->publisher->publish_campaign( $campaign );

		$this->assertSame( $ad_id, $retry->reused_ids()[ $good ] );
	}

	/**
	 * A failure after provider-object creation checkpoints a non-rendering draft.
	 *
	 * This is the recovery boundary that prevents the hardest duplicate: the
	 * provider accepted the post, but one of its undocumented meta writes did
	 * not stick. Retrying must reconcile that exact object, never create a
	 * second one or leave the incomplete first object public.
	 *
	 * @return void
	 */
	public function test_a_configuration_failure_reuses_the_checkpointed_draft_on_retry(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign );

		$block_size = static function ( $check, int $object_id, string $meta_key ) {
			if ( Adsanity::META_SIZE === $meta_key && Adsanity::POST_TYPE === get_post_type( $object_id ) ) {
				return true;
			}

			return $check;
		};

		add_filter( 'update_post_metadata', $block_size, 10, 3 );

		try {
			$failed = $this->publisher->publish_campaign( $campaign );
		} finally {
			remove_filter( 'update_post_metadata', $block_size, 10 );
		}

		$this->assertNotInstanceOf( WP_Error::class, $failed );
		$this->assertSame( 'size_not_stored', $failed->failures()[ $creative ] );

		$checkpoint = $this->creatives->provider_ad_id( $creative );

		$this->assertGreaterThan( 0, $checkpoint );
		$this->assertSame( array( $checkpoint ), $this->campaigns->provider_ad_ids( $campaign ) );
		$this->assertSame( 'draft', get_post_status( $checkpoint ), 'An incomplete ad entered rotation.' );

		$retry = $this->publisher->publish_campaign( $campaign );

		$this->assertTrue( $retry->is_complete() );
		$this->assertSame( array( $creative => $checkpoint ), $retry->reused_ids() );
		$this->assertSame( 'publish', get_post_status( $checkpoint ) );
		$this->assertCount(
			1,
			get_posts(
				array(
					'post_type'   => Adsanity::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => 10,
					'fields'      => 'ids',
				)
			),
			'A retry created a duplicate provider object.'
		);
	}

	/**
	 * Read-back includes the target flag rather than merely the visible fields.
	 *
	 * @return void
	 */
	public function test_target_write_is_verified_before_the_ad_is_published(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign, array( Creative_Repository::META_TARGET_BLANK => 1 ) );

		$block_target = static function ( $check, int $object_id, string $meta_key ) {
			if ( Adsanity::META_TARGET === $meta_key && Adsanity::POST_TYPE === get_post_type( $object_id ) ) {
				return true;
			}

			return $check;
		};

		add_filter( 'update_post_metadata', $block_target, 10, 3 );

		try {
			$result = $this->publisher->publish_campaign( $campaign );
		} finally {
			remove_filter( 'update_post_metadata', $block_target, 10 );
		}

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'target_not_stored', $result->failures()[ $creative ] );
		$this->assertSame( 'draft', get_post_status( $this->creatives->provider_ad_id( $creative ) ) );
	}

	/**
	 * A present but incorrect date is still a failed write.
	 *
	 * Merely checking for a non-empty date would let an ad run outside the
	 * campaign window while the portal declared publication successful.
	 *
	 * @return void
	 */
	public function test_date_read_back_must_match_the_campaign_exactly(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign );

		$replace_start = static function ( $check, int $object_id, string $meta_key ) {
			if ( Adsanity::META_START_DATE === $meta_key && Adsanity::POST_TYPE === get_post_type( $object_id ) ) {
				add_post_meta( $object_id, $meta_key, 123, true );

				return true;
			}

			return $check;
		};

		add_filter( 'update_post_metadata', $replace_start, 10, 3 );

		try {
			$result = $this->publisher->publish_campaign( $campaign );
		} finally {
			remove_filter( 'update_post_metadata', $replace_start, 10 );
		}

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'start_date_not_stored', $result->failures()[ $creative ] );
		$this->assertSame( 123, (int) get_post_meta( $this->creatives->provider_ad_id( $creative ), Adsanity::META_START_DATE, true ) );
		$this->assertSame( 'draft', get_post_status( $this->creatives->provider_ad_id( $creative ) ) );
	}

	/**
	 * A creative pointer alone cannot authorize rewriting an unrelated ad.
	 *
	 * A valid checkpoint is deliberately redundant: both the creative and its
	 * campaign record the provider id. If only one side points at an existing
	 * AdSanity post, reconciliation creates a new owned object instead of
	 * clobbering a manually managed ad.
	 *
	 * @return void
	 */
	public function test_a_stale_creative_pointer_does_not_rewrite_an_unrelated_ad(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign );
		$foreign  = (int) self::factory()->post->create(
			array(
				'post_type'   => Adsanity::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => 'Manually managed ad',
			)
		);

		update_post_meta( $foreign, Adsanity::META_URL, 'https://example.com/original' );
		$this->creatives->set_provider_ad_id( $creative, $foreign );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertTrue( $result->is_complete() );
		$this->assertCount( 1, $result->created_ids() );
		$this->assertNotSame( $foreign, $result->created_ids()[ $creative ] );
		$this->assertSame( 'https://example.com/original', get_post_meta( $foreign, Adsanity::META_URL, true ) );
		$this->assertSame( 'Manually managed ad', get_the_title( $foreign ) );
	}

	/**
	 * A size AdSanity does not know is refused before the write.
	 *
	 * AdSanity accepts any string for _size and renders an unrecognized one
	 * with an empty CSS class, so it will not catch our typos.
	 *
	 * @return void
	 */
	public function test_an_unknown_size_is_refused(): void {
		update_post_meta( $this->placement_id, Placement_Repository::META_SIZE, '999x999' );

		$campaign = $this->campaign();
		$creative = $this->creative( $campaign );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertFalse( $result->is_complete() );
		$this->assertSame( 'unknown_size', $result->failures()[ $creative ] );
		$this->assertSame( 0, $this->creatives->provider_ad_id( $creative ) );
	}

	/**
	 * A creative with no attachment is refused rather than published blank.
	 *
	 * @return void
	 */
	public function test_a_creative_without_an_attachment_is_refused(): void {
		$campaign = $this->campaign();
		$creative = $this->creative( $campaign, array( Creative_Repository::META_ATTACHMENT_ID => 0 ) );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertSame( 'laao_ads_creative_file_missing', $result->failures()[ $creative ] );
	}

	/**
	 * **Publishing promotes the creative, rather than requiring somebody else
	 * to have done it.**
	 *
	 * This is the regression test for a real gap: every piece of the approval
	 * path passed in isolation, but nothing called the promoter, so
	 * attachment_id stayed 0 and publication always failed with a missing
	 * attachment. Each part was tested; the seam between them was not.
	 *
	 * @return void
	 */
	public function test_publishing_promotes_the_creative_itself(): void {
		$campaign = $this->campaign();
		$creative = $this->stored_creative( $campaign );

		$this->assertSame(
			0,
			$this->creatives->attachment_id( $creative ),
			'Test precondition: nothing has promoted this creative yet.'
		);

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertNotInstanceOf( WP_Error::class, $result );
		$this->assertTrue( $result->is_complete(), 'Failures: ' . implode( ', ', $result->failures() ) );

		$attachment_id = $this->creatives->attachment_id( $creative );

		$this->assertGreaterThan( 0, $attachment_id, 'Publishing did not promote the creative.' );
		$this->assertSame( 'attachment', get_post_type( $attachment_id ) );
		$this->assertSame( $attachment_id, (int) get_post_thumbnail_id( $result->ad_ids()[0] ) );
	}

	/**
	 * Creates a creative with a real file in private storage, unpromoted.
	 *
	 * @param int $campaign_id Owning campaign.
	 * @return int
	 */
	private function stored_creative( int $campaign_id ): int {
		$image = imagecreatetruecolor( 728, 90 );

		ob_start();
		imagepng( $image );
		$bytes = (string) ob_get_clean();

		$temp = wp_tempnam( 'laao-ads-publish' );
		file_put_contents( $temp, $bytes );

		$storage  = new Private_Storage();
		$accepted = ( new \LAAO_Advertiser_Portal\Workflow\Creative_Uploader( $storage ) )->accept(
			array(
				'name'     => 'poster.png',
				'tmp_name' => $temp,
				'error'    => UPLOAD_ERR_OK,
				'size'     => strlen( $bytes ),
			)
		);

		$this->assertIsArray( $accepted );

		$creative_id = (int) self::factory()->post->create(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
			)
		);

		$this->creatives->record_upload( $creative_id, $accepted );

		foreach (
			array(
				Creative_Repository::META_CAMPAIGN_ID  => $campaign_id,
				Creative_Repository::META_PLACEMENT_ID => $this->placement_id,
				Creative_Repository::META_KIND         => 'image',
				Creative_Repository::META_CLICK_URL    => 'https://example.com/tickets',
			) as $key => $value
		) {
			update_post_meta( $creative_id, $key, $value );
		}

		if ( is_file( $temp ) ) {
			unlink( $temp );
		}

		return $creative_id;
	}

	/**
	 * An unresolved mapping aborts before anything is written.
	 *
	 * @return void
	 */
	public function test_an_unmapped_placement_aborts_before_writing(): void {
		update_post_meta( $this->placement_id, Placement_Repository::META_ADGROUP_TERM, 0 );

		$campaign = $this->campaign();
		$this->creative( $campaign );

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_placement_unmapped', $result->get_error_code() );
		$this->assertSame(
			array(),
			get_posts(
				array(
					'post_type'   => Adsanity::POST_TYPE,
					'post_status' => 'any',
					'numberposts' => 10,
					'fields'      => 'ids',
				)
			),
			'An ad was created despite the mapping failing.'
		);
	}

	/**
	 * A campaign with no creative cannot be published.
	 *
	 * @return void
	 */
	public function test_a_campaign_without_creatives_is_refused(): void {
		$campaign = $this->campaign();

		$result = $this->publisher->publish_campaign( $campaign );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_nothing_to_publish', $result->get_error_code() );
	}

	/**
	 * The effect reports incomplete publication as an error carrying both what
	 * failed and what is already live.
	 *
	 * @return void
	 */
	public function test_the_effect_reports_partial_failure_with_detail(): void {
		$campaign = $this->campaign();

		$this->creative( $campaign );
		$this->creative( $campaign, array( Creative_Repository::META_ATTACHMENT_ID => 0 ) );

		$effect = $this->publisher->as_effect();
		$result = $effect( $campaign, null );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'laao_ads_publication_incomplete', $result->get_error_code() );

		$data = $result->get_error_data();
		$this->assertIsArray( $data );
		$this->assertCount( 1, $data['failed'] );
		$this->assertCount( 1, $data['published'] );
	}

	/**
	 * A complete publication reports success to the state machine.
	 *
	 * @return void
	 */
	public function test_the_effect_reports_success(): void {
		$campaign = $this->campaign();
		$this->creative( $campaign );

		$effect = $this->publisher->as_effect();

		$this->assertTrue( $effect( $campaign, null ) );
	}
}
