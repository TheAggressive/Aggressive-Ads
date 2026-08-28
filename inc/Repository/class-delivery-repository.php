<?php
/**
 * Indexed native-delivery reads for token validation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Core\Post_Types;

/**
 * Re-reads one creative by primary id for beacon and click-hop validation.
 *
 * Fill selection reads assignments instead; this class remains the fast path
 * for verifying a signed token still names a live campaign/placement tuple.
 */
final class Delivery_Repository {

	/**
	 * One creative, only when its exact campaign/placement tuple is live.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $placement_id Placement post id.
	 * @param int $campaign_id Expected campaign id.
	 * @return array{creative_id: int, campaign_id: int, placement_id: int, attachment_id: int, click_url: string, alt_text: string, width: int, height: int}|null
	 */
	public function candidate( int $creative_id, int $placement_id, int $campaign_id = 0 ): ?array {
		if ( $creative_id <= 0 || $placement_id <= 0 || $campaign_id < 0 ) {
			return null;
		}

		global $wpdb;

		$posts = $wpdb->posts;
		$meta  = $wpdb->postmeta;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Primary-id delivery lookup; table names are core-owned and every value is prepared.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT creative.ID AS creative_id,
					campaign.ID AS campaign_id,
					CAST(creative_placement.meta_value AS UNSIGNED) AS placement_id,
					CAST(COALESCE(attachment.meta_value, '0') AS UNSIGNED) AS attachment_id,
					COALESCE(destination.meta_value, '') AS click_url,
					COALESCE(alt_text.meta_value, '') AS alt_text,
					CAST(COALESCE(width_meta.meta_value, '0') AS UNSIGNED) AS width,
					CAST(COALESCE(height_meta.meta_value, '0') AS UNSIGNED) AS height
				FROM {$posts} creative
				INNER JOIN {$meta} creative_campaign
					ON creative_campaign.post_id = creative.ID
					AND creative_campaign.meta_key = %s
				INNER JOIN {$posts} campaign
					ON campaign.ID = CAST(creative_campaign.meta_value AS UNSIGNED)
					AND campaign.post_type = %s
					AND campaign.post_status = %s
				INNER JOIN {$meta} creative_placement
					ON creative_placement.post_id = creative.ID
					AND creative_placement.meta_key = %s
					AND creative_placement.meta_value = %s
				INNER JOIN {$meta} campaign_placement
					ON campaign_placement.post_id = campaign.ID
					AND campaign_placement.meta_key = %s
					AND campaign_placement.meta_value = %s
				LEFT JOIN {$meta} attachment
					ON attachment.post_id = creative.ID AND attachment.meta_key = %s
				LEFT JOIN {$meta} destination
					ON destination.post_id = creative.ID AND destination.meta_key = %s
				LEFT JOIN {$meta} alt_text
					ON alt_text.post_id = creative.ID AND alt_text.meta_key = %s
				LEFT JOIN {$meta} width_meta
					ON width_meta.post_id = creative.ID AND width_meta.meta_key = %s
				LEFT JOIN {$meta} height_meta
					ON height_meta.post_id = creative.ID AND height_meta.meta_key = %s
				LEFT JOIN {$meta} replaces
					ON replaces.post_id = creative.ID
					AND replaces.meta_key = %s
					AND CAST(replaces.meta_value AS UNSIGNED) > 0
				LEFT JOIN {$meta} replaced
					ON replaced.post_id = creative.ID
					AND replaced.meta_key = %s
					AND CAST(replaced.meta_value AS UNSIGNED) > 0
				WHERE creative.ID = %d
					AND creative.post_type = %s
					AND creative.post_status NOT IN ('trash', 'auto-draft')
					AND (%d = 0 OR campaign.ID = %d)
					AND replaces.meta_id IS NULL
					AND replaced.meta_id IS NULL
				LIMIT 1",
				Creative_Repository::META_CAMPAIGN_ID,
				Post_Types::CAMPAIGN,
				Post_Statuses::LIVE,
				Creative_Repository::META_PLACEMENT_ID,
				(string) $placement_id,
				Campaign_Repository::META_PLACEMENT_ID,
				(string) $placement_id,
				Creative_Repository::META_ATTACHMENT_ID,
				Creative_Repository::META_CLICK_URL,
				Creative_Repository::META_ALT_TEXT,
				Creative_Repository::META_WIDTH,
				Creative_Repository::META_HEIGHT,
				Creative_Repository::META_REPLACES_ID,
				Creative_Repository::META_REPLACED_BY,
				$creative_id,
				Post_Types::CREATIVE,
				$campaign_id,
				$campaign_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $row ) ) {
			return null;
		}

		return array(
			'creative_id'   => (int) $row['creative_id'],
			'campaign_id'   => (int) $row['campaign_id'],
			'placement_id'  => (int) $row['placement_id'],
			'attachment_id' => (int) $row['attachment_id'],
			'click_url'     => (string) $row['click_url'],
			'alt_text'      => (string) $row['alt_text'],
			'width'         => (int) $row['width'],
			'height'        => (int) $row['height'],
		);
	}
}
