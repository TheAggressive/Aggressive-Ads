<?php
/**
 * SQL for the Phase 0 identity rewrite.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Identity_Maps;
use RuntimeException;

/**
 * Rewrites stored LAAO identifiers onto Aggressive Ads names.
 *
 * Every statement is idempotent: a second run updates zero rows. Table
 * identifiers are validated before interpolation because MySQL cannot bind
 * them as parameters.
 */
final class Identity_Rewrite {

	/**
	 * Rewrites custom post types on wp_posts.
	 *
	 * @return void
	 */
	public function rewrite_post_types(): void {
		global $wpdb;

		foreach ( Identity_Maps::post_types() as $from => $to ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time identity migration; no object cache represents post_type as a set to rewrite.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_type = %s WHERE post_type = %s",
					$to,
					$from
				)
			);
		}
	}

	/**
	 * Rewrites campaign statuses on wp_posts.
	 *
	 * @return void
	 */
	public function rewrite_post_statuses(): void {
		global $wpdb;

		foreach ( Identity_Maps::statuses() as $from => $to ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time identity migration.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_status = %s WHERE post_status = %s",
					$to,
					$from
				)
			);
		}
	}

	/**
	 * Rewrites plugin meta keys.
	 *
	 * @return void
	 */
	public function rewrite_meta_keys(): void {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Prefix rewrite of every plugin meta key; there is no WordPress API for a bulk meta_key rename.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_key = REPLACE( meta_key, %s, %s ) WHERE meta_key LIKE %s",
				Identity_Maps::LEGACY_META_PREFIX,
				Identity_Maps::META_PREFIX,
				$wpdb->esc_like( Identity_Maps::LEGACY_META_PREFIX ) . '%'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Same rewrite for user meta (email-change tokens, membership).
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->usermeta} SET meta_key = REPLACE( meta_key, %s, %s ) WHERE meta_key LIKE %s",
				Identity_Maps::LEGACY_META_PREFIX,
				Identity_Maps::META_PREFIX,
				$wpdb->esc_like( Identity_Maps::LEGACY_META_PREFIX ) . '%'
			)
		);
	}

	/**
	 * Renames custom tables when the old name still exists and the new one does not.
	 *
	 * @return void
	 */
	public function rename_tables(): void {
		global $wpdb;

		foreach ( Identity_Maps::tables() as $from => $to ) {
			$old = $wpdb->prefix . $from;
			$new = $wpdb->prefix . $to;

			$this->assert_safe_identifier( $old );
			$this->assert_safe_identifier( $new );

			// Activation of new code against an old database can dbDelta the
			// new name first, leaving an empty table beside the populated
			// legacy one. RENAME then refuses. Dropping the empty shell is
			// the recovery; a non-empty destination is left alone.
			if ( $this->table_exists( $old ) && $this->table_exists( $new ) && $this->table_is_empty( $new ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Empty destination only; name validated.
				$wpdb->query( "DROP TABLE `{$new}`" );
			}

			if ( $this->table_exists( $old ) && ! $this->table_exists( $new ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- RENAME TABLE cannot bind identifiers; both names were validated.
				$wpdb->query( "RENAME TABLE `{$old}` TO `{$new}`" );
			}
		}
	}

	/**
	 * Copies mapped option rows onto the new keys, then deletes the old ones.
	 *
	 * Also rewrites leftover options still carrying the previous prefix, and
	 * matching transients, so a rate-limit row cannot outlive the prefix that
	 * created it.
	 *
	 * @return void
	 */
	public function rewrite_options(): void {
		global $wpdb;

		foreach ( Identity_Maps::option_keys() as $from => $to ) {
			$this->move_option_row( $from, $to );
		}

		$patterns = array(
			$wpdb->esc_like( Identity_Maps::LEGACY_OPTION_PREFIX ) . '%',
			$wpdb->esc_like( '_transient_' . Identity_Maps::LEGACY_OPTION_PREFIX ) . '%',
			$wpdb->esc_like( '_transient_timeout_' . Identity_Maps::LEGACY_OPTION_PREFIX ) . '%',
		);

		foreach ( $patterns as $like ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Leftover prefix sweep after the mapped keys have moved.
			$names = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
					$like
				)
			);

			if ( ! is_array( $names ) ) {
				continue;
			}

			foreach ( $names as $from ) {
				if ( ! is_string( $from ) || '' === $from ) {
					continue;
				}

				$to = str_replace( Identity_Maps::LEGACY_OPTION_PREFIX, Identity_Maps::OPTION_PREFIX, $from );
				$this->move_option_row( $from, $to );
			}
		}
	}

	/**
	 * Drops leftover tables that still carry the previous prefix.
	 *
	 * Used on uninstall so a site that never finished migration does not leave
	 * the audit log behind under a name nothing will drop.
	 *
	 * @return void
	 */
	public function drop_legacy_tables(): void {
		global $wpdb;

		foreach ( array_keys( Identity_Maps::tables() ) as $unprefixed ) {
			$table = $wpdb->prefix . $unprefixed;
			$this->assert_safe_identifier( $table );

			if ( ! $this->table_exists( $table ) ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Uninstall of a leftover table; name validated.
			$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
	}

	/**
	 * Moves one option row, preserving autoload, skipping if the destination exists.
	 *
	 * @param string $from Source option name.
	 * @param string $to   Destination option name.
	 * @return void
	 */
	private function move_option_row( string $from, string $to ): void {
		global $wpdb;

		if ( $from === $to ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Existence check; get_option cannot distinguish missing from stored false.
		$from_row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT option_value, autoload FROM {$wpdb->options} WHERE option_name = %s",
				$from
			),
			ARRAY_A
		);

		if ( ! is_array( $from_row ) ) {
			return;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Destination existence.
		$to_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_id FROM {$wpdb->options} WHERE option_name = %s",
				$to
			)
		);

		if ( null === $to_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- One-time option rename; wp_cache is invalidated below.
			$wpdb->insert(
				$wpdb->options,
				array(
					'option_name'  => $to,
					'option_value' => $from_row['option_value'],
					'autoload'     => $from_row['autoload'],
				),
				array( '%s', '%s', '%s' )
			);
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Delete the source after the copy.
		$wpdb->delete( $wpdb->options, array( 'option_name' => $from ), array( '%s' ) );

		wp_cache_delete( $from, 'options' );
		wp_cache_delete( $to, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}

	/**
	 * Whether a fully prefixed table exists and contains no rows.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_is_empty( string $table ): bool {
		global $wpdb;

		$this->assert_safe_identifier( $table );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- COUNT of a validated identifier; used only to decide whether a destination shell is safe to drop.
		$count = $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" );

		return is_numeric( $count ) && 0 === (int) $count;
	}

	/**
	 * Whether a fully prefixed table exists.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private function table_exists( string $table ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Refuses to interpolate anything that is not a legal table identifier.
	 *
	 * @param string $identifier Candidate.
	 * @return void
	 *
	 * @throws RuntimeException When the identifier is not a legal table name.
	 */
	private function assert_safe_identifier( string $identifier ): void {
		if ( 1 !== preg_match( '/^[A-Za-z0-9_]+$/', $identifier ) ) {
			throw new RuntimeException( 'Refusing to interpolate an unsafe table identifier.' );
		}
	}
}
