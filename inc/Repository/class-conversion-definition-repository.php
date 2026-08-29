<?php
/**
 * Conversion definitions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Repository;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Install\Schema;

/**
 * The only code that touches aggr_conversion_definitions.
 */
final class Conversion_Definition_Repository {

	/**
	 * A site cannot hold more definitions than this.
	 *
	 * Not a licensing limit. The public reporting endpoint resolves a definition
	 * on every request, and staff screens list them unpaged; a bound here means
	 * neither has to grow a pagination story to stay safe. Two hundred is far
	 * past any real publisher's set.
	 */
	public const MAX_DEFINITIONS = 200;

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

		return $wpdb->prefix . Schema::CONVERSION_DEFINITIONS_TABLE;
	}

	/**
	 * Creates or repairs the definitions table.
	 */
	public function install_table(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( Schema::conversion_definitions_table_ddl( $this->table_name(), $wpdb->get_charset_collate() ) );

		$this->table_exists = null;
	}

	/**
	 * Whether the definitions table exists.
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
	 * Drops the definitions table. Uninstall only.
	 */
	public function drop_table(): void {
		global $wpdb;

		$table = $this->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Dropping this plugin's own table on uninstall.
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );

		$this->table_exists = false;
	}

	/**
	 * Creates one definition and returns its id, or 0.
	 *
	 * The public key is minted here rather than supplied, because a caller that
	 * could choose it could choose one it had already seen elsewhere.
	 *
	 * @param array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string} $fields Validated definition.
	 */
	public function create( array $fields ): int {
		global $wpdb;

		if ( ! $this->table_exists() || $this->count() >= self::MAX_DEFINITIONS ) {
			return 0;
		}

		$now = time();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Custom table; $wpdb->insert with a format array is the write path.
		$written = $wpdb->insert(
			$this->table_name(),
			array(
				'created_at_ts'        => $now,
				'updated_at_ts'        => $now,
				'org_id'               => $fields['org_id'],
				'public_key'           => bin2hex( random_bytes( 16 ) ),
				'name'                 => $fields['name'],
				'window_seconds'       => $fields['window_seconds'],
				'default_value_micros' => $fields['default_value_micros'],
				'currency'             => $fields['currency'],
				'allow_s2s'            => $fields['allow_s2s'] ? 1 : 0,
				'status'               => $fields['status'],
				'revision'             => 1,
			),
			array( '%d', '%d', '%d', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%d' )
		);

		return false === $written ? 0 : (int) $wpdb->insert_id;
	}

	/**
	 * Updates one definition, refusing a stale write.
	 *
	 * The revision is part of the WHERE clause rather than checked first,
	 * because a read-then-write would be a race: two staff saving the same
	 * definition would both read revision 4 and both believe they were current.
	 *
	 * @param int                                                                                                                                 $id                Definition id.
	 * @param array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string} $fields            Validated definition.
	 * @param int                                                                                                                                 $expected_revision Revision the caller last read.
	 * @return bool True when exactly one row moved.
	 */
	public function update( int $id, array $fields, int $expected_revision ): bool {
		global $wpdb;

		if ( $id <= 0 || $expected_revision <= 0 || ! $this->table_exists() ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Custom table; conditional update is the optimistic-concurrency write.
		$written = $wpdb->query(
			$wpdb->prepare(
				'UPDATE %i SET updated_at_ts = %d, org_id = %d, name = %s, window_seconds = %d, default_value_micros = %d, currency = %s, allow_s2s = %d, status = %s, revision = revision + 1 WHERE id = %d AND revision = %d',
				$this->table_name(),
				time(),
				$fields['org_id'],
				$fields['name'],
				$fields['window_seconds'],
				$fields['default_value_micros'],
				$fields['currency'],
				$fields['allow_s2s'] ? 1 : 0,
				$fields['status'],
				$id,
				$expected_revision
			)
		);

		return 1 === $written;
	}

	/**
	 * One definition by id, or null.
	 *
	 * @param int $id Definition id.
	 * @return array<string, mixed>|null
	 */
	public function find( int $id ): ?array {
		global $wpdb;

		if ( $id <= 0 || ! $this->table_exists() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single indexed read on this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d LIMIT 1', $this->table_name(), $id ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * One definition by its public key, or null.
	 *
	 * The lookup the public reporting endpoint makes. The key's shape is
	 * checked before the query so a malformed one costs no database round trip
	 * at all — the endpoint is unauthenticated, and the cheapest refusal is the
	 * one that never reaches MySQL.
	 *
	 * @param string $public_key 32-hex identifier.
	 * @return array<string, mixed>|null
	 */
	public function find_by_public_key( string $public_key ): ?array {
		global $wpdb;

		if ( ! Conversion_Definition::is_valid_public_key( $public_key ) || ! $this->table_exists() ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Single unique-key read on this plugin's table.
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE public_key = %s LIMIT 1', $this->table_name(), $public_key ), ARRAY_A );

		return is_array( $row ) ? self::shape( $row ) : null;
	}

	/**
	 * Every definition, newest first.
	 *
	 * Unpaged on purpose, and safe because `MAX_DEFINITIONS` bounds the table.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function all(): array {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return array();
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded read of this plugin's table; MAX_DEFINITIONS caps it.
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $this->table_name(), self::MAX_DEFINITIONS ), ARRAY_A );

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_values( array_map( static fn ( array $row ): array => self::shape( $row ), $rows ) );
	}

	/**
	 * How many definitions exist.
	 */
	public function count(): int {
		global $wpdb;

		if ( ! $this->table_exists() ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bounded count on this plugin's table.
		$count = $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', $this->table_name() ) );

		return is_numeric( $count ) ? (int) $count : 0;
	}

	/**
	 * Gives a raw row its types.
	 *
	 * `$wpdb` returns every column as a string. Handing that to a caller that
	 * compares an org id with `===` is how a tenancy check silently stops
	 * matching, so the shaping happens once, here.
	 *
	 * @param array<string, mixed> $row Raw row.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		return array(
			'id'                   => (int) ( $row['id'] ?? 0 ),
			'created_at_ts'        => (int) ( $row['created_at_ts'] ?? 0 ),
			'updated_at_ts'        => (int) ( $row['updated_at_ts'] ?? 0 ),
			'org_id'               => (int) ( $row['org_id'] ?? 0 ),
			'public_key'           => (string) ( $row['public_key'] ?? '' ),
			'name'                 => (string) ( $row['name'] ?? '' ),
			'window_seconds'       => (int) ( $row['window_seconds'] ?? 0 ),
			'default_value_micros' => (int) ( $row['default_value_micros'] ?? 0 ),
			'currency'             => (string) ( $row['currency'] ?? '' ),
			'allow_s2s'            => ! empty( $row['allow_s2s'] ),
			'status'               => (string) ( $row['status'] ?? '' ),
			'revision'             => (int) ( $row['revision'] ?? 0 ),
		);
	}
}
