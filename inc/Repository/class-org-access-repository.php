<?php
/**
 * Organization identity and membership-access persistence.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Core\Post_Types;
use Aggressive\Ads\Install\Schema;
use WP_Error;

/**
 * Owns canonical identities, invitations, and duplicate-name access requests.
 */
final class Org_Access_Repository {

	/**
	 * Option holding the salt behind `active_key`.
	 *
	 * Not autoloaded: it is read on identity writes and lookups, not on every
	 * request, and it must never be regenerated once rows exist.
	 */
	public const LOOKUP_SALT_OPTION = 'aggr_org_access_lookup_salt';

	public const KIND_IDENTITY = 'identity';
	public const KIND_INVITE   = 'invite';
	public const KIND_REQUEST  = 'request';

	public const STATUS_ACTIVE     = 'active';
	public const STATUS_PENDING    = 'pending';
	public const STATUS_PROCESSING = 'processing';
	public const STATUS_ACCEPTED   = 'accepted';
	public const STATUS_DENIED     = 'denied';
	public const STATUS_REVOKED    = 'revoked';
	public const STATUS_EXPIRED    = 'expired';

	public const INVITE_TTL          = 3 * DAY_IN_SECONDS;
	public const REQUEST_TTL         = 7 * DAY_IN_SECONDS;
	public const MAX_PENDING_PER_ORG = 100;

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::ORG_ACCESS_TABLE;
	}

	/**
	 * Create or repair the table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::org_access_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Whether the table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection for this repository's custom table.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $table === $found;
	}

	/**
	 * Drop the table during a destructive uninstall.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Drops this repository's fixed, prefix-derived table only.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Atomically reserve one canonical organization identity.
	 *
	 * @param int    $org_id         Organization id.
	 * @param string $canonical_name Canonical display name.
	 * @return true|WP_Error
	 */
	public function register_identity( int $org_id, string $canonical_name ): bool|WP_Error {
		global $wpdb;

		if ( $org_id <= 0 || '' === $canonical_name ) {
			return new WP_Error(
				'aggr_invalid_org_identity',
				__( 'Enter a valid organization name.', 'aggressive-ads' )
			);
		}

		$key = $this->active_key( self::KIND_IDENTITY, 0, $canonical_name );

		// Exact lookup also removes a reservation whose organization was deleted
		// outside this repository. Without that repair, one stale row can block
		// the organization name permanently.
		$this->org_id_for_canonical( $canonical_name );

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom identity registry; the unique active_key is the concurrency control. Duplicate key is a WP_Error, not HTML.
		$written = $wpdb->insert(
			$this->table_name(),
			array(
				'org_id'         => $org_id,
				'kind'           => self::KIND_IDENTITY,
				'status'         => self::STATUS_ACTIVE,
				'canonical_name' => $canonical_name,
				'created_at_ts'  => time(),
				'token_hash'     => $this->digest( 'identity-token|' . $org_id . '|' . $canonical_name ),
				'active_key'     => $key,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s' )
		);
		$wpdb->suppress_errors( false );

		if ( false !== $written ) {
			return true;
		}

		$existing = $this->org_id_for_canonical( $canonical_name );
		if ( $org_id === $existing ) {
			return true;
		}

		return new WP_Error( 'aggr_duplicate_org_identity', '', array( 'org_id' => $existing ) );
	}

	/**
	 * Remove a canonical identity when its just-created organization rolls back.
	 *
	 * @param int $org_id Organization id.
	 */
	public function remove_identity( int $org_id ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom registry cleanup paired with a failed organization creation.
		$wpdb->delete(
			$this->table_name(),
			array(
				'org_id' => $org_id,
				'kind'   => self::KIND_IDENTITY,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Atomically move one organization's reserved canonical identity.
	 *
	 * The new `active_key` is reserved first so a concurrent signup cannot claim
	 * the destination name. Only the previous identity row is deleted afterwards;
	 * `remove_identity()` would wipe both and leave the tenant unmatchable.
	 *
	 * @param int    $org_id         Organization id.
	 * @param string $old_canonical  Current canonical name.
	 * @param string $new_canonical  Destination canonical name.
	 * @return true|WP_Error
	 */
	public function rename_identity( int $org_id, string $old_canonical, string $new_canonical ): bool|WP_Error {
		global $wpdb;

		if ( $org_id <= 0 || '' === $old_canonical || '' === $new_canonical ) {
			return new WP_Error(
				'aggr_invalid_org_identity',
				__( 'Enter a valid organization name.', 'aggressive-ads' )
			);
		}

		if ( $old_canonical === $new_canonical ) {
			return true;
		}

		if ( $org_id !== $this->org_id_for_canonical( $old_canonical ) ) {
			/*
			 * No row under the old name is not always a mismatch.
			 *
			 * An organization can reach this point holding no identity row at
			 * all — created before the registry existed, imported, or seeded
			 * around the normal path. Refusing there made the organization
			 * permanently unrenameable, with an error blaming a registry entry
			 * that was never written.
			 *
			 * So a *missing* registration is repaired by making it, and only a
			 * registration held under some other name is refused. Claiming the
			 * new name still goes through register_identity(), whose unique
			 * index is what stops two organizations sharing one name, so this
			 * heals the gap without widening what may be taken.
			 */
			if ( 0 === $this->identity_row_count( $org_id ) ) {
				return $this->register_identity( $org_id, $new_canonical );
			}

			return new WP_Error(
				'aggr_org_identity_mismatch',
				__( 'This organization is registered under a different name, so it cannot be renamed.', 'aggressive-ads' )
			);
		}

		$registered = $this->register_identity( $org_id, $new_canonical );
		if ( is_wp_error( $registered ) ) {
			return $registered;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Deletes only the previous identity row after the destination key is reserved.
		$wpdb->delete(
			$this->table_name(),
			array(
				'org_id'         => $org_id,
				'kind'           => self::KIND_IDENTITY,
				'canonical_name' => $old_canonical,
			),
			array( '%d', '%s', '%s' )
		);

		if ( $org_id !== $this->org_id_for_canonical( $new_canonical ) || $org_id === $this->org_id_for_canonical( $old_canonical ) ) {
			$this->delete_identity_name( $org_id, $new_canonical );
			$this->register_identity( $org_id, $old_canonical );

			return new WP_Error(
				'aggr_org_identity_not_saved',
				__( 'That organization name could not be saved.', 'aggressive-ads' )
			);
		}

		return true;
	}

	/**
	 * How many active identity rows one organization holds.
	 *
	 * @param int $org_id Organization id.
	 * @return int
	 */
	private function identity_row_count( int $org_id ): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed count in the custom identity registry.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE org_id = %d AND kind = %s AND status = %s',
				$this->table_name(),
				$org_id,
				self::KIND_IDENTITY,
				self::STATUS_ACTIVE
			)
		);
	}

	/**
	 * Delete one identity row by organization and canonical name.
	 *
	 * @param int    $org_id         Organization id.
	 * @param string $canonical_name Canonical name.
	 */
	private function delete_identity_name( int $org_id, string $canonical_name ): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensating deletion of one identity reservation.
		$wpdb->delete(
			$this->table_name(),
			array(
				'org_id'         => $org_id,
				'kind'           => self::KIND_IDENTITY,
				'canonical_name' => $canonical_name,
			),
			array( '%d', '%s', '%s' )
		);
	}

	/**
	 * Resolve an exact canonical identity.
	 *
	 * @param string $canonical_name Canonical name.
	 */
	public function org_id_for_canonical( string $canonical_name ): int {
		global $wpdb;

		if ( '' === $canonical_name ) {
			return 0;
		}

		$key = $this->active_key( self::KIND_IDENTITY, 0, $canonical_name );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact indexed lookup in the custom identity registry.
		$org_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT org_id FROM %i WHERE active_key = %s AND kind = %s AND status = %s LIMIT 1',
				$this->table_name(),
				$key,
				self::KIND_IDENTITY,
				self::STATUS_ACTIVE
			)
		);

		if ( $org_id <= 0 || Post_Types::ORGANIZATION === get_post_type( $org_id ) ) {
			return $org_id;
		}

		$this->remove_identity( $org_id );

		return 0;
	}

	/**
	 * Resolve one unambiguous close spelling, never an arbitrary best guess.
	 *
	 * @param string $canonical_name Canonical candidate.
	 */
	public function similar_org_id( string $canonical_name ): int {
		global $wpdb;

		if ( mb_strlen( $canonical_name ) < 4 ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded identity catalogue joined to existing organization posts so deleted tenants cannot attract access requests.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT identities.org_id, identities.canonical_name FROM %i identities INNER JOIN %i posts ON posts.ID = identities.org_id AND posts.post_type = %s WHERE identities.kind = %s AND identities.status = %s ORDER BY identities.id ASC LIMIT 1000',
				$this->table_name(),
				$wpdb->posts,
				Post_Types::ORGANIZATION,
				self::KIND_IDENTITY,
				self::STATUS_ACTIVE
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$best_id = 0;
		$best    = 0.0;
		$second  = 0.0;

		foreach ( $rows as $row ) {
			$name = is_array( $row ) ? (string) ( $row['canonical_name'] ?? '' ) : '';
			if ( '' === $name ) {
				continue;
			}

			$length = max( strlen( $canonical_name ), strlen( $name ) );
			$score  = 1 - ( levenshtein( $canonical_name, $name ) / max( 1, $length ) );

			if ( $score > $best ) {
				$second  = $best;
				$best    = $score;
				$best_id = (int) ( $row['org_id'] ?? 0 );
			} elseif ( $score > $second ) {
				$second = $score;
			}
		}

		return $best >= 0.88 && ( $best - $second ) >= 0.03 ? $best_id : 0;
	}

	/**
	 * Create a pending owner-issued invitation.
	 *
	 * @param int    $org_id     Organization id.
	 * @param string $email      Normalized email.
	 * @param int    $inviter_id Inviting owner/staff user.
	 * @return array{id: int, token: string, expires_at_ts: int}|WP_Error
	 */
	public function create_invite( int $org_id, string $email, int $inviter_id ): array|WP_Error {
		return $this->create_pending( self::KIND_INVITE, $org_id, $email, 0, $inviter_id, self::INVITE_TTL );
	}

	/**
	 * Create a duplicate-name access request for a new subscriber.
	 *
	 * @param int    $org_id  Candidate organization.
	 * @param string $email   Normalized email.
	 * @param int    $user_id Pending subscriber.
	 * @return int|WP_Error
	 */
	public function create_request( int $org_id, string $email, int $user_id ): int|WP_Error {
		$result = $this->create_pending( self::KIND_REQUEST, $org_id, $email, $user_id, 0, self::REQUEST_TTL );

		return is_wp_error( $result ) ? $result : $result['id'];
	}

	/**
	 * Find an invitation by its bearer token and intended address.
	 *
	 * @param string $token Raw invitation token.
	 * @param string $email Normalized email.
	 * @return array<string, int|string>|null
	 */
	public function invitation( string $token, string $email ): ?array {
		global $wpdb;

		$this->expire_stale();

		if ( '' === $token || '' === $email ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single indexed token lookup in the custom access table.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token_hash = %s AND kind = %s AND status = %s AND email = %s AND expires_at_ts >= %d LIMIT 1',
				$this->table_name(),
				$this->digest( $token ),
				self::KIND_INVITE,
				self::STATUS_PENDING,
				$email,
				time()
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->typed_row( $row ) : null;
	}

	/**
	 * One pending duplicate-name request for a user.
	 *
	 * @param int $user_id User id.
	 * @return array<string, int|string>|null
	 */
	public function pending_for_user( int $user_id ): ?array {
		global $wpdb;

		$this->expire_stale();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed pending-state lookup used after successful authentication.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE request_user_id = %d AND kind = %s AND status = %s LIMIT 1',
				$this->table_name(),
				$user_id,
				self::KIND_REQUEST,
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->typed_row( $row ) : null;
	}

	/**
	 * Whether the user's previous request for this organization expired.
	 *
	 * This permits a bounded public signup retry without weakening the ordinary
	 * existing-email no-mail behavior or allowing the address to target a
	 * different organization.
	 *
	 * @param int $user_id User id.
	 * @param int $org_id  Organization id.
	 */
	public function has_expired_request( int $user_id, int $org_id ): bool {
		global $wpdb;

		$this->expire_stale();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Indexed lifecycle lookup for an account retry.
		return 1 === (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM %i WHERE request_user_id = %d AND org_id = %d AND kind = %s AND status = %s LIMIT 1',
				$this->table_name(),
				$user_id,
				$org_id,
				self::KIND_REQUEST,
				self::STATUS_EXPIRED
			)
		);
	}

	/**
	 * Pending invitations and requests visible to an authorized org owner.
	 *
	 * @param int $org_id Organization id.
	 * @return array<int, array<string, int|string>>
	 */
	public function pending_for_org( int $org_id ): array {
		global $wpdb;

		$this->expire_stale();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded, indexed organization access queue.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE org_id = %d AND status = %s AND kind IN (%s, %s) ORDER BY id DESC LIMIT %d',
				$this->table_name(),
				$org_id,
				self::STATUS_PENDING,
				self::KIND_REQUEST,
				self::KIND_INVITE,
				self::MAX_PENDING_PER_ORG
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map( array( $this, 'typed_row' ), $rows );
	}

	/**
	 * Load one pending row within an organization.
	 *
	 * @param int    $id     Row id.
	 * @param int    $org_id Organization id.
	 * @param string $kind   Expected kind.
	 * @return array<string, int|string>|null
	 */
	public function pending( int $id, int $org_id, string $kind ): ?array {
		global $wpdb;

		$this->expire_stale();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Primary-key lookup constrained again by tenant and state.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE id = %d AND org_id = %d AND kind = %s AND status = %s LIMIT 1',
				$this->table_name(),
				$id,
				$org_id,
				$kind,
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->typed_row( $row ) : null;
	}

	/**
	 * Resolve a pending row exactly once.
	 *
	 * @param int    $id       Row id.
	 * @param string $status   Terminal status.
	 * @param int    $actor_id Resolving owner/staff user.
	 */
	public function resolve( int $id, string $status, int $actor_id ): bool {
		global $wpdb;

		if ( ! in_array( $status, array( self::STATUS_ACCEPTED, self::STATUS_DENIED, self::STATUS_REVOKED ), true ) ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Conditional state transition in the custom access table.
		$updated = $wpdb->update(
			$this->table_name(),
			array(
				'status'              => $status,
				'resolved_by_user_id' => $actor_id,
				'resolved_at_ts'      => time(),
				'active_key'          => $this->digest( 'resolved|' . $id . '|' . wp_generate_uuid4() ),
			),
			array(
				'id'     => $id,
				'status' => self::STATUS_PROCESSING,
			),
			array( '%s', '%d', '%d', '%s' ),
			array( '%d', '%s' )
		);

		return 1 === $updated;
	}

	/**
	 * Claim a pending row before performing membership side effects.
	 *
	 * @param int $id Row id.
	 */
	public function claim( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic state claim prevents two approvers consuming the same row.
		return 1 === $wpdb->update(
			$this->table_name(),
			array( 'status' => self::STATUS_PROCESSING ),
			array(
				'id'     => $id,
				'status' => self::STATUS_PENDING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Return a failed claim to pending while retaining its uniqueness lock.
	 *
	 * @param int $id Row id.
	 */
	public function release_claim( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensation for a claimed workflow that could not complete.
		return 1 === $wpdb->update(
			$this->table_name(),
			array( 'status' => self::STATUS_PENDING ),
			array(
				'id'     => $id,
				'status' => self::STATUS_PROCESSING,
			),
			array( '%s' ),
			array( '%d', '%s' )
		);
	}

	/**
	 * Delete an unannounced row after invitation/request email failure.
	 *
	 * @param int $id Row id.
	 */
	public function delete_pending( int $id ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Compensation for a workflow that could not send its required email.
		return 1 === $wpdb->delete(
			$this->table_name(),
			array(
				'id'     => $id,
				'status' => self::STATUS_PENDING,
			),
			array( '%d', '%s' )
		);
	}

	/**
	 * Create one pending access row.
	 *
	 * @param string $kind       Invite or request.
	 * @param int    $org_id     Organization id.
	 * @param string $email      Normalized email.
	 * @param int    $user_id    Pending user id for a request.
	 * @param int    $creator_id Inviting user id.
	 * @param int    $ttl        Lifetime in seconds.
	 * @return array{id: int, token: string, expires_at_ts: int}|WP_Error
	 */
	private function create_pending( string $kind, int $org_id, string $email, int $user_id, int $creator_id, int $ttl ): array|WP_Error {
		global $wpdb;

		if ( ! in_array( $kind, array( self::KIND_INVITE, self::KIND_REQUEST ), true ) || $org_id <= 0 || ! is_email( $email ) ) {
			return new WP_Error( 'aggr_invalid_org_access' );
		}

		$token   = $this->token();
		$expires = time() + $ttl;

		$wpdb->suppress_errors( true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom access write; unique active_key atomically prevents a duplicate pending row. Duplicate key is a WP_Error, not HTML.
		$written = $wpdb->insert(
			$this->table_name(),
			array(
				'org_id'             => $org_id,
				'kind'               => $kind,
				'status'             => self::STATUS_PENDING,
				'email'              => $email,
				'request_user_id'    => $user_id,
				'created_by_user_id' => $creator_id,
				'created_at_ts'      => time(),
				'expires_at_ts'      => $expires,
				'token_hash'         => $this->digest( $token ),
				'active_key'         => $this->active_key( $kind, $org_id, $email ),
			),
			array( '%d', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s' )
		);
		$wpdb->suppress_errors( false );

		if ( false === $written ) {
			return new WP_Error( 'aggr_org_access_exists', __( 'A request for that email is already pending.', 'aggressive-ads' ) );
		}

		return array(
			'id'            => (int) $wpdb->insert_id,
			'token'         => $token,
			'expires_at_ts' => $expires,
		);
	}

	/** Expire stale pending rows and release their unique active keys. */
	private function expire_stale(): void {
		global $wpdb;

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded indexed lifecycle update for expired access rows.
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM %i WHERE status = %s AND expires_at_ts > 0 AND expires_at_ts < %d LIMIT 100',
				$this->table_name(),
				self::STATUS_PENDING,
				$now
			)
		);

		foreach ( $ids as $id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Per-row conditional transition preserves unique-key safety under concurrent requests.
			$wpdb->update(
				$this->table_name(),
				array(
					'status'         => self::STATUS_EXPIRED,
					'resolved_at_ts' => $now,
					'active_key'     => $this->digest( 'expired|' . (int) $id . '|' . wp_generate_uuid4() ),
				),
				array(
					'id'     => (int) $id,
					'status' => self::STATUS_PENDING,
				),
				array( '%s', '%d', '%s' ),
				array( '%d', '%s' )
			);
		}
	}

	/**
	 * Build the unique pending/identity key.
	 *
	 * @param string $kind     Row kind.
	 * @param int    $org_id   Organization id.
	 * @param string $identity Canonical name or normalized email.
	 */
	private function active_key( string $kind, int $org_id, string $identity ): string {
		return $this->lookup_digest( $kind . '|' . $org_id . '|' . strtolower( $identity ) );
	}

	/**
	 * Recomputes every `active_key` from the plaintext beside it.
	 *
	 * Repairs a registry whose keys were written under a different salt. Every
	 * input is recoverable, which is the whole reason this is a migration and
	 * not a data loss: an identity row stores its `canonical_name`, and an
	 * invitation or request stores its `email`, both in the clear. Only
	 * `token_hash` is unrecoverable, and it is deliberately left alone.
	 *
	 * **Only live rows.** `active_key` means two different things depending on
	 * status. On an active identity or a pending invitation it is the lookup
	 * key. On anything resolved or expired it is a random sentinel, written by
	 * `resolve()` and the expiry sweep precisely so the unique index stops
	 * reserving that name or address — which is what lets somebody who once
	 * declined an invitation be invited again.
	 *
	 * Recomputing those would re-reserve every address ever invited, so a
	 * second invitation to any of them would come back as "already pending".
	 * Worse, where a pending row for the same address already existed, the
	 * UPDATE would collide with the unique index, fail, and leave that row on
	 * its unreproducible key with nothing reporting it.
	 *
	 * Idempotent. Rows already keyed correctly are rewritten to the same value,
	 * so running it twice changes nothing.
	 *
	 * @return int Rows rewritten.
	 */
	public function reindex_active_keys(): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration over the custom registry.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT id, kind, org_id, canonical_name, email FROM %i WHERE status IN ( %s, %s )',
				$this->table_name(),
				self::STATUS_ACTIVE,
				self::STATUS_PENDING
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return 0;
		}

		$rewritten = 0;

		foreach ( $rows as $row ) {
			$kind = (string) ( $row['kind'] ?? '' );

			/*
			 * The identity index is global, so it is keyed on scope 0; an
			 * invitation is scoped to its organization. Keying either one the
			 * other way produces a well-formed digest that matches nothing,
			 * which is the failure this migration exists to end.
			 */
			$identity = self::KIND_IDENTITY === $kind
				? (string) ( $row['canonical_name'] ?? '' )
				: (string) ( $row['email'] ?? '' );

			if ( '' === $identity ) {
				continue;
			}

			$scope = self::KIND_IDENTITY === $kind ? 0 : (int) ( $row['org_id'] ?? 0 );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration over the custom registry.
			$updated = $wpdb->update(
				$this->table_name(),
				array( 'active_key' => $this->active_key( $kind, $scope, $identity ) ),
				array( 'id' => (int) ( $row['id'] ?? 0 ) ),
				array( '%s' ),
				array( '%d' )
			);

			if ( false !== $updated ) {
				++$rewritten;

				continue;
			}

			/*
			 * A refused UPDATE leaves the row on a key nothing can reproduce,
			 * and a migration that swallows that is a migration that reports
			 * success over data it did not repair. It cannot happen while the
			 * status filter above holds — two live rows cannot share a key,
			 * because the unique index would not have accepted them — so
			 * reaching here means an assumption broke and is worth the notice.
			 */
			$this->audit_reindex_failure( (int) ( $row['id'] ?? 0 ) );
		}

		return $rewritten;
	}

	/**
	 * Records a row the reindex could not rewrite.
	 *
	 * Deliberately not a WP_Error: this runs inside a migration step whose
	 * caller is the upgrade walker, and failing the whole upgrade over one
	 * unreachable row would leave the rest of the registry unrepaired.
	 *
	 * @param int $row_id Registry row id.
	 * @return void
	 */
	private function audit_reindex_failure( int $row_id ): void {
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			return;
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- Debug-only diagnostic for a migration that cannot fail loudly.
		error_log(
			sprintf(
				'aggr: org access row %d kept an unreproducible active_key; its lookup will not resolve.',
				$row_id
			)
		);
	}

	/**
	 * The salt behind lookup keys, which must outlive an auth-salt rotation.
	 *
	 * `active_key` is an index, not a secret. It is derived from values this
	 * table already stores in the clear beside it — `canonical_name` for an
	 * identity, `email` for an invitation — so hashing it protects nothing that
	 * is not already there, and it exists only to give the unique index
	 * something fixed-width to enforce uniqueness over.
	 *
	 * It used to be salted with `wp_salt( 'auth' )`, and that made the index
	 * perishable. Rotating auth salts is routine security hygiene and is what
	 * invalidates every session; restoring a database into a site with
	 * different AUTH_KEY/AUTH_SALT values does the same thing by accident.
	 * Afterwards no stored key can be recomputed, so every lookup misses rows
	 * that are sitting right there: an organization can never be renamed again,
	 * and duplicate-name detection silently stops detecting anything.
	 *
	 * `token_hash` deliberately still uses `digest()` and `wp_salt( 'auth' )`.
	 * That one *is* a secret verifier for a bearer token, and a salt rotation
	 * invalidating outstanding invitations is correct behaviour rather than a
	 * bug.
	 *
	 * @return string
	 */
	private function lookup_salt(): string {
		$salt = (string) get_option( self::LOOKUP_SALT_OPTION, '' );

		if ( '' !== $salt ) {
			return $salt;
		}

		$fresh = wp_generate_password( 64, true, true );

		// add_option fails when another request won the race; the re-read takes
		// that winner's value rather than overwriting the index it just keyed.
		add_option( self::LOOKUP_SALT_OPTION, $fresh, '', false );

		$stored = (string) get_option( self::LOOKUP_SALT_OPTION, '' );

		return '' !== $stored ? $stored : $fresh;
	}

	/**
	 * Durable digest for lookup keys.
	 *
	 * @param string $value Value to digest.
	 */
	private function lookup_digest( string $value ): string {
		return hash_hmac( 'sha256', $value, $this->lookup_salt() );
	}

	/**
	 * Salted one-way lookup digest.
	 *
	 * @param string $value Value to digest.
	 */
	private function digest( string $value ): string {
		return hash_hmac( 'sha256', $value, wp_salt( 'auth' ) );
	}

	/** Cryptographically random URL-safe bearer token. */
	private function token(): string {
		return rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
	}

	/**
	 * Normalize database scalar types.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array<string, int|string>
	 */
	private function typed_row( array $row ): array {
		$integers = array(
			'id',
			'org_id',
			'request_user_id',
			'created_by_user_id',
			'resolved_by_user_id',
			'created_at_ts',
			'expires_at_ts',
			'resolved_at_ts',
		);

		foreach ( $row as $key => $value ) {
			$row[ $key ] = in_array( $key, $integers, true ) ? (int) $value : (string) $value;
		}

		return $row;
	}
}
