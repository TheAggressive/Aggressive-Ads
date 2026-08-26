<?php
/**
 * The creative revision chain.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use WP_Post;

/**
 * Who superseded whom, and what a revision is allowed to differ by.
 *
 * Split out of `Creative_Repository` when it crossed the thousand-line limit.
 * The split is by responsibility rather than by size: the rest of that class
 * answers "what is this creative", while this answers "how does this creative
 * relate to the ones before it" — a question with its own invariant, its own
 * direction-of-travel trap, and its own security property.
 *
 * The trap is worth reading before touching anything here. `META_REPLACES_ID`
 * is the obvious backward link and is deleted the moment a replacement is
 * approved, because `is_active()` reads its presence as "still pending". Only
 * `META_REPLACED_BY` survives going live, so the chain is durable in one
 * direction only and every walk has to go with the grain.
 */
final class Creative_Revision_Repository {

	/**
	 * The oldest creative in a replacement chain.
	 *
	 * One logical piece of artwork can already be several Creative posts, linked
	 * by `_aggr_replaces_creative_id` when a replacement was approved. That
	 * chain is the asset the P2 model names, so its root is the stable identity
	 * every revision of the same artwork shares.
	 *
	 * Bounded rather than trusting the data: a chain that loops — which nothing
	 * should create and a corrupted meta pair could — would otherwise hang the
	 * migration on one row.
	 *
	 * @param int $creative_id Any creative in the chain.
	 * @return int Root creative id.
	 */
	public function chain_root( int $creative_id ): int {
		$seen = array();

		while ( $creative_id > 0 && ! isset( $seen[ $creative_id ] ) ) {
			$seen[ $creative_id ] = true;

			$previous = $this->predecessor_of( $creative_id );

			if ( $previous <= 0 || isset( $seen[ $previous ] ) ) {
				break;
			}

			$creative_id = $previous;
		}

		return $creative_id;
	}

	/**
	 * The creative this one superseded, if any.
	 *
	 * **Read from the forward link, not the backward one.** `META_REPLACES_ID`
	 * looks like the obvious answer and is wrong for every chain that matters:
	 * `activate_replacement()` deletes it the moment a replacement is approved,
	 * because `is_active()` treats its presence as "still pending". So a
	 * backward walk finds nothing on any chain that actually went live, and
	 * every revision looks like its own root.
	 *
	 * `META_REPLACED_BY` survives approval and is therefore the durable chain.
	 * Reversing it costs a meta lookup, which is the price of asking a question
	 * the data model answers in one direction only.
	 *
	 * @param int $creative_id Creative post id.
	 * @return int Predecessor id, or 0.
	 */
	public function predecessor_of( int $creative_id ): int {
		if ( $creative_id <= 0 ) {
			return 0;
		}

		// A pending replacement still carries the backward link, and it is
		// cheaper and exact, so prefer it when present.
		$staged = (int) get_post_meta( $creative_id, Creative_Repository::META_REPLACES_ID, true );

		if ( $staged > 0 ) {
			return $staged;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Reverse chain lookup owned by the persistence layer.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %d LIMIT 1",
				Creative_Repository::META_REPLACED_BY,
				$creative_id
			)
		);
	}

	/**
	 * Creates an approved revision that differs only in its text.
	 *
	 * The new post carries the predecessor's bytes by reference — the same
	 * attachment and the same checksum — because a text change changes no byte.
	 * That is what makes the `text_only` classification *derived* rather than
	 * asserted: the two checksums are equal because the artwork is the same
	 * file, not because a caller said so. Nothing a caller sends can influence
	 * it, which is the property `docs/platform-p2-creative-model.md` requires.
	 *
	 * The predecessor is preserved and linked in both directions, so what was
	 * previously approved and served stays on the record.
	 *
	 * @param int    $creative_id Superseded creative id.
	 * @param string $click_url   New destination.
	 * @param string $alt_text    New alternative text.
	 * @return int New creative id, or 0 on failure.
	 */
	public function create_text_revision( int $creative_id, string $click_url, string $alt_text ): int {
		$revision_id = $this->stage_text_revision( $creative_id, $click_url, $alt_text );

		if ( $revision_id <= 0 ) {
			return 0;
		}

		/*
		 * Linked the way an *approved* replacement is linked, because that is
		 * what this is: staff approved the campaign change that proposed this
		 * text, so the revision arrives live rather than pending.
		 *
		 * That means the forward link only. Leaving `META_REPLACES_ID` on the
		 * new revision would make `is_active()` treat it as an unapproved
		 * replacement, and the campaign would show no current creative at all.
		 */
		update_post_meta( $creative_id, Creative_Repository::META_REPLACED_BY, $revision_id );
		update_post_meta( $creative_id, Creative_Repository::META_REVIEW_STATE, 'replaced' );
		update_post_meta( $revision_id, Creative_Repository::META_REVIEW_STATE, 'approved' );
		update_post_meta( $revision_id, Creative_Repository::META_CHANGE_STATE, Creative_Repository::CHANGE_APPLIED );

		return $revision_id;
	}

	/**
	 * Stages a text revision for review, leaving the current ad serving.
	 *
	 * The pending shape, and the difference from the method above is the whole
	 * point: `META_REPLACES_ID` stays on the new revision, which is what
	 * `is_active()` reads as "not live yet". The approved creative keeps
	 * serving until a reviewer decides, so an advertiser correcting a typo
	 * cannot take their own placement off the site.
	 *
	 * Everything after that is the existing replacement flow unchanged —
	 * `Creative_Change_Manager::approve()` and `reject()` already handle a
	 * pending revision, and `Creative_Promoter::promote()` is a no-op for one
	 * that already carries an attachment, which a text revision does.
	 *
	 * @param int    $creative_id Superseded creative id.
	 * @param string $click_url   New destination.
	 * @param string $alt_text    New alternative text.
	 * @return int New creative id, or 0 on failure.
	 */
	public function create_pending_text_revision( int $creative_id, string $click_url, string $alt_text ): int {
		$revision_id = $this->stage_text_revision( $creative_id, $click_url, $alt_text );

		if ( $revision_id <= 0 ) {
			return 0;
		}

		update_post_meta( $revision_id, Creative_Repository::META_REPLACES_ID, $creative_id );
		update_post_meta( $revision_id, Creative_Repository::META_CHANGE_STATE, Creative_Repository::CHANGE_PENDING );
		update_post_meta( $revision_id, Creative_Repository::META_REQUESTED_AT, time() );

		return $revision_id;
	}

	/**
	 * Creates the revision post and carries the predecessor's bytes across.
	 *
	 * Shared by both shapes so the byte-copying — the thing that makes
	 * `is_text_only_revision()` derivable rather than asserted — cannot drift
	 * between them.
	 *
	 * @param int    $creative_id Superseded creative id.
	 * @param string $click_url   New destination.
	 * @param string $alt_text    New alternative text.
	 * @return int New creative id, or 0 on failure.
	 */
	private function stage_text_revision( int $creative_id, string $click_url, string $alt_text ): int {
		$source = get_post( $creative_id );

		if ( ! $source instanceof \WP_Post ) {
			return 0;
		}

		$revision_id = wp_insert_post(
			array(
				'post_type'   => Post_Types::CREATIVE,
				'post_status' => $source->post_status,
				'post_title'  => $source->post_title,
				'post_parent' => $source->post_parent,
			),
			true
		);

		if ( is_wp_error( $revision_id ) || 0 === (int) $revision_id ) {
			return 0;
		}

		$revision_id = (int) $revision_id;

		// Everything except the text is carried across unchanged, including the
		// checksum and the attachment: the bytes are the same bytes.
		foreach ( array(
			Creative_Repository::META_CAMPAIGN_ID,
			Creative_Repository::META_ORG_ID,
			Creative_Repository::META_PLACEMENT_ID,
			Creative_Repository::META_SIZE,
			Creative_Repository::META_KIND,
			Creative_Repository::META_WIDTH,
			Creative_Repository::META_HEIGHT,
			Creative_Repository::META_TARGET_BLANK,
			Creative_Repository::META_ATTACHMENT_ID,
			Creative_Repository::META_SHA256,
			Creative_Repository::META_MIME,
			Creative_Repository::META_FILESIZE,
			Creative_Repository::META_ORIGINAL_NAME,
		) as $key ) {
			$value = get_post_meta( $creative_id, $key, true );

			if ( '' !== $value && null !== $value ) {
				update_post_meta( $revision_id, $key, $value );
			}
		}

		update_post_meta( $revision_id, Creative_Repository::META_CLICK_URL, $click_url );
		update_post_meta( $revision_id, Creative_Repository::META_ALT_TEXT, $alt_text );

		return $revision_id;
	}

	/**
	 * Whether a revision differs from its predecessor only in text.
	 *
	 * Derived from the checksums the two rows already carry, never from
	 * anything a caller supplies. A client-settable "this is only a text
	 * change" flag would let somebody swap the artwork and claim the one-click
	 * review lane.
	 *
	 * @param int $revision_id Revision to classify.
	 * @return bool
	 */
	public function is_text_only_revision( int $revision_id ): bool {
		// Via the forward link, for the reason `predecessor_of()` records: the
		// backward one is deleted at approval and this classification has to
		// survive the revision going live.
		$previous = $this->predecessor_of( $revision_id );

		if ( $previous <= 0 ) {
			return false;
		}

		$mine   = (string) get_post_meta( $revision_id, Creative_Repository::META_SHA256, true );
		$theirs = (string) get_post_meta( $previous, Creative_Repository::META_SHA256, true );

		return '' !== $mine && $mine === $theirs;
	}
}
