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
 * **The replacement lifecycle moved here the second time that limit was hit**,
 * and it belongs here rather than in a third class: a pending replacement, the
 * decision on it, and the swap that applies it are all the same question about
 * the same relationship. Half of it already lived here —
 * `create_pending_text_revision()` writes the change state that
 * `change_state()` reads — so the two halves of one workflow were split across
 * two files, which is worse than either arrangement.
 *
 * The trap is worth reading before touching anything here. `META_REPLACES_ID`
 * is the obvious backward link and is deleted the moment a replacement is
 * approved, because `is_active()` reads its presence as "still pending". Only
 * `META_REPLACED_BY` survives going live, so the chain is durable in one
 * direction only and every walk has to go with the grain.
 */
final class Creative_Revision_Repository {

	/**
	 * Advisory locks held by this request, keyed by database lock name.
	 *
	 * @var array<string, true>
	 */
	private static array $change_locks = array();

	/**
	 * Constructor.
	 *
	 * @param Creative_Repository $creatives The creative record this chain is over.
	 */
	public function __construct( private readonly Creative_Repository $creatives ) {
	}


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

	/**
	 * Replacement-review state.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return string
	 */
	public function change_state( int $creative_id ): string {
		return (string) get_post_meta( $creative_id, Creative_Repository::META_CHANGE_STATE, true );
	}

	/**
	 * When a replacement was requested.
	 *
	 * @param int $creative_id Creative revision id.
	 * @return int
	 */
	public function requested_at( int $creative_id ): int {
		return (int) get_post_meta( $creative_id, Creative_Repository::META_REQUESTED_AT, true );
	}

	/**
	 * Pending replacement for one active creative, if any.
	 *
	 * @param int $creative_id Active creative id.
	 * @return int
	 */
	public function pending_replacement_id( int $creative_id ): int {
		foreach ( $this->creatives->ids_for_campaign( (int) ( $this->creatives->details( $creative_id )['campaign_id'] ?? 0 ) ) as $candidate_id ) {
			if ( $creative_id === $this->creatives->replacement_target_id( $candidate_id ) && Creative_Repository::CHANGE_PENDING === $this->change_state( $candidate_id ) ) {
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

		foreach ( array_reverse( $this->creatives->ids_for_campaign( $campaign_id ) ) as $creative_id ) {
			$state = $this->change_state( $creative_id );

			if ( $this->creatives->replacement_target_id( $creative_id ) <= 0 || ( array() !== $states && ! in_array( $state, $states, true ) ) ) {
				continue;
			}

			$details = $this->creatives->details( $creative_id );

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
		update_post_meta( $creative_id, Creative_Repository::META_CHANGE_STATE, Creative_Repository::CHANGE_REJECTED );
		update_post_meta( $creative_id, Creative_Repository::META_CHANGE_NOTES, $notes );
		update_post_meta( $creative_id, Creative_Repository::META_DECIDED_AT, time() );

		return Creative_Repository::CHANGE_REJECTED === $this->change_state( $creative_id )
			&& $notes === $this->creatives->change_notes( $creative_id );
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
		$old_review = (string) get_post_meta( $current_id, Creative_Repository::META_REVIEW_STATE, true );

		update_post_meta( $current_id, Creative_Repository::META_REPLACED_BY, $replacement_id );
		update_post_meta( $current_id, Creative_Repository::META_REVIEW_STATE, 'replaced' );
		delete_post_meta( $current_id, Creative_Repository::META_PROVIDER_AD );

		delete_post_meta( $replacement_id, Creative_Repository::META_REPLACES_ID );
		update_post_meta( $replacement_id, Creative_Repository::META_CHANGE_STATE, Creative_Repository::CHANGE_APPLIED );
		update_post_meta( $replacement_id, Creative_Repository::META_REVIEW_STATE, 'approved' );
		update_post_meta( $replacement_id, Creative_Repository::META_DECIDED_AT, time() );
		update_post_meta( $replacement_id, Creative_Repository::META_PROVIDER_AD, $provider_ad_id );

		$activated = $this->creatives->is_active( $replacement_id )
			&& ! $this->creatives->is_active( $current_id )
			&& $provider_ad_id === $this->creatives->provider_ad_id( $replacement_id );

		if ( $activated ) {
			return true;
		}

		delete_post_meta( $current_id, Creative_Repository::META_REPLACED_BY );
		update_post_meta( $current_id, Creative_Repository::META_REVIEW_STATE, $old_review );
		update_post_meta( $current_id, Creative_Repository::META_PROVIDER_AD, $provider_ad_id );
		update_post_meta( $replacement_id, Creative_Repository::META_REPLACES_ID, $current_id );
		update_post_meta( $replacement_id, Creative_Repository::META_CHANGE_STATE, Creative_Repository::CHANGE_PENDING );
		update_post_meta( $replacement_id, Creative_Repository::META_REVIEW_STATE, 'pending' );
		delete_post_meta( $replacement_id, Creative_Repository::META_PROVIDER_AD );

		return false;
	}

	/**
	 * Atomically claims a replacement operation lock.
	 *
	 * @param int $creative_id Current creative id.
	 * @return string Empty when another request owns the lock.
	 */
	public function claim_change_lock( int $creative_id ): string {
		global $wpdb;

		$lock_name = 'aggr_creative_change_' . get_current_blog_id() . '_' . $creative_id;

		if ( isset( self::$change_locks[ $lock_name ] ) ) {
			return '';
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- The advisory lock is the atomic cross-request serialization primitive.
		$acquired = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 0 ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- The advisory lock name and timeout are prepared.
		);

		if ( 1 !== $acquired ) {
			return '';
		}

		self::$change_locks[ $lock_name ] = true;

		return $lock_name;
	}

	/**
	 * Releases a replacement operation lock only for its owner.
	 *
	 * @param int    $creative_id Current creative id.
	 * @param string $token       Claim token.
	 * @return void
	 */
	public function release_change_lock( int $creative_id, string $token ): void {
		global $wpdb;

		$expected = 'aggr_creative_change_' . get_current_blog_id() . '_' . $creative_id;

		if ( $expected !== $token || ! isset( self::$change_locks[ $token ] ) ) {
			return;
		}

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases only the exact advisory lock this request acquired.
			$wpdb->get_var(
				$wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $token ) // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Releases only the exact advisory lock this request acquired.
			);
		} finally {
			unset( self::$change_locks[ $token ] );
		}
	}
}
