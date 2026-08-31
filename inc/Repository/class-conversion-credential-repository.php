<?php
/**
 * Server-to-server conversion credentials.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Conversion_Credential;
use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_conversion_credentials.
 *
 * **The plaintext never reaches this class's storage.** `issue()` mints a
 * secret, writes its digest, and hands the secret back to exactly one caller.
 * Nothing here can return it again, because nothing here has it — which is the
 * property that makes "we cannot show you the token again, issue a new one" an
 * honest answer rather than a policy.
 */
final class Conversion_Credential_Repository {

	/**
	 * A site cannot hold more live credentials than this.
	 *
	 * Not a licensing limit. Revocation is the operator's tool for a leaked
	 * secret, and a credential list nobody can read through is a list nobody
	 * revokes from. Fifty is far past one integration per advertiser.
	 */
	public const MAX_LIVE_CREDENTIALS = 50;

	/**
	 * Memoised existence, matching the other custom-table repositories.
	 *
	 * @var bool|null
	 */
	private ?bool $table_exists = null;

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::CONVERSION_CREDENTIALS_TABLE;
	}

	/**
	 * Creates or repairs the credentials table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::conversion_credentials_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );

		$this->table_exists = null;
	}

	/**
	 * Whether the credentials table exists.
	 */
	public function table_exists(): bool {
		if ( null !== $this->table_exists ) {
			return $this->table_exists;
		}

		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$this->table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $this->table_exists;
	}

	/**
	 * Drops the credentials table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$this->table_exists = false;
	}

	/**
	 * Mints one credential and stores only its digest.
	 *
	 * Returns the plaintext with the row, because this is the one moment it
	 * exists. A caller that loses it has to issue another; there is no read
	 * path back to it, by construction rather than by rule.
	 *
	 * @param int    $org_id     Organization the credential may report for.
	 * @param string $label      Staff-facing name.
	 * @param int    $created_by Issuing user id.
	 * @return array{id: int, token: string}|null Null when the write failed.
	 */
	public function issue( int $org_id, string $label, int $created_by ): ?array {
		if ( ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;

		$token = $this->mint();

		$written = $wpdb->insert(  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write owned by this repository.
			$this->table_name(),
			array(
				'created_at_ts'   => time(),
				'last_used_at_ts' => 0,
				'revoked_at_ts'   => 0,
				'org_id'          => $org_id,
				'created_by'      => $created_by,
				'token_hash'      => $this->digest( $token ),
				'label'           => $label,
			),
			array( '%d', '%d', '%d', '%d', '%d', '%s', '%s' )
		);

		if ( ! $written ) {
			return null;
		}

		return array(
			'id'    => (int) $wpdb->insert_id,
			'token' => $token,
		);
	}

	/**
	 * Resolves a presented secret to the credential it belongs to.
	 *
	 * Shape-checked before the query, so bytes that cannot be one of ours cost
	 * a string comparison rather than an indexed read on a public endpoint.
	 *
	 * Returns the row whether or not it is revoked. Deciding what a revoked
	 * credential means is the workflow's job, and a repository that quietly
	 * returned null for one would leave the audit trail unable to say that a
	 * revoked secret was still being presented — which is the signal an
	 * operator most wants after revoking it.
	 *
	 * @param string $token Presented plaintext.
	 * @return array{id: int, created_at_ts: int, last_used_at_ts: int, revoked_at_ts: int, org_id: int, created_by: int, label: string}|null
	 */
	public function find_by_token( string $token ): ?array {
		if ( ! Conversion_Credential::is_valid_token( $token ) || ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE token_hash = %s LIMIT 1',
				$this->table_name(),
				$this->digest( $token )
			),
			ARRAY_A
		);

		return is_array( $row ) ? $this->typed_row( $row ) : null;
	}

	/**
	 * Marks one credential revoked, if it is not already.
	 *
	 * Guarded in SQL rather than by reading first: two staff revoking the same
	 * credential must not overwrite the earlier revocation time, because that
	 * timestamp is the answer to "when did we cut this off" in an incident.
	 *
	 * @param int $credential_id Credential row id.
	 * @return bool Whether this call was the one that revoked it.
	 */
	public function revoke( int $credential_id ): bool {
		if ( $credential_id <= 0 || ! $this->table_exists() ) {
			return false;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write owned by this repository.
		$updated = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET revoked_at_ts = %d WHERE id = %d AND revoked_at_ts = 0',
				$this->table_name(),
				time(),
				$credential_id
			)
		);

		return is_int( $updated ) && $updated > 0;
	}

	/**
	 * Records that a credential authenticated a request.
	 *
	 * Best effort and deliberately unchecked. This is an operator convenience —
	 * "is this integration still running?" — and a failed write here must never
	 * turn a conversion that was accepted into one that was refused.
	 *
	 * @param int $credential_id Credential row id.
	 */
	public function touch( int $credential_id ): void {
		if ( $credential_id <= 0 || ! $this->table_exists() ) {
			return;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table write owned by this repository.
		$wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET last_used_at_ts = %d WHERE id = %d',
				$this->table_name(),
				time(),
				$credential_id
			)
		);
	}

	/**
	 * Every credential, newest first, for the staff list.
	 *
	 * Revoked ones included: an operator reviewing an incident needs to see what
	 * was cut off and when, and a list that hid them would make a revocation
	 * indistinguishable from a credential that never existed.
	 *
	 * @return list<array{id: int, created_at_ts: int, last_used_at_ts: int, revoked_at_ts: int, org_id: int, created_by: int, label: string}>
	 */
	public function all(): array {
		if ( ! $this->table_exists() ) {
			return array();
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i ORDER BY id DESC LIMIT %d',
				$this->table_name(),
				self::MAX_LIVE_CREDENTIALS * 4
			),
			ARRAY_A
		);

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( fn ( array $row ): array => $this->typed_row( $row ), $rows ) );
	}

	/**
	 * How many credentials are still live.
	 *
	 * @return int
	 */
	public function live_count(): int {
		if ( ! $this->table_exists() ) {
			return 0;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		return (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE revoked_at_ts = 0', $this->table_name() )
		);
	}

	/**
	 * One credential by id, for revocation and auditing.
	 *
	 * @param int $credential_id Credential row id.
	 * @return array{id: int, created_at_ts: int, last_used_at_ts: int, revoked_at_ts: int, org_id: int, created_by: int, label: string}|null
	 */
	public function find( int $credential_id ): ?array {
		if ( $credential_id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom-table read owned by this repository.
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table_name(), $credential_id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->typed_row( $row ) : null;
	}

	/**
	 * A fresh URL-safe secret.
	 *
	 * The same construction as the organization invitation token, so there is
	 * one answer in this codebase to "what does a bearer secret look like".
	 */
	private function mint(): string {
		return rtrim(
			strtr( base64_encode( random_bytes( Conversion_Credential::TOKEN_BYTES ) ), '+/', '-_' ),
			'='
		);
	}

	/**
	 * Verifier digest for one secret.
	 *
	 * `wp_salt( 'auth' )` deliberately, and see the note on
	 * `Schema::conversion_credentials_table_ddl()`: this is a secret verifier,
	 * not a lookup index over something stored in the clear, so a salt rotation
	 * invalidating every outstanding credential is the intended behaviour rather
	 * than the defect it was for `aggr_org_access.active_key`.
	 *
	 * @param string $token Presented or minted plaintext.
	 */
	private function digest( string $token ): string {
		return hash_hmac( 'sha256', $token, wp_salt( 'auth' ) );
	}

	/**
	 * Normalizes database scalars, minus the digest.
	 *
	 * `token_hash` is dropped on the way out. Nothing above this class has any
	 * use for it, and a verifier that never leaves the repository cannot be
	 * logged, serialized into a REST response, or compared by a caller that
	 * forgot to use a constant-time comparison.
	 *
	 * @param array<string, mixed> $row Database row.
	 * @return array{id: int, created_at_ts: int, last_used_at_ts: int, revoked_at_ts: int, org_id: int, created_by: int, label: string}
	 */
	private function typed_row( array $row ): array {
		return array(
			'id'              => (int) ( $row['id'] ?? 0 ),
			'created_at_ts'   => (int) ( $row['created_at_ts'] ?? 0 ),
			'last_used_at_ts' => (int) ( $row['last_used_at_ts'] ?? 0 ),
			'revoked_at_ts'   => (int) ( $row['revoked_at_ts'] ?? 0 ),
			'org_id'          => (int) ( $row['org_id'] ?? 0 ),
			'created_by'      => (int) ( $row['created_by'] ?? 0 ),
			'label'           => (string) ( $row['label'] ?? '' ),
		);
	}
}
