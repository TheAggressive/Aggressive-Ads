<?php
/**
 * Attributed conversions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Domain\Measurement_Rules;
use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_conversions.
 *
 * A conversion is its own ledger rather than another `aggr_events` row, for the
 * reason recorded on `Schema::conversions_table_ddl()`: that table's
 * `(token_hash, event)` unique key would allow exactly one conversion per fill
 * for all time. Here the unique key is `(definition_id, idempotency_key)`, so
 * two definitions from one click both count and the same outcome reported twice
 * does not.
 */
final class Conversion_Repository {

	/**
	 * Fully prefixed table name.
	 */
	public function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . Schema::CONVERSIONS_TABLE;
	}

	/**
	 * Creates or repairs the conversions table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::conversions_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );
	}

	/**
	 * Whether the conversions table exists.
	 */
	public function table_exists(): bool {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema introspection on a custom table; caching existence is how a failed install looks healthy.
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );

		return $found === $table;
	}

	/**
	 * Drops the conversions table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	/**
	 * Records one attributed conversion. A duplicate outcome returns false.
	 *
	 * Every shape is checked before the write rather than after it. The unique
	 * key refuses a duplicate key, but only once MySQL has decided what the key
	 * is — and outside strict mode an over-long `idempotency_key` is truncated
	 * to `varchar(64)` rather than refused, which would collapse two different
	 * outcomes onto one key and refuse the second as a replay. Validating here
	 * is what stops a silent undercount.
	 *
	 * @param array{definition_id: int, idempotency_key: string, placement_id: int, campaign_id: int, creative_id: int, line_item_id: int, token_hash: string, attributed_event: string, occurred_at_ts: int, value_micros?: int, currency?: string, source: string} $conversion Validated conversion facts, every one resolved server-side.
	 */
	public function insert( array $conversion ): bool {
		global $wpdb;

		$definition_id = (int) $conversion['definition_id'];
		$key           = (string) $conversion['idempotency_key'];
		$token_hash    = (string) $conversion['token_hash'];
		$event         = (string) $conversion['attributed_event'];
		$occurred      = (int) $conversion['occurred_at_ts'];
		$value         = (int) ( $conversion['value_micros'] ?? 0 );
		$currency      = (string) ( $conversion['currency'] ?? '' );
		$source        = (string) $conversion['source'];

		if ( $definition_id <= 0 || ! Conversion_Rules::is_valid_idempotency_key( $key ) ) {
			return false;
		}

		if ( ! Measurement_Rules::is_valid_token_hash( $token_hash ) || ! Conversion_Rules::is_attributable_event( $event ) ) {
			return false;
		}

		if ( ! Conversion_Rules::is_valid_source( $source ) || ! Conversion_Rules::is_valid_value_micros( $value ) ) {
			return false;
		}

		// An empty currency is how a valueless conversion — a signup — is
		// stored. A non-empty one must be a real code, so a typo cannot reach
		// a report as though it were a currency.
		if ( '' !== $currency && ! Conversion_Rules::is_valid_currency( $currency ) ) {
			return false;
		}

		if ( $occurred <= 0 ) {
			return false;
		}

		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; $wpdb->insert with a format array is the write path. Duplicate (definition_id, idempotency_key) is the deduplication refusal.
			$written = $wpdb->insert(
				$this->table_name(),
				array(
					'created_at_ts'    => time(),
					'occurred_at_ts'   => $occurred,
					'definition_id'    => $definition_id,
					'idempotency_key'  => $key,
					'placement_id'     => (int) $conversion['placement_id'],
					'campaign_id'      => (int) $conversion['campaign_id'],
					'creative_id'      => (int) $conversion['creative_id'],
					'line_item_id'     => (int) $conversion['line_item_id'],
					'token_hash'       => $token_hash,
					'attributed_event' => $event,
					'value_micros'     => $value,
					'currency'         => $currency,
					'source'           => $source,
				),
				array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
			);
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		return false !== $written;
	}

	/**
	 * Whether this outcome has already been recorded.
	 *
	 * Used only to tell an expected duplicate apart from an infrastructure
	 * write failure after insert() returns false — never as a check before an
	 * insert, which would be a race with no unique key behind it.
	 *
	 * @param int    $definition_id   Conversion definition id.
	 * @param string $idempotency_key Reporter-supplied key.
	 */
	public function exists( int $definition_id, string $idempotency_key ): bool {
		global $wpdb;

		if ( $definition_id <= 0 || ! Conversion_Rules::is_valid_idempotency_key( $idempotency_key ) ) {
			return false;
		}

		$table = $this->table_name();

		$was_suppressing = $wpdb->suppress_errors( true );

		try {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Exact unique-key diagnostic after a failed ledger insert.
			$id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE definition_id = %d AND idempotency_key = %s LIMIT 1", $definition_id, $idempotency_key ) );
		} finally {
			$wpdb->suppress_errors( $was_suppressing );
		}

		return is_numeric( $id ) && (int) $id > 0;
	}

	/**
	 * Conversions recorded for one campaign on one UTC day.
	 *
	 * Counted by `occurred_at_ts`, not receipt: a server-to-server report that
	 * arrives on Thursday describing a Monday purchase belongs in Monday's
	 * total, or the report moves depending on when the reporter got around to
	 * sending it.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $day_utc     UTC day, Y-m-d.
	 */
	public function count_for_campaign_day( int $campaign_id, string $day_utc ): int {
		global $wpdb;

		if ( $campaign_id <= 0 || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day_utc ) ) {
			return 0;
		}

		$start = strtotime( $day_utc . ' 00:00:00 UTC' );

		if ( false === $start ) {
			return 0;
		}

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Bounded count on this plugin's table, over the campaign_day index.
		$count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND occurred_at_ts >= %d AND occurred_at_ts < %d", $campaign_id, $start, $start + 86400 ) );

		return is_numeric( $count ) ? (int) $count : 0;
	}
}
