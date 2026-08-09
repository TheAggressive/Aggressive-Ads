<?php
/**
 * Creative persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Core\Post_Types;

/**
 * Reads the creatives belonging to a campaign.
 *
 * A creative points at its campaign through meta rather than post_parent,
 * because post_parent carries WordPress semantics we do not want: hierarchical
 * permalinks, cascade deletion, and admin list nesting. See
 * docs/domain-model.md.
 */
final class Creative_Repository {

	public const META_CAMPAIGN_ID  = '_laao_ads_campaign_id';
	public const META_ORG_ID       = '_laao_ads_org_id';
	public const META_PLACEMENT_ID = '_laao_ads_placement_id';
	public const META_SIZE         = '_laao_ads_size';
	public const META_KIND         = '_laao_ads_kind';
	public const META_WIDTH        = '_laao_ads_width';
	public const META_HEIGHT       = '_laao_ads_height';
	public const META_CLICK_URL    = '_laao_ads_click_url';
	public const META_ALT_TEXT     = '_laao_ads_alt_text';
	public const META_REVIEW_STATE = '_laao_ads_review_state';

	/**
	 * A campaign cannot carry more creatives than this.
	 *
	 * Bounded because the query runs during validation, and validation runs on
	 * the submission path where a caller controls how many rows exist. A real
	 * campaign has one creative per placement.
	 */
	public const MAX_PER_CAMPAIGN = 100;

	/**
	 * The creative ids belonging to a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int>
	 */
	public function ids_for_campaign( int $campaign_id ): array {
		if ( $campaign_id <= 0 ) {
			return array();
		}

		$ids = get_posts(
			array(
				'post_type'              => Post_Types::CREATIVE,
				'post_status'            => 'any',
				'numberposts'            => self::MAX_PER_CAMPAIGN,
				'fields'                 => 'ids',
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => self::META_CAMPAIGN_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup; a creative belongs to exactly one campaign.
				// Compared as a string because that is how meta values are
				// stored; passing an int makes WP_Query's own type juggling the
				// thing you are relying on.
				'meta_value'             => (string) $campaign_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Everything validation needs about one creative, in a single shape.
	 *
	 * Returned as a plain array rather than as several getters because the
	 * validator reads all of it at once, and one pass over the meta cache
	 * beats ten.
	 *
	 * @param int $creative_id Creative post id.
	 * @return array{id: int, campaign_id: int, org_id: int, placement_id: int, size: string, kind: string, width: int, height: int, click_url: string, alt_text: string}|null
	 */
	public function details( int $creative_id ): ?array {
		if ( Post_Types::CREATIVE !== get_post_type( $creative_id ) ) {
			return null;
		}

		return array(
			'id'           => $creative_id,
			'campaign_id'  => (int) get_post_meta( $creative_id, self::META_CAMPAIGN_ID, true ),
			'org_id'       => (int) get_post_meta( $creative_id, self::META_ORG_ID, true ),
			'placement_id' => (int) get_post_meta( $creative_id, self::META_PLACEMENT_ID, true ),
			'size'         => (string) get_post_meta( $creative_id, self::META_SIZE, true ),
			'kind'         => (string) get_post_meta( $creative_id, self::META_KIND, true ),
			'width'        => (int) get_post_meta( $creative_id, self::META_WIDTH, true ),
			'height'       => (int) get_post_meta( $creative_id, self::META_HEIGHT, true ),
			'click_url'    => (string) get_post_meta( $creative_id, self::META_CLICK_URL, true ),
			'alt_text'     => (string) get_post_meta( $creative_id, self::META_ALT_TEXT, true ),
		);
	}

	/**
	 * Every creative on a campaign, with details.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, array{id: int, campaign_id: int, org_id: int, placement_id: int, size: string, kind: string, width: int, height: int, click_url: string, alt_text: string}>
	 */
	public function for_campaign( int $campaign_id ): array {
		$creatives = array();

		foreach ( $this->ids_for_campaign( $campaign_id ) as $creative_id ) {
			$details = $this->details( $creative_id );

			if ( null !== $details ) {
				$creatives[] = $details;
			}
		}

		return $creatives;
	}
}
