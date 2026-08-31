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

	/**
	 * Set on the attachment itself, marking it as delivered advertising.
	 *
	 * Lives on the attachment rather than being derived from META_ATTACHMENT_ID
	 * because the Media Library filters query attachments directly and cannot
	 * afford a lookup per row.
	 */
	public const META_IS_CREATIVE   = '_aggr_is_creative';
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
	/**
	 * A creative's title.
	 *
	 * Here rather than at the call site because reading a post is data access,
	 * and `check-boundaries.php` is right to refuse it anywhere else — it
	 * caught this one.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public function title( int $creative_id ): string {
		return $creative_id > 0 ? (string) get_the_title( $creative_id ) : '';
	}

	/**
	 * Creative ids after a cursor, in primary-key order.
	 *
	 * The migration walks the id space rather than querying by campaign, so it
	 * is resumable from a single integer and cannot revisit or skip a row when
	 * campaigns are created or deleted mid-run.
	 *
	 * @param int $cursor Last id already visited.
	 * @param int $limit  Batch size.
	 * @return array<int, int>
	 */
	public function creative_ids_after( int $cursor, int $limit ): array {
		global $wpdb;

		$limit  = max( 1, min( 500, $limit ) );
		$cursor = max( 0, $cursor );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded primary-key migration scan owned by the persistence layer.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d",
				Post_Types::CREATIVE,
				$cursor,
				$limit
			)
		);

		return array_map( 'intval', is_array( $ids ) ? $ids : array() );
	}





	/**
	 * Whether a publisher has approved this revision.
	 *
	 * `approved` and `replaced` both mean a publisher said yes to these bytes;
	 * `replaced` is an approved revision that a later one superseded, and a
	 * superseded revision is caught by `is_active()` rather than here.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return bool
	 */
	public function is_approved( int $creative_id ): bool {
		return in_array(
			(string) get_post_meta( $creative_id, self::META_REVIEW_STATE, true ),
			array( 'approved', 'replaced' ),
			true
		);
	}

	/**
	 * Whether a creative is the current one rather than a superseded revision.
	 *
	 * @param int $creative_id Creative post id.
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
	 * Advertiser-facing decision notes.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return string
	 */
	public function change_notes( int $creative_id ): string {
		return (string) get_post_meta( $creative_id, self::META_CHANGE_NOTES, true );
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
	 * Marks an attachment as advertising creative.
	 *
	 * Hidden from the Media Library by Admin\Media_Library, so a site running
	 * hundreds of campaigns still has a library of the site's own media. The
	 * file stays a normal attachment and is still served as a static file:
	 * delivery is the highest-volume path in the plugin, and routing it through
	 * PHP to keep the library tidy would be the wrong trade.
	 *
	 * @param int $attachment_id Attachment id.
	 * @param int $creative_id   Creative the attachment came from.
	 * @return void
	 */
	public function mark_attachment_as_creative( int $attachment_id, int $creative_id ): void {
		update_post_meta( $attachment_id, self::META_IS_CREATIVE, $creative_id );
	}

	/**
	 * Creatives that have a public attachment and still hold a private file.
	 *
	 * The contradiction this finds is the point: once promoted, the attachment
	 * is what delivery serves, so a surviving private original is a duplicate
	 * of already-public bytes sitting in the directory the deny rule exists to
	 * protect.
	 *
	 * @param int $limit Maximum ids to return.
	 * @return array<int, int>
	 */
	public function ids_promoted_with_private_file( int $limit ): array {
		$ids = get_posts(
			array(
				'post_type'      => Post_Types::CREATIVE,
				'post_status'    => 'any',
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'no_found_rows'  => true,
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Two EXISTS clauses on indexed meta keys; the pair is the condition, and there is no other way to express it.
				'meta_query'     => array(
					'relation' => 'AND',
					array(
						'key'     => self::META_ATTACHMENT_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => self::META_PRIVATE_PATH,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Marks the attachments of creatives promoted before the marker existed.
	 *
	 * Walked in batches rather than with posts_per_page => -1. A site that has
	 * been running campaigns for a year is exactly the site that needs this,
	 * and it is also the one where an unbounded query is most likely to exhaust
	 * memory partway and leave the job half done.
	 *
	 * Idempotent: marking an attachment twice writes the same value.
	 *
	 * @param int $batch How many creatives to read per page.
	 * @return int Attachments marked.
	 */
	public function backfill_creative_attachment_marks( int $batch = 100 ): int {
		$marked = 0;
		$page   = 1;

		do {
			$creative_ids = get_posts(
				array(
					'post_type'        => Post_Types::CREATIVE,
					'post_status'      => 'any',
					'posts_per_page'   => $batch,
					'paged'            => $page,
					'fields'           => 'ids',
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
					'no_found_rows'    => true,
				)
			);

			foreach ( (array) $creative_ids as $creative_id ) {
				$attachment_id = $this->attachment_id( (int) $creative_id );

				if ( $attachment_id > 0 ) {
					$this->mark_attachment_as_creative( $attachment_id, (int) $creative_id );
					++$marked;
				}
			}

			++$page;
		} while ( array() !== (array) $creative_ids );

		return $marked;
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
	 * Public URL of a promoted creative's Media Library file, or empty.
	 *
	 * Approval deletes the private original, so the authenticated file route
	 * answers 404 for anything approved — correctly, since there is nothing
	 * left to stream. Anything showing a creative has to follow it here once it
	 * has an attachment.
	 *
	 * @param int $creative_id Creative post id.
	 * @return string
	 */
	public function attachment_url( int $creative_id ): string {
		$attachment_id = $this->attachment_id( $creative_id );

		if ( $attachment_id <= 0 ) {
			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );

		return is_string( $url ) ? $url : '';
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
	 * Repoints one creative's click destination.
	 *
	 * Deliberately narrower than update_details(): approving a destination
	 * change must not be able to alter the size or kind a reviewer accepted,
	 * and the way to guarantee that is to have no code path that could.
	 * Callers validate the URL — Domain\Campaign_Rules::is_valid_click_url()
	 * is the authority — because this layer does not judge values.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $click_url   Validated destination.
	 * @return void
	 */
	public function set_click_url( int $creative_id, string $click_url ): void {
		update_post_meta( $creative_id, self::META_CLICK_URL, $click_url );
	}

	/**
	 * Writes both text fields on an editable creative.
	 *
	 * Callers ask `Revision_Policy::is_frozen()` first. This layer does not
	 * judge whether the write is allowed, in keeping with the rest of the
	 * repository — but it is worth knowing that calling it on a promoted
	 * creative is the mistake the policy exists to prevent.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $click_url   Validated destination.
	 * @param string $alt_text    Alternative text.
	 * @return void
	 */
	public function set_text( int $creative_id, string $click_url, string $alt_text ): void {
		update_post_meta( $creative_id, self::META_CLICK_URL, $click_url );
		update_post_meta( $creative_id, self::META_ALT_TEXT, $alt_text );
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
	 * The review state a turned-down creative carries.
	 *
	 * Distinct from `CHANGE_REJECTED`, which is a *replacement's* change state.
	 * A creative added to a running campaign is not replacing anything, so its
	 * decision belongs on the review state rather than on a field that means
	 * "what happened to the swap".
	 */
	public const REVIEW_REJECTED = 'rejected';

	/**
	 * Records a decision to turn down a creative, with the reason.
	 *
	 * Verified rather than assumed: `update_post_meta` returns false both when
	 * a write fails and when the value was already there, so the caller cannot
	 * tell those apart from its return. Reading the values back can.
	 *
	 * @param int    $creative_id Creative post id.
	 * @param string $notes       Explanation the advertiser will read.
	 * @return bool
	 */
	public function reject_creative( int $creative_id, string $notes ): bool {
		update_post_meta( $creative_id, self::META_REVIEW_STATE, self::REVIEW_REJECTED );
		update_post_meta( $creative_id, self::META_CHANGE_NOTES, $notes );
		update_post_meta( $creative_id, self::META_DECIDED_AT, time() );

		return $this->is_rejected( $creative_id )
			&& (string) get_post_meta( $creative_id, self::META_CHANGE_NOTES, true ) === $notes;
	}

	/**
	 * Whether a creative has been turned down.
	 *
	 * @param int $creative_id Creative post id.
	 * @return bool
	 */
	public function is_rejected( int $creative_id ): bool {
		return self::REVIEW_REJECTED === (string) get_post_meta( $creative_id, self::META_REVIEW_STATE, true );
	}

	/**
	 * The campaign a creative belongs to, or 0.
	 *
	 * A single meta read. `details()` carries the same value, but it assembles
	 * the whole record — a caller that only needs to know which campaign owns a
	 * creative should not pay for its dimensions and checksum.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int
	 */
	public function campaign_id( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, self::META_CAMPAIGN_ID, true );
	}

	/**
	 * Creatives on a campaign that have not been published yet.
	 *
	 * **Having an attachment is what "approved" actually means here.** A
	 * creative is promoted into the Media Library by `Publisher::publish_campaign()`
	 * when the campaign transitions into a published state, and that promotion
	 * does not touch `_aggr_review_state` — only the replacement path maintains
	 * it. So a creative can read `pending` and be serving, which makes the meta
	 * useless as a signal and `has_attachment()` the honest one.
	 *
	 * A creative added to an *already* published campaign misses that
	 * transition entirely and stays unpublished with nothing surfacing it,
	 * which is what this exists to find.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return array<int, int> Creative ids awaiting publication.
	 */
	public function unpublished_for_campaign( int $campaign_id ): array {
		$waiting = array();

		foreach ( $this->ids_for_campaign( $campaign_id ) as $creative_id ) {
			/*
			 * A turned-down creative is not waiting for anything. Without this
			 * it would sit on the queue for ever, because rejecting one cannot
			 * give it the attachment whose absence put it there.
			 */
			if ( $this->is_rejected( $creative_id ) ) {
				continue;
			}

			if ( $this->is_active( $creative_id ) && ! $this->has_attachment( $creative_id ) ) {
				$waiting[] = (int) $creative_id;
			}
		}

		return $waiting;
	}

	/**
	 * How many of a campaign's creatives are awaiting publication.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return int
	 */
	public function unpublished_count_for_campaign( int $campaign_id ): int {
		return count( $this->unpublished_for_campaign( $campaign_id ) );
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
