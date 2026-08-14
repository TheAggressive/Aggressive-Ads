<?php
/**
 * Creative persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;

/**
 * Reads the creatives belonging to a campaign.
 *
 * A creative points at its campaign through meta rather than post_parent,
 * because post_parent carries WordPress semantics we do not want: hierarchical
 * permalinks, cascade deletion, and admin list nesting. See
 * docs/domain-model.md.
 */
final class Creative_Repository {

	public const META_CAMPAIGN_ID   = '_aggr_campaign_id';
	public const META_ORG_ID        = '_aggr_org_id';
	public const META_PLACEMENT_ID  = '_aggr_placement_id';
	public const META_SIZE          = '_aggr_size';
	public const META_KIND          = '_aggr_kind';
	public const META_WIDTH         = '_aggr_width';
	public const META_HEIGHT        = '_aggr_height';
	public const META_CLICK_URL     = '_aggr_click_url';
	public const META_ALT_TEXT      = '_aggr_alt_text';
	public const META_REVIEW_STATE  = '_aggr_review_state';
	public const META_TARGET_BLANK  = '_aggr_target_blank';
	public const META_ATTACHMENT_ID = '_aggr_attachment_id';
	public const META_PROVIDER_AD   = '_aggr_adsanity_ad_id';
	public const META_PRIVATE_PATH  = '_aggr_private_path';
	public const META_PRIVATE_TOKEN = '_aggr_private_token';
	public const META_SHA256        = '_aggr_sha256';
	public const META_MIME          = '_aggr_mime';
	public const META_FILESIZE      = '_aggr_filesize';
	public const META_ORIGINAL_NAME = '_aggr_original_name';
	public const META_REPLACES_ID   = '_aggr_replaces_creative_id';
	public const META_REPLACED_BY   = '_aggr_replaced_by_creative_id';
	public const META_CHANGE_STATE  = '_aggr_change_state';
	public const META_CHANGE_NOTES  = '_aggr_change_notes';
	public const META_REQUESTED_AT  = '_aggr_change_requested_at';
	public const META_DECIDED_AT    = '_aggr_change_decided_at';
	public const META_CHANGE_LOCK   = '_aggr_change_lock';

	public const CHANGE_PENDING  = 'pending';
	public const CHANGE_REJECTED = 'rejected';
	public const CHANGE_APPLIED  = 'applied';

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
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
				'meta_key'               => self::META_CAMPAIGN_ID, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Indexed lookup; a creative belongs to exactly one campaign.
				// Compared as a string because that is how meta values are
				// stored; passing an int makes WP_Query's own type juggling the
				// thing you are relying on.
				'meta_value'             => (string) $campaign_id, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- As above.
			)
		);

		$ids = array_map( 'intval', $ids );
		sort( $ids );

		return $ids;
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
	 * @return array{path: string, sha256: string, mime: string, alt_text: string, name: string, bytes: int}|null
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
			'bytes'    => (int) get_post_meta( $creative_id, self::META_FILESIZE, true ),
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
	 * Permanently removes one creative record.
	 *
	 * Private-file deletion is deliberately a workflow responsibility: the
	 * repository knows the record, while the workflow coordinates both stores.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function delete( int $creative_id ): bool {
		if ( Post_Types::CREATIVE !== get_post_type( $creative_id ) ) {
			return false;
		}

		return wp_delete_post( $creative_id, true ) instanceof \WP_Post;
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
	 * Creates a private revision linked to the currently published creative.
	 *
	 * The revision is excluded from for_campaign() until approval, so existing
	 * validation and publication always see exactly one active creative per
	 * placement.
	 *
	 * @param int                   $creative_id Current creative id.
	 * @param array<string, string> $fields      Validated creative fields.
	 * @return int Zero on failure.
	 */
	public function create_replacement( int $creative_id, array $fields ): int {
		$current = $this->details( $creative_id );

		if ( null === $current || ! $this->is_active( $creative_id ) ) {
			return 0;
		}

		$replacement_id = $this->create(
			$current['campaign_id'],
			$current['org_id'],
			$current['placement_id'],
			$fields
		);

		if ( 0 === $replacement_id ) {
			return 0;
		}

		update_post_meta( $replacement_id, self::META_REPLACES_ID, $creative_id );
		update_post_meta( $replacement_id, self::META_CHANGE_STATE, self::CHANGE_PENDING );
		update_post_meta( $replacement_id, self::META_REQUESTED_AT, time() );

		return $replacement_id;
	}

	/**
	 * Whether a creative is the active campaign revision.
	 *
	 * @param int $creative_id Creative id.
	 * @return bool
	 */
	public function is_active( int $creative_id ): bool {
		return null !== $this->details( $creative_id )
			&& 0 === $this->replacement_target_id( $creative_id )
			&& 0 === (int) get_post_meta( $creative_id, self::META_REPLACED_BY, true );
	}

	/**
	 * Current creative a revision proposes to replace.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return int
	 */
	public function replacement_target_id( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_REPLACES_ID, true );
	}

	/**
	 * Replacement-review state.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return string
	 */
	public function change_state( int $creative_id ): string {
		return (string) get_post_meta( $creative_id, self::META_CHANGE_STATE, true );
	}

	/**
	 * Advertiser-facing decision notes.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return string
	 */
	public function change_notes( int $creative_id ): string {
		return (string) get_post_meta( $creative_id, self::META_CHANGE_NOTES, true );
	}

	/**
	 * When a replacement was requested.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return int
	 */
	public function requested_at( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_REQUESTED_AT, true );
	}

	/**
	 * Pending replacement for one active creative, if any.
	 *
	 * @param int $creative_id Active creative id.
	 * @return int
	 */
	public function pending_replacement_id( int $creative_id ): int {
		foreach ( $this->ids_for_campaign( (int) ( $this->details( $creative_id )['campaign_id'] ?? 0 ) ) as $candidate_id ) {
			if ( $creative_id === $this->replacement_target_id( $candidate_id ) && self::CHANGE_PENDING === $this->change_state( $candidate_id ) ) {
				return $candidate_id;
			}
		}

		return 0;
	}

	/**
	 * Replacement revisions for a campaign, newest first.
	 *
	 * @param int                $campaign_id Campaign id.
	 * @param array<int, string> $states      Optional state allowlist.
	 * @return array<int, array{id: int, campaign_id: int, org_id: int, placement_id: int, size: string, kind: string, width: int, height: int, click_url: string, alt_text: string}>
	 */
	public function replacements_for_campaign( int $campaign_id, array $states = array() ): array {
		$rows = array();

		foreach ( array_reverse( $this->ids_for_campaign( $campaign_id ) ) as $creative_id ) {
			$state = $this->change_state( $creative_id );

			if ( $this->replacement_target_id( $creative_id ) <= 0 || ( array() !== $states && ! in_array( $state, $states, true ) ) ) {
				continue;
			}

			$details = $this->details( $creative_id );

			if ( null !== $details ) {
				$rows[] = $details;
			}
		}

		return $rows;
	}

	/**
	 * Stores a rejected replacement and the advertiser-facing reason.
	 *
	 * @param int    $creative_id Replacement id.
	 * @param string $notes       Review reason.
	 * @return bool
	 */
	public function reject_replacement( int $creative_id, string $notes ): bool {
		update_post_meta( $creative_id, self::META_CHANGE_STATE, self::CHANGE_REJECTED );
		update_post_meta( $creative_id, self::META_CHANGE_NOTES, $notes );
		update_post_meta( $creative_id, self::META_DECIDED_AT, time() );

		return self::CHANGE_REJECTED === $this->change_state( $creative_id )
			&& $notes === $this->change_notes( $creative_id );
	}

	/**
	 * Makes an approved replacement current and archives its predecessor.
	 *
	 * The provider write happens first. If these verified metadata writes fail,
	 * the caller restores the provider from the still-intact old creative.
	 *
	 * @param int $current_id     Current creative id.
	 * @param int $replacement_id Approved replacement id.
	 * @param int $provider_ad_id Existing provider ad id.
	 * @return bool
	 */
	public function activate_replacement( int $current_id, int $replacement_id, int $provider_ad_id ): bool {
		$old_review = (string) get_post_meta( $current_id, self::META_REVIEW_STATE, true );

		update_post_meta( $current_id, self::META_REPLACED_BY, $replacement_id );
		update_post_meta( $current_id, self::META_REVIEW_STATE, 'replaced' );
		delete_post_meta( $current_id, self::META_PROVIDER_AD );

		delete_post_meta( $replacement_id, self::META_REPLACES_ID );
		update_post_meta( $replacement_id, self::META_CHANGE_STATE, self::CHANGE_APPLIED );
		update_post_meta( $replacement_id, self::META_REVIEW_STATE, 'approved' );
		update_post_meta( $replacement_id, self::META_DECIDED_AT, time() );
		update_post_meta( $replacement_id, self::META_PROVIDER_AD, $provider_ad_id );

		$activated = $this->is_active( $replacement_id )
			&& ! $this->is_active( $current_id )
			&& $provider_ad_id === $this->provider_ad_id( $replacement_id );

		if ( $activated ) {
			return true;
		}

		delete_post_meta( $current_id, self::META_REPLACED_BY );
		update_post_meta( $current_id, self::META_REVIEW_STATE, $old_review );
		update_post_meta( $current_id, self::META_PROVIDER_AD, $provider_ad_id );
		update_post_meta( $replacement_id, self::META_REPLACES_ID, $current_id );
		update_post_meta( $replacement_id, self::META_CHANGE_STATE, self::CHANGE_PENDING );
		update_post_meta( $replacement_id, self::META_REVIEW_STATE, 'pending' );
		delete_post_meta( $replacement_id, self::META_PROVIDER_AD );

		return false;
	}

	/**
	 * Atomically claims a short-lived replacement operation lock.
	 *
	 * @param int $creative_id Current creative id.
	 * @return string Empty when another request owns the lock.
	 */
	public function claim_change_lock( int $creative_id ): string {
		$token = time() . '|' . wp_generate_uuid4();

		if ( add_post_meta( $creative_id, self::META_CHANGE_LOCK, $token, true ) ) {
			return $token;
		}

		$existing = (string) get_post_meta( $creative_id, self::META_CHANGE_LOCK, true );
		$created  = (int) strtok( $existing, '|' );

		if ( $created <= 0 || $created > time() - ( 5 * MINUTE_IN_SECONDS ) ) {
			return '';
		}

		delete_post_meta( $creative_id, self::META_CHANGE_LOCK, $existing );

		add_post_meta( $creative_id, self::META_CHANGE_LOCK, $token, true );

		return (string) get_post_meta( $creative_id, self::META_CHANGE_LOCK, true ) === $token ? $token : '';
	}

	/**
	 * Releases a replacement operation lock only for its owner.
	 *
	 * @param int    $creative_id Current creative id.
	 * @param string $token       Claim token.
	 * @return void
	 */
	public function release_change_lock( int $creative_id, string $token ): void {
		if ( '' !== $token ) {
			delete_post_meta( $creative_id, self::META_CHANGE_LOCK, $token );
		}
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
	 * @return bool Whether the exact attachment id was persisted.
	 */
	public function set_attachment_id( int $creative_id, int $attachment_id ): bool {
		update_post_meta( $creative_id, self::META_ATTACHMENT_ID, $attachment_id );

		return $attachment_id > 0 && $attachment_id === $this->attachment_id( $creative_id );
	}

	/**
	 * The Media Library attachment backing a creative, or 0.
	 *
	 * Zero until the creative is promoted at approval: uploads live in private
	 * storage and only become attachments once somebody has approved them.
	 * See docs/domain-model.md.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int
	 */
	public function attachment_id( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_ATTACHMENT_ID, true );
	}

	/**
	 * Absolute path of a promoted Media Library file, or empty.
	 *
	 * Used when a copy cannot resolve the private stage (retention) but the
	 * approved attachment is still on disk.
	 *
	 * @param int $creative_id Creative post id.
	 */
	public function attachment_file( int $creative_id ): string {
		$attachment_id = $this->attachment_id( $creative_id );

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$file = get_attached_file( $attachment_id );

		return is_string( $file ) ? $file : '';
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
	/**
	 * Clears the private-file pointers after the bytes have been deleted.
	 *
	 * Leaves checksum, MIME and dimensions in place: the creative record is
	 * still the reviewed artifact, and the Media Library attachment (when one
	 * exists) remains the public copy. Only the private stage is removable.
	 *
	 * @param int $creative_id Creative post id.
	 * @return void
	 */
	public function clear_private_file( int $creative_id ): void {
		if ( Post_Types::CREATIVE !== get_post_type( $creative_id ) ) {
			return;
		}

		delete_post_meta( $creative_id, self::META_PRIVATE_PATH );
		delete_post_meta( $creative_id, self::META_PRIVATE_TOKEN );
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

			if ( null !== $details && $this->is_active( $creative_id ) ) {
				$creatives[] = $details;
			}
		}

		return $creatives;
	}
}
