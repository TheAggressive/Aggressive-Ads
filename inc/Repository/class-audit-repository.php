<?php
/**
 * Audit log persistence.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Repository;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Install\Schema;

/**
 * The only code that touches the audit table.
 *
 * This class also owns the table's creation, because installing it needs the
 * database handle and `$wpdb` appears nowhere outside inc/Repository/. Keeping
 * the DDL itself in Install\Schema — as a string, executed here — is what lets
 * the schema be asserted without running a migration.
 *
 * See docs/architecture.md and docs/adr/0003-audit-log-in-custom-table.md.
 */
final class Audit_Repository {

	/**
	 * The fully prefixed table name.
	 *
	 * @return string
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::AUDIT_TABLE;
	}

	/**
	 * Creates or updates the audit table.
	 *
	 * Idempotent by way of dbDelta, which diffs the declared shape against the
	 * live one. Remember that dbDelta **adds but never drops**: removing a
	 * column or key needs an explicit ALTER in a numbered migration step.
	 *
	 * @return void
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::audit_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Reports whether the audit table exists.
	 *
	 * @return bool
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; there is no WordPress API for it, and caching a table's existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the audit table. Uninstall only.
	 *
	 * @return void
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall. The name is built from $wpdb->prefix and a class constant; identifiers cannot be bound as parameters.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Writes one audit row.
	 *
	 * The single write path, using $wpdb->insert() with an explicit format
	 * array. No string interpolation, ever. The DirectDatabaseQuery suppression
	 * appears on this method and nowhere else in the codebase; a second
	 * occurrence is a review failure.
	 *
	 * Returns the inserted id, or 0 when the write failed. **Failure is never
	 * thrown.** Audit is an observer: a business transaction that already
	 * succeeded must not be reversed because logging it did not.
	 *
	 * @param Audit_Event $event The event to record.
	 * @return int
	 */
	public function insert( Audit_Event $event ): int {
		global $wpdb;

		$now = time();

		$data = array(
			'created_at'    => gmdate( 'Y-m-d H:i:s', $now ),
			'created_at_ts' => $now,
			'actor_user_id' => $event->actor_user_id(),
			'actor_role'    => $this->actor_role( $event->actor_user_id() ),
			'actor_ip_hash' => $this->actor_ip_hash(),
			'request_id'    => $this->request_id(),
			'event'         => $event->event(),
			'object_type'   => $event->object_type(),
			'object_id'     => $event->object_id(),
			'org_id'        => $event->org_id(),
			'from_state'    => $event->from_state(),
			'to_state'      => $event->to_state(),
			'outcome'       => $event->outcome(),
			'message'       => $event->message(),
			'context'       => $this->encode_context( $event->context() ),
		);

		$formats = array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The audit log is a custom table with no WordPress API, and an append-only log must never be served from cache.
		$written = $wpdb->insert( $this->table_name(), $data, $formats );

		if ( false === $written ) {
			return 0;
		}

		return (int) $wpdb->insert_id;
	}

	/**
	 * JSON-encodes the context, or null when there is nothing to record.
	 *
	 * @param array<string, mixed> $context Structured detail.
	 * @return string|null
	 */
	private function encode_context( array $context ): ?string {
		if ( array() === $context ) {
			return null;
		}

		$encoded = wp_json_encode( $context );

		return false === $encoded ? null : $encoded;
	}

	/**
	 * The acting user's primary role, for reading the log without a join.
	 *
	 * @param int $user_id Acting user, or 0 for the system.
	 * @return string
	 */
	private function actor_role( int $user_id ): string {
		if ( 0 === $user_id ) {
			return 'system';
		}

		$user = get_userdata( $user_id );

		if ( false === $user || array() === $user->roles ) {
			return '';
		}

		$role = reset( $user->roles );

		return is_string( $role ) ? $role : '';
	}

	/**
	 * A salted hash of the client address — never the address itself.
	 *
	 * A raw IP is personal data carrying a retention obligation. The hash still
	 * answers "was this the same client?", which is the only question the log
	 * is ever actually asked.
	 *
	 * @return string
	 */
	private function actor_ip_hash(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';

		if ( '' === $remote ) {
			return '';
		}

		return hash( 'sha256', $remote . wp_salt( 'laao_ads_audit' ) );
	}

	/**
	 * One id per request, memoized.
	 *
	 * A single approval writes four or five rows; this is what proves they were
	 * one action rather than a coincidence.
	 *
	 * @return string
	 */
	private function request_id(): string {
		static $request_id = null;

		if ( null === $request_id ) {
			$request_id = wp_generate_uuid4();
		}

		return $request_id;
	}
}
