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

	public const META_CAMPAIGN_ID   = '_laao_ads_campaign_id';
	public const META_ORG_ID        = '_laao_ads_org_id';
	public const META_PLACEMENT_ID  = '_laao_ads_placement_id';
	public const META_SIZE          = '_laao_ads_size';
	public const META_KIND          = '_laao_ads_kind';
	public const META_WIDTH         = '_laao_ads_width';
	public const META_HEIGHT        = '_laao_ads_height';
	public const META_CLICK_URL     = '_laao_ads_click_url';
	public const META_ALT_TEXT      = '_laao_ads_alt_text';
	public const META_REVIEW_STATE  = '_laao_ads_review_state';
	public const META_TARGET_BLANK  = '_laao_ads_target_blank';
	public const META_ATTACHMENT_ID = '_laao_ads_attachment_id';
	public const META_PROVIDER_AD   = '_laao_ads_adsanity_ad_id';
	public const META_PRIVATE_PATH  = '_laao_ads_private_path';
	public const META_PRIVATE_TOKEN = '_laao_ads_private_token';
	public const META_SHA256        = '_laao_ads_sha256';
	public const META_MIME          = '_laao_ads_mime';
	public const META_FILESIZE      = '_laao_ads_filesize';
	public const META_ORIGINAL_NAME = '_laao_ads_original_name';

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
	 * Everything the promoter needs about a creative's stored file.
	 *
	 * @param int $creative_id Creative post id.
	 * @return array{path: string, sha256: string, mime: string, alt_text: string, name: string}|null
	 */
	public function storage_details( int $creative_id ): ?array {
		if ( Post_Types::CREATIVE !== get_post_type( $creative_id ) ) {
			return null;
		}

		return array(
			'path'     => (string) get_post_meta( $creative_id, self::META_PRIVATE_PATH, true ),
			'sha256'   => (string) get_post_meta( $creative_id, self::META_SHA256, true ),
			'mime'     => (string) get_post_meta( $creative_id, self::META_MIME, true ),
			'alt_text' => (string) get_post_meta( $creative_id, self::META_ALT_TEXT, true ),
			'name'     => (string) get_post_meta( $creative_id, self::META_ORIGINAL_NAME, true ),
		);
	}

	/**
	 * Records where an accepted upload was stored, and what it is.
	 *
	 * Dimensions and MIME come from the server's own inspection of the bytes,
	 * never from what the browser claimed.
	 *
	 * @param int                                                                                                                 $creative_id Creative post id.
	 * @param array{path: string, token: string, sha256: string, bytes: int, mime: string, width: int, height: int, name: string} $upload      Accepted upload.
	 * @return void
	 */
	public function record_upload( int $creative_id, array $upload ): void {
		update_post_meta( $creative_id, self::META_PRIVATE_PATH, $upload['path'] );
		update_post_meta( $creative_id, self::META_PRIVATE_TOKEN, $upload['token'] );
		update_post_meta( $creative_id, self::META_SHA256, $upload['sha256'] );
		update_post_meta( $creative_id, self::META_FILESIZE, $upload['bytes'] );
		update_post_meta( $creative_id, self::META_MIME, $upload['mime'] );
		update_post_meta( $creative_id, self::META_WIDTH, $upload['width'] );
		update_post_meta( $creative_id, self::META_HEIGHT, $upload['height'] );
		update_post_meta( $creative_id, self::META_ORIGINAL_NAME, $upload['name'] );
	}

	/**
	 * Creates a creative belonging to a campaign.
	 *
	 * The organization is passed in from the campaign rather than accepted
	 * from anywhere near a request. `org_id` is never read from client input —
	 * that single rule collapses most of the object-reference attack surface.
	 *
	 * @param int                   $campaign_id  Owning campaign.
	 * @param int                   $org_id       Owning organization, derived server-side.
	 * @param int                   $placement_id Placement the creative fills.
	 * @param array<string, string> $fields       kind, click_url, alt_text and size.
	 * @return int Zero on failure.
	 */
	public function create( int $campaign_id, int $org_id, int $placement_id, array $fields ): int {
		$creative_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => 'publish',
				'post_title'  => sprintf( 'Creative for campaign %d', $campaign_id ),
			),
			true
		);

		if ( is_wp_error( $creative_id ) ) {
			return 0;
		}

		$creative_id = (int) $creative_id;

		update_post_meta( $creative_id, self::META_CAMPAIGN_ID, $campaign_id );
		update_post_meta( $creative_id, self::META_ORG_ID, $org_id );
		update_post_meta( $creative_id, self::META_PLACEMENT_ID, $placement_id );
		update_post_meta( $creative_id, self::META_KIND, $fields['kind'] ?? '' );
		update_post_meta( $creative_id, self::META_CLICK_URL, $fields['click_url'] ?? '' );
		update_post_meta( $creative_id, self::META_ALT_TEXT, $fields['alt_text'] ?? '' );
		update_post_meta( $creative_id, self::META_SIZE, $fields['size'] ?? '' );
		update_post_meta( $creative_id, self::META_REVIEW_STATE, 'pending' );

		return $creative_id;
	}

	/**
	 * Whether a creative already points at a real attachment.
	 *
	 * Checks the post actually exists rather than trusting the recorded id: an
	 * attachment deleted from the library leaves the meta behind, and a
	 * promoted-but-missing creative must be promoted again rather than
	 * published with nothing behind it.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function has_attachment( int $creative_id ): bool {
		$attachment_id = $this->attachment_id( $creative_id );

		return $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id );
	}

	/**
	 * Sets an attachment's alternative text.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $alt_text      Alternative text.
	 * @return void
	 */
	public function set_attachment_alt_text( int $attachment_id, string $alt_text ): void {
		update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
	}

	/**
	 * Records the Media Library attachment a creative was promoted into.
	 *
	 * @param int $creative_id   Creative post id.
	 * @param int $attachment_id Attachment id.
	 * @return void
	 */
	public function set_attachment_id( int $creative_id, int $attachment_id ): void {
		update_post_meta( $creative_id, self::META_ATTACHMENT_ID, $attachment_id );
	}

	/**
	 * The Media Library attachment backing a creative, or 0.
	 *
	 * Zero until the creative is promoted at approval: uploads live in private
	 * storage and only become attachments once somebody has approved them.
	 * See docs/adr/0010-two-stage-creative-storage.md.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int
	 */
	public function attachment_id( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_ATTACHMENT_ID, true );
	}

	/**
	 * Whether the creative's destination should open in a new window.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function opens_in_new_window( int $creative_id ): bool {
		return 1 === (int) get_post_meta( $creative_id, self::META_TARGET_BLANK, true );
	}

	/**
	 * The provider ad id published for this creative, or 0.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int
	 */
	public function provider_ad_id( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_PROVIDER_AD, true );
	}

	/**
	 * Records the provider ad id for a creative.
	 *
	 * Written the moment each ad succeeds rather than once at the end, so a
	 * failure partway through leaves the successes recorded and a retry
	 * reconciles them instead of creating duplicates.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $ad_id       Provider ad id.
	 * @return void
	 */
	public function set_provider_ad_id( int $creative_id, int $ad_id ): void {
		update_post_meta( $creative_id, self::META_PROVIDER_AD, $ad_id );
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
