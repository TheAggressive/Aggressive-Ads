<?php
/**
 * Campaign persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Statuses;
use LAAO_Advertiser_Portal\Core\Post_Types;

/**
 * Every read and write of a campaign.
 *
 * The status setter is deliberately blunt and deliberately not public API:
 * only Campaign_State_Machine::apply() should reach it, and a listener writes
 * an audit row when a campaign's status changes without it. See
 * docs/adr/0008-explicit-transition-table.md.
 */
final class Campaign_Repository {

	public const META_ORG_ID       = '_laao_ads_org_id';
	public const META_START_TS     = '_laao_ads_start_ts';
	public const META_END_TS       = '_laao_ads_end_ts';
	public const META_SUBMITTED_AT = '_laao_ads_submitted_at';
	public const META_REVIEWED_BY  = '_laao_ads_reviewed_by';
	public const META_REVIEWED_AT  = '_laao_ads_reviewed_at';
	public const META_REVIEW_NOTES = '_laao_ads_review_notes';
	public const META_REVISION     = '_laao_ads_revision';
	public const META_ADSANITY_ID  = '_laao_ads_adsanity_ad_id';
	public const META_PLACEMENT_ID = '_laao_ads_placement_id';

	/**
	 * Whether a post exists and is a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return bool
	 */
	public function exists( int $campaign_id ): bool {
		return Post_Types::CAMPAIGN === get_post_type( $campaign_id );
	}

	/**
	 * The campaign's current status, or an empty string when it is not a
	 * campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function status( int $campaign_id ): string {
		if ( ! $this->exists( $campaign_id ) ) {
			return '';
		}

		$status = get_post_status( $campaign_id );

		return is_string( $status ) ? $status : '';
	}

	/**
	 * The owning organization.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function org_id( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_ORG_ID, true );
	}

	/**
	 * Writes the campaign's status.
	 *
	 * Uses wp_update_post so that transition_post_status fires and anything
	 * listening — caches, our own divergence listener — sees the change.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $status      One of the registered campaign statuses.
	 * @return bool
	 */
	public function update_status( int $campaign_id, string $status ): bool {
		if ( ! Post_Statuses::is_valid( $status ) ) {
			return false;
		}

		$result = wp_update_post(
			array(
				'ID'          => $campaign_id,
				'post_status' => $status,
			),
			true
		);

		return ! is_wp_error( $result );
	}

	/**
	 * The reviewer who has claimed this campaign, or 0 when unclaimed.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function reviewed_by( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_REVIEWED_BY, true );
	}

	/**
	 * Claims or releases the campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $user_id     Reviewer id, or 0 to release.
	 * @return void
	 */
	public function set_reviewed_by( int $campaign_id, int $user_id ): void {
		update_post_meta( $campaign_id, self::META_REVIEWED_BY, $user_id );
		update_post_meta( $campaign_id, self::META_REVIEWED_AT, 0 === $user_id ? 0 : time() );
	}

	/**
	 * The advertiser-visible review feedback.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function review_notes( int $campaign_id ): string {
		return (string) get_post_meta( $campaign_id, self::META_REVIEW_NOTES, true );
	}

	/**
	 * Records advertiser-visible review feedback.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Feedback.
	 * @return void
	 */
	public function set_review_notes( int $campaign_id, string $notes ): void {
		update_post_meta( $campaign_id, self::META_REVIEW_NOTES, $notes );
	}

	/**
	 * How many times this campaign has been submitted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function revision( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_REVISION, true );
	}

	/**
	 * Bumps the revision counter and returns the new value.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function increment_revision( int $campaign_id ): int {
		$next = $this->revision( $campaign_id ) + 1;

		update_post_meta( $campaign_id, self::META_REVISION, $next );

		return $next;
	}

	/**
	 * Stamps the submission time.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $timestamp   UTC Unix seconds.
	 * @return void
	 */
	public function set_submitted_at( int $campaign_id, int $timestamp ): void {
		update_post_meta( $campaign_id, self::META_SUBMITTED_AT, $timestamp );
	}

	/**
	 * When the campaign was last submitted.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function submitted_at( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_SUBMITTED_AT, true );
	}

	/**
	 * The campaign's start time, in UTC Unix seconds.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function start_ts( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_START_TS, true );
	}

	/**
	 * The campaign's end time, in UTC Unix seconds. Zero means open-ended.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function end_ts( int $campaign_id ): int {
		return (int) get_post_meta( $campaign_id, self::META_END_TS, true );
	}

	/**
	 * How many campaigns one page of a listing returns.
	 *
	 * Paged server-side, always. An advertiser with four campaigns and one with
	 * four hundred hit the same query, and the second is the one that decides
	 * whether this page loads.
	 */
	public const PAGE_SIZE = 20;

	/**
	 * One page of an organization's campaigns, newest first.
	 *
	 * Scoped by organization in the query rather than filtered afterwards. The
	 * array-filtering version works until somebody adds pagination, at which
	 * point page two is short for reasons nobody can explain, and the fix that
	 * suggests itself is to remove the filter.
	 *
	 * @param int $org_id Owning organization.
	 * @param int $page   1-based page number.
	 * @return array{ids: array<int, int>, total: int, pages: int}
	 */
	public function for_org( int $org_id, int $page = 1 ): array {
		if ( $org_id <= 0 ) {
			return array(
				'ids'   => array(),
				'total' => 0,
				'pages' => 0,
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'              => Post_Types::CAMPAIGN,
				'post_status'            => Post_Statuses::all(),
				'posts_per_page'         => self::PAGE_SIZE,
				'paged'                  => max( 1, $page ),
				'fields'                 => 'ids',
				'orderby'                => 'date',
				'order'                  => 'DESC',
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Indexed lookup on a single meta key; this is the org scope, and it belongs in SQL.
					array(
						'key'   => self::META_ORG_ID,
						'value' => (string) $org_id,
					),
				),
			)
		);

		// `fields => ids` yields integers, but WP_Query::$posts is typed as the
		// union either way. Narrowing here rather than casting blindly keeps
		// the method honest if somebody ever drops that argument.
		$ids = array();

		foreach ( $query->posts as $post ) {
			$ids[] = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		}

		return array(
			'ids'   => $ids,
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * The campaign's title.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public function title( int $campaign_id ): string {
		$title = get_the_title( $campaign_id );

		return is_string( $title ) ? $title : '';
	}

	/**
	 * Records a provider ad id against the campaign, without duplicating it.
	 *
	 * Repeated meta, one row per ad, so a retry can see exactly what already
	 * exists. Adding the same id twice would make "how many ads does this
	 * campaign have?" wrong in the direction that hides a problem.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @param int $ad_id       Provider ad id.
	 * @return void
	 */
	public function add_provider_ad_id( int $campaign_id, int $ad_id ): void {
		if ( $ad_id <= 0 || in_array( $ad_id, $this->provider_ad_ids( $campaign_id ), true ) ) {
			return;
		}

		add_post_meta( $campaign_id, self::META_ADSANITY_ID, $ad_id );
	}

	/**
	 * The placements this campaign has selected.
	 *
	 * Repeated meta rather than a serialized array, so "which campaigns use
	 * placement 62?" stays an indexed lookup. A serialized array would need a
	 * LIKE '%i:62;%', which is also wrong, because it matches 162 and 620.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function placement_ids( int $campaign_id ): array {
		$ids = get_post_meta( $campaign_id, self::META_PLACEMENT_ID, false );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
	}

	/**
	 * The provider ad ids published for this campaign.
	 *
	 * Repeated meta rather than a serialized array, so a retry can reconcile
	 * what already succeeded. See docs/campaign-workflow.md.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function provider_ad_ids( int $campaign_id ): array {
		$ids = get_post_meta( $campaign_id, self::META_ADSANITY_ID, false );

		if ( ! is_array( $ids ) ) {
			return array();
		}

		return array_values( array_filter( array_map( 'intval', $ids ) ) );
	}
}
