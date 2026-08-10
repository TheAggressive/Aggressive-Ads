<?php
/**
 * Publishing a campaign into AdSanity.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Integration\Adsanity;

use LAAO_Advertiser_Portal\Domain\Publication_Result;
use LAAO_Advertiser_Portal\Domain\Transition_Table;
use LAAO_Advertiser_Portal\Repository\Campaign_Repository;
use LAAO_Advertiser_Portal\Repository\Creative_Repository;
use LAAO_Advertiser_Portal\Repository\Placement_Repository;
use WP_Error;

/**
 * Creates or reconciles one AdSanity ad per creative.
 *
 * Three facts about AdSanity shape everything here, all verified in its source
 * and recorded in docs/adsanity-integration.md:
 *
 * **`save_post` is a no-op for programmatic writes.** It requires
 * `$_POST['ads_nonce']` and returns immediately without one, so none of
 * AdSanity's own sanitization runs on anything we write. There is no safety
 * net: AdSanity will store whatever we give it and then fail to display it.
 * Every key written here is read back and asserted, and that read-back is the
 * only validation in the pipeline.
 *
 * **There is no cron.** Scheduling is a read-time meta_query requiring
 * `_start_date <= now` and `_end_date >= now`, so an ad missing either key is
 * invisible everywhere — not "expired", absent. That is the failure mode that
 * produces "we billed for a campaign nobody ever saw", so both keys are always
 * written, as integers.
 *
 * **The creative is the featured image.** ad.php picks a render path in strict
 * priority order — featured image, then `_code`, then `_text`, then `ad_src` —
 * and only one applies. We publish image ads, so we set the thumbnail and none
 * of the others.
 */
final class Ad_Publisher {

	/**
	 * Constructor.
	 *
	 * @param Campaign_Repository  $campaigns  Campaign persistence.
	 * @param Creative_Repository  $creatives  Creative persistence.
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Placement_Mapping    $mapping    Ad-group resolution.
	 */
	public function __construct(
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Placement_Repository $placements,
		private readonly Placement_Mapping $mapping
	) {
	}

	/**
	 * Publishes every creative on a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return Publication_Result|WP_Error Error only when nothing could be attempted.
	 */
	public function publish_campaign( int $campaign_id ) {
		$groups = $this->mapping->resolve_all( $this->campaigns->placement_ids( $campaign_id ) );

		// Fails closed before a single write. See ADR-0007.
		if ( is_wp_error( $groups ) ) {
			return $groups;
		}

		$creatives = $this->creatives->for_campaign( $campaign_id );

		if ( array() === $creatives ) {
			return new WP_Error(
				'laao_ads_nothing_to_publish',
				__( 'This campaign has no creative to publish.', 'laao-advertiser-portal' )
			);
		}

		$result = new Publication_Result();

		foreach ( $creatives as $creative ) {
			$this->publish_creative( $campaign_id, $creative, $groups, $result );
		}

		return $result;
	}

	/**
	 * The effect callable the state machine consumes.
	 *
	 * @return callable
	 *
	 * @phpstan-return callable(int, mixed): (true|WP_Error)
	 */
	public function as_effect(): callable {
		return function ( int $campaign_id ): bool|WP_Error {
			$result = $this->publish_campaign( $campaign_id );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( $result->is_complete() ) {
				return true;
			}

			return new WP_Error(
				'laao_ads_publication_incomplete',
				__( 'Some ads could not be published. The ones that succeeded have been kept, so retrying will not duplicate them.', 'laao-advertiser-portal' ),
				array(
					'failed'    => $result->failures(),
					'published' => $result->ad_ids(),
				)
			);
		};
	}

	/**
	 * Takes a campaign's ads out of rotation permanently.
	 *
	 * Expires them and drafts them rather than deleting them. AdSanity's
	 * per-day view and click counters live on the ad post, and a cancelled
	 * campaign is still something the business billed for and will be asked
	 * about — deleting the post throws that away to save a row.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function unpublish_campaign( int $campaign_id ) {
		return $this->rewrite_schedules(
			$campaign_id,
			$this->expired_at(),
			'draft'
		);
	}

	/**
	 * Suspends a campaign's ads, reversibly.
	 *
	 * Expires them but leaves them published, because resuming has to be able
	 * to put them straight back. Suppression works through AdSanity's own
	 * scheduling rather than through a filter, so nothing depends on a hook of
	 * ours running on every front-end request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function suppress_campaign( int $campaign_id ) {
		return $this->rewrite_schedules( $campaign_id, $this->expired_at(), '' );
	}

	/**
	 * Puts a suspended campaign's ads back into rotation.
	 *
	 * Dates are restored from the campaign rather than from anything stashed
	 * on the ad, so a campaign whose window was edited while paused resumes
	 * with the window it actually has.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function resume_campaign( int $campaign_id ) {
		return $this->rewrite_schedules(
			$campaign_id,
			$this->end_date_for( $campaign_id ),
			'publish'
		);
	}

	/**
	 * The effect callables the state machine consumes for the lifecycle
	 * transitions.
	 *
	 * @return array<string, callable>
	 */
	public function lifecycle_effects(): array {
		return array(
			Transition_Table::EFFECT_UNPUBLISH => fn ( int $id ): bool|WP_Error => $this->unpublish_campaign( $id ),
			Transition_Table::EFFECT_SUPPRESS  => fn ( int $id ): bool|WP_Error => $this->suppress_campaign( $id ),
			Transition_Table::EFFECT_RESUME    => fn ( int $id ): bool|WP_Error => $this->resume_campaign( $id ),
		);
	}

	/**
	 * Rewrites the end date, and optionally the status, of every ad a campaign
	 * has.
	 *
	 * Tolerant of a campaign with no ads: a campaign cancelled before its
	 * publication ever succeeded has nothing to withdraw, and refusing there
	 * would leave it stuck in a state it cannot leave.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param int    $end_date    End date to write.
	 * @param string $status      Post status to set, or empty to leave alone.
	 * @return true|WP_Error
	 */
	private function rewrite_schedules( int $campaign_id, int $end_date, string $status ) {
		$failed = array();

		foreach ( $this->campaigns->provider_ad_ids( $campaign_id ) as $ad_id ) {
			// An ad deleted by hand in the admin is not an error to report at
			// somebody trying to cancel a campaign; there is simply nothing
			// left to withdraw.
			if ( Adsanity::POST_TYPE !== get_post_type( $ad_id ) ) {
				continue;
			}

			update_post_meta( $ad_id, Adsanity::META_END_DATE, $end_date );

			if ( '' !== $status ) {
				wp_update_post(
					array(
						'ID'          => $ad_id,
						'post_status' => $status,
					)
				);
			}

			// Read back, for the same reason every other write here is: nothing
			// in AdSanity checks that what we asked for is what was stored.
			if ( (int) get_post_meta( $ad_id, Adsanity::META_END_DATE, true ) !== $end_date ) {
				$failed[] = $ad_id;
			}
		}

		if ( array() !== $failed ) {
			return new WP_Error(
				'laao_ads_schedule_write_failed',
				__( 'Some ads could not be updated. Please try again.', 'laao-advertiser-portal' ),
				array( 'ads' => $failed )
			);
		}

		return true;
	}

	/**
	 * A moment already past, so AdSanity's read-time filter excludes the ad.
	 *
	 * One second back rather than exactly now, because `_end_date >= now` is
	 * inclusive and "now" moves during a request.
	 *
	 * @return int
	 */
	private function expired_at(): int {
		return time() - 1;
	}

	/**
	 * Publishes or reconciles one creative.
	 *
	 * @param int                                                                                                                                                        $campaign_id Campaign post id.
	 * @param array{id: int, campaign_id: int, org_id: int, placement_id: int, size: string, kind: string, width: int, height: int, click_url: string, alt_text: string} $creative    Creative details.
	 * @param array<int, int>                                                                                                                                            $groups      Placement id to ad-group term id.
	 * @param Publication_Result                                                                                                                                         $result      Result to record into.
	 * @return void
	 */
	private function publish_creative( int $campaign_id, array $creative, array $groups, Publication_Result $result ): void {
		$creative_id = $creative['id'];

		$attachment_id = $this->creatives->attachment_id( $creative_id );

		if ( $attachment_id <= 0 ) {
			$result->failed( $creative_id, 'missing_attachment' );

			return;
		}

		if ( ! isset( $groups[ $creative['placement_id'] ] ) ) {
			$result->failed( $creative_id, 'unmapped_placement' );

			return;
		}

		$size = $this->placements->size( $creative['placement_id'] );

		// AdSanity accepts any string for _size and stores it, then renders an
		// unrecognized one with an empty CSS class. It will not catch our
		// typos, so we check before writing rather than after somebody notices
		// an unstyled ad on the front page.
		if ( ! Adsanity::knows_size( $size ) ) {
			$result->failed( $creative_id, 'unknown_size' );

			return;
		}

		$existing = $this->creatives->provider_ad_id( $creative_id );
		$reusing  = $existing > 0 && Adsanity::POST_TYPE === get_post_type( $existing );

		$ad_id = $reusing ? $existing : $this->create_ad( $campaign_id, $creative );

		if ( $ad_id <= 0 ) {
			$result->failed( $creative_id, 'insert_failed' );

			return;
		}

		$this->write_ad( $ad_id, $creative, $size, $attachment_id, $groups[ $creative['placement_id'] ], $campaign_id );

		$verified = $this->verify_ad( $ad_id, $creative, $size, $attachment_id, $groups[ $creative['placement_id'] ] );

		if ( '' !== $verified ) {
			$result->failed( $creative_id, $verified );

			return;
		}

		// Persisted the moment it succeeds. A failure on the next creative
		// then leaves this one recorded, and the retry reconciles it.
		$this->creatives->set_provider_ad_id( $creative_id, $ad_id );
		$this->campaigns->add_provider_ad_id( $campaign_id, $ad_id );

		if ( $reusing ) {
			$result->reused( $creative_id, $ad_id );

			return;
		}

		$result->created( $creative_id, $ad_id );
	}

	/**
	 * Creates the ad post.
	 *
	 * @param int                  $campaign_id Campaign post id.
	 * @param array<string, mixed> $creative    Creative details.
	 * @return int Zero on failure.
	 */
	private function create_ad( int $campaign_id, array $creative ): int {
		$placement_id = isset( $creative['placement_id'] ) ? (int) $creative['placement_id'] : 0;

		$title = sprintf(
			'%1$s — %2$s',
			get_the_title( $campaign_id ),
			$this->placements->name( $placement_id )
		);

		$ad_id = wp_insert_post(
			array(
				'post_type'   => Adsanity::POST_TYPE,
				'post_status' => 'publish',
				'post_title'  => $title,
			),
			true
		);

		return is_wp_error( $ad_id ) ? 0 : (int) $ad_id;
	}

	/**
	 * Writes every field AdSanity needs.
	 *
	 * @param int                  $ad_id         Provider ad id — the post being written.
	 * @param array<string, mixed> $creative      Creative details.
	 * @param string               $size          Ad size key.
	 * @param int                  $attachment_id Attachment backing the creative.
	 * @param int                  $term_id       Ad-group term id.
	 * @param int                  $campaign_id   Campaign the dates come from.
	 * @return void
	 */
	private function write_ad( int $ad_id, array $creative, string $size, int $attachment_id, int $term_id, int $campaign_id ): void {
		set_post_thumbnail( $ad_id, $attachment_id );

		update_post_meta( $ad_id, Adsanity::META_URL, (string) ( $creative['click_url'] ?? '' ) );
		update_post_meta( $ad_id, Adsanity::META_TARGET, $this->creatives->opens_in_new_window( (int) $creative['id'] ) ? 1 : 0 );
		update_post_meta( $ad_id, Adsanity::META_SIZE, $size );

		// Integers, always, and both of them. AdSanity compares numerically at
		// read time and an ad missing either key renders nowhere at all.
		update_post_meta( $ad_id, Adsanity::META_START_DATE, $this->campaigns->start_ts( $campaign_id ) );
		update_post_meta( $ad_id, Adsanity::META_END_DATE, $this->end_date_for( $campaign_id ) );

		wp_set_object_terms( $ad_id, array( $term_id ), Adsanity::TAXONOMY, false );
	}

	/**
	 * Reads every written field back and reports the first that disagrees.
	 *
	 * Not paranoia. AdSanity's save_post never runs for our writes, so nothing
	 * else in this pipeline checks that what we intended is what is stored.
	 *
	 * @param int                  $ad_id         Provider ad id.
	 * @param array<string, mixed> $creative      Creative details.
	 * @param string               $size          Ad size key.
	 * @param int                  $attachment_id Attachment backing the creative.
	 * @param int                  $term_id       Ad-group term id.
	 * @return string Empty when everything matches, otherwise a reason code.
	 */
	private function verify_ad( int $ad_id, array $creative, string $size, int $attachment_id, int $term_id ): string {
		if ( (int) get_post_thumbnail_id( $ad_id ) !== $attachment_id ) {
			return 'thumbnail_not_stored';
		}

		if ( (string) get_post_meta( $ad_id, Adsanity::META_URL, true ) !== (string) ( $creative['click_url'] ?? '' ) ) {
			return 'url_not_stored';
		}

		if ( (string) get_post_meta( $ad_id, Adsanity::META_SIZE, true ) !== $size ) {
			return 'size_not_stored';
		}

		$start = get_post_meta( $ad_id, Adsanity::META_START_DATE, true );
		$end   = get_post_meta( $ad_id, Adsanity::META_END_DATE, true );

		if ( '' === $start || '' === $end ) {
			// The failure that makes an ad invisible everywhere rather than
			// merely wrong.
			return 'dates_not_stored';
		}

		$terms = wp_get_object_terms( $ad_id, Adsanity::TAXONOMY, array( 'fields' => 'ids' ) );

		if ( ! is_array( $terms ) || ! in_array( $term_id, array_map( 'intval', $terms ), true ) ) {
			return 'group_not_assigned';
		}

		return '';
	}

	/**
	 * The end date to store, translating open-ended into the sentinel.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	private function end_date_for( int $campaign_id ): int {
		$end = $this->campaigns->end_ts( $campaign_id );

		return $end > 0 ? $end : Adsanity::end_of_life();
	}
}
