<?php
/**
 * The Media Library copy of a creative's artwork.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;

/**
 * Everything about the *attachment* behind a creative, rather than the creative.
 *
 * Split out of `Creative_Repository` when that file reached the 800-line warning
 * for the third time. The seam was named in `open-work.md` well before it was
 * cut, and it is the honest one: these methods are about the Media Library —
 * promoting into it, marking what came from a campaign, reading a public URL or
 * an on-disk path back out — while everything left behind is about the creative
 * record and its private staging.
 *
 * **The meta keys stayed on `Creative_Repository`.** They name the shape of the
 * record, and a key is read by more than its writer: `META_ATTACHMENT_ID` is
 * queried by the assignment repository, `META_IS_CREATIVE` by the Media Library
 * screen. That is the same reasoning that kept `change_notes()` behind in the
 * previous split — a shared reader belongs with the record, not with one of its
 * readers. Moving them would also have rewritten thirty test files to say a
 * different class name for an unchanged string.
 *
 * The runtime dependency runs one way and the other way from last time:
 * `Creative_Repository` holds one of these, because "is this creative approved"
 * is answered by "does it have an attachment" and `unpublished_for_campaign()`
 * has to ask. Nothing here holds a `Creative_Repository` — it names two of that
 * class's constants, which is a compile-time reference and not an object graph,
 * so there is no cycle to construct.
 *
 * Stateless and dependency-free on purpose: that is what lets the class above
 * take one without any of its five construction sites needing a container.
 */
final class Creative_Attachment_Repository {

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
		update_post_meta( $attachment_id, Creative_Repository::META_IS_CREATIVE, $creative_id );
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
						'key'     => Creative_Repository::META_ATTACHMENT_ID,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => Creative_Repository::META_PRIVATE_PATH,
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
		update_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, $attachment_id );

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
		return (int) get_post_meta( $creative_id, Creative_Repository::META_ATTACHMENT_ID, true );
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
}
