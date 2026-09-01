<?php
/**
 * The conversion ledger, against real MySQL.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Domain\Measurement_Event_Type;
use Aggressive\Ads\Install\Schema;
use Aggressive\Ads\Repository\Conversion_Repository;
use WP_UnitTestCase;

/**
 * Why this suite and not a unit test: every assertion here is about what the
 * database does, and none of it is expressible without one. A unique key that
 * refuses a duplicate, a nullable column that stays null, and a column width
 * that does not silently truncate are all MySQL's behaviour, not PHP's.
 */
final class ConversionLedgerTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Conversion_Repository
	 */
	private Conversion_Repository $conversions;

	public function set_up(): void {
		parent::set_up();

		$this->conversions = new Conversion_Repository();
		$this->conversions->install_table();
	}

	/**
	 * One valid conversion, with only the fields a caller varies.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private function conversion( array $overrides = array() ): array {
		return array_merge(
			array(
				'definition_id'    => 7,
				'idempotency_key'  => 'order-1099-abcdef',
				'placement_id'     => 11,
				'campaign_id'      => 22,
				'creative_id'      => 33,
				'line_item_id'     => 44,
				'token_hash'       => str_repeat( 'a', 64 ),
				'attributed_event' => Measurement_Event_Type::TYPE_CLICK,
				'occurred_at_ts'   => 1700000000,
				'value_micros'     => 4990000,
				'currency'         => 'USD',
				'source'           => Conversion_Rules::SOURCE_BROWSER,
			),
			$overrides
		);
	}

	/**
	 * Rows currently in the ledger.
	 */
	private function row_count(): int {
		global $wpdb;

		$table = $this->conversions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Test assertion against this plugin's table.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	}

	public function test_the_table_installs_with_the_columns_and_indexes_the_schema_declares(): void {
		global $wpdb;

		$table = $this->conversions->table_name();

		$this->assertTrue( $this->conversions->table_exists(), 'The fixture must exist before anything is asserted about it.' );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$columns = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		sort( $columns );

		$expected = Schema::conversions_columns();
		sort( $expected );

		$this->assertSame( $expected, $columns );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$rows  = $wpdb->get_results( "SHOW INDEX FROM {$table}", ARRAY_A );
		$names = array_values( array_unique( array_column( $rows, 'Key_name' ) ) );
		sort( $names );

		$declared = Schema::conversions_index_names();
		sort( $declared );

		$this->assertSame( $declared, $names );

		/*
		 * And that the deduplication key is actually unique. The name matching
		 * proves an index exists; only `Non_unique` proves it refuses a second
		 * row. A DDL edit that dropped the word UNIQUE would keep the name, keep
		 * the columns, and quietly stop deduplicating anything.
		 */
		$unique = array_values(
			array_filter( $rows, static fn ( array $row ): bool => 'definition_key' === $row['Key_name'] )
		);

		$this->assertNotEmpty( $unique, 'The deduplication key must exist before its uniqueness means anything.' );

		foreach ( $unique as $part ) {
			$this->assertSame( '0', (string) $part['Non_unique'], 'definition_key must be a UNIQUE index.' );
		}

		$this->assertSame(
			array( 'definition_id', 'idempotency_key' ),
			array_column( $unique, 'Column_name' ),
			'Deduplication is per definition; a key over anything else counts the wrong outcomes as duplicates.'
		);
	}

	public function test_one_conversion_is_recorded(): void {
		$this->assertTrue( $this->conversions->insert( $this->conversion() ) );
		$this->assertSame( 1, $this->row_count() );
	}

	/**
	 * The deduplication guarantee, at the database rather than in PHP.
	 */
	public function test_the_same_outcome_reported_twice_counts_once(): void {
		$this->assertTrue( $this->conversions->insert( $this->conversion() ) );
		$this->assertFalse( $this->conversions->insert( $this->conversion() ) );

		$this->assertSame( 1, $this->row_count(), 'A duplicate must not add a row.' );
		$this->assertTrue( $this->conversions->exists( 7, 'order-1099-abcdef' ), 'A refused duplicate must be reported as a duplicate, not a write failure.' );
	}

	/**
	 * A retried report differing only in value is still the same outcome.
	 *
	 * The key identifies the outcome; nothing else may reopen it. Otherwise a
	 * reporter that retries with a corrected value counts the sale twice.
	 */
	public function test_a_retry_with_a_different_value_is_still_a_duplicate(): void {
		$this->assertTrue( $this->conversions->insert( $this->conversion() ) );
		$this->assertFalse( $this->conversions->insert( $this->conversion( array( 'value_micros' => 9990000 ) ) ) );

		$this->assertSame( 1, $this->row_count() );
	}

	/**
	 * **The concurrency half of deduplication, which sequential tests cannot
	 * reach.** P12 requires the duplicate refusal to survive concurrent
	 * arrival, and every other test here writes both rows through one
	 * repository in one process — which a check-then-insert implementation
	 * would also pass.
	 *
	 * A single-process suite cannot run two real requests, so this proves the
	 * two properties that make the race unwinnable instead of hoping a
	 * parallel test lands inside the window:
	 *
	 * 1. the row already present was written by something this repository
	 *    knows nothing about, so no PHP-side memory of it can be what refuses
	 *    the second write; and
	 * 2. `exists()` still classifies the refusal as a duplicate rather than an
	 *    infrastructure failure, which is what keeps the losing request
	 *    returning success to its caller instead of a retryable error.
	 *
	 * Concurrent arrival is exactly case 1: the winner's row appears between
	 * the loser's validation and its write.
	 */
	public function test_a_duplicate_written_by_another_process_is_still_refused(): void {
		global $wpdb;

		$table = $this->conversions->table_name();

		/*
		 * Deliberately not through the repository. This is the row the winning
		 * request committed while the losing one was still validating, and it
		 * differs in every column the key does not cover so that nothing but
		 * `(definition_id, idempotency_key)` can be what matches.
		 */
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Simulating a write this process did not make.
		$wpdb->insert(
			$table,
			array(
				'created_at_ts'    => 1699999999,
				'occurred_at_ts'   => 1699999998,
				'definition_id'    => 7,
				'idempotency_key'  => 'order-1099-abcdef',
				'placement_id'     => 99,
				'campaign_id'      => 98,
				'creative_id'      => 97,
				'line_item_id'     => 96,
				'token_hash'       => str_repeat( 'f', 64 ),
				'attributed_event' => Measurement_Event_Type::TYPE_VIEWABLE,
				'value_micros'     => 1,
				'currency'         => 'EUR',
				'source'           => Conversion_Rules::SOURCE_SERVER,
			),
			array( '%d', '%d', '%d', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s' )
		);

		$this->assertSame( 1, $this->row_count(), 'The fixture must be present before the refusal means anything.' );

		$this->assertFalse( $this->conversions->insert( $this->conversion() ), 'The database must refuse a key it has already seen.' );
		$this->assertSame( 1, $this->row_count(), 'A losing concurrent write must not add a row.' );
		$this->assertTrue(
			$this->conversions->exists( 7, 'order-1099-abcdef' ),
			'The loser must be able to tell a duplicate from a write failure, or it returns a retryable error for an outcome already recorded.'
		);
	}

	/**
	 * And that the refusal is the unique key rather than a read.
	 *
	 * A `exists()` check before the insert would pass every other test in this
	 * file and lose the race: two requests both read nothing, both proceed,
	 * and only the index stops the second — so if the index were ever dropped
	 * in favour of the check, nothing here would notice. Counting the queries
	 * pins the write path to one statement, which is the shape that cannot be
	 * interleaved wrongly.
	 *
	 * Asserted as a number rather than by inspection, because the number is
	 * the thing that regresses.
	 */
	public function test_the_write_path_reads_nothing_before_it_inserts(): void {
		global $wpdb;

		// Warm the memoised table_exists(): its SHOW TABLES is a
		// once-per-request cost, and counting it here would measure the
		// fixture rather than the write.
		$this->assertTrue( $this->conversions->insert( $this->conversion( array( 'idempotency_key' => 'warm-up-key' ) ) ) );

		$before = $wpdb->num_queries;

		$this->assertTrue( $this->conversions->insert( $this->conversion() ) );

		$this->assertSame( 1, $wpdb->num_queries - $before, 'One INSERT, and nothing that could decide a duplicate in PHP.' );

		$after = $wpdb->num_queries;

		$this->assertFalse( $this->conversions->insert( $this->conversion() ) );

		$this->assertSame( 1, $wpdb->num_queries - $after, 'A refused duplicate must also cost one statement: the index refuses it, no read precedes it.' );
	}

	/**
	 * **The assertion that fails if conversions are ever moved back into
	 * `aggr_events`.**
	 *
	 * One click, two definitions — a signup and a purchase. Both are real, both
	 * must count. Under `aggr_events`'s `(token_hash, event)` unique key the
	 * second would be refused as a replay, and the refusal would look exactly
	 * like correct deduplication.
	 */
	public function test_two_definitions_from_one_interaction_both_count(): void {
		$token = str_repeat( 'b', 64 );

		$this->assertTrue(
			$this->conversions->insert(
				$this->conversion(
					array(
						'definition_id'   => 1,
						'idempotency_key' => 'signup-99887766',
						'token_hash'      => $token,
					)
				)
			)
		);

		$this->assertTrue(
			$this->conversions->insert(
				$this->conversion(
					array(
						'definition_id'   => 2,
						'idempotency_key' => 'purchase-99887766',
						'token_hash'      => $token,
					)
				)
			),
			'A second definition against the same click is a second conversion, not a replay.'
		);

		$this->assertSame( 2, $this->row_count() );
	}

	/**
	 * The same key under two definitions is likewise two outcomes: an order id
	 * is unique to the shop that issued it, not across every definition.
	 */
	public function test_one_key_under_two_definitions_is_two_conversions(): void {
		$this->assertTrue( $this->conversions->insert( $this->conversion( array( 'definition_id' => 1 ) ) ) );
		$this->assertTrue( $this->conversions->insert( $this->conversion( array( 'definition_id' => 2 ) ) ) );

		$this->assertSame( 2, $this->row_count() );
	}

	/**
	 * The column-width trap, proven against the database that would spring it.
	 *
	 * `idempotency_key` is `varchar(64)`, and an over-long key must never reach
	 * it. What that costs depends on the server: outside strict mode MySQL
	 * truncates, so two different outcomes collide on the unique index and the
	 * second is lost as a duplicate; under strict mode it raises a data error
	 * instead. One is a silent undercount and the other is a failed report, and
	 * neither is acceptable — which is why the domain refuses the key before a
	 * query is built.
	 *
	 * **`last_error` is the assertion that makes this test mean anything.**
	 * Asserting only that `insert()` returned false proves nothing here: with
	 * the guard deleted, strict mode returns false too, and the test passes over
	 * the missing guard. Written the first way it did exactly that. An empty
	 * `last_error` is the difference between "refused before the query" and
	 * "the database refused it for us", and only the first is a guarantee this
	 * plugin controls.
	 */
	public function test_an_over_long_key_is_refused_before_it_reaches_the_database(): void {
		global $wpdb;

		$base = str_repeat( 'k', 64 );

		foreach ( array( $base . 'A', $base . 'B' ) as $key ) {
			$wpdb->last_error = '';

			$this->assertFalse( $this->conversions->insert( $this->conversion( array( 'idempotency_key' => $key ) ) ) );
			$this->assertSame( '', $wpdb->last_error, 'The key must be refused in PHP, not survive as far as a MySQL error.' );
		}

		$this->assertSame( 0, $this->row_count(), 'Neither may reach the table, truncated or otherwise.' );

		// And the key at exactly the width still works, so the guard is a
		// boundary rather than a blanket refusal.
		$this->assertTrue( $this->conversions->insert( $this->conversion( array( 'idempotency_key' => $base ) ) ) );
		$this->assertSame( 1, $this->row_count() );
	}

	/**
	 * Malformed conversions never reach the ledger.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function refusals(): array {
		return array(
			'no definition'            => array( array( 'definition_id' => 0 ) ),
			'a served ad, not a click' => array( array( 'attributed_event' => Measurement_Event_Type::TYPE_SERVED ) ),
			'an unknown interaction'   => array( array( 'attributed_event' => 'purchase' ) ),
			'a short key'              => array( array( 'idempotency_key' => 'abc' ) ),
			'a key with a space'       => array( array( 'idempotency_key' => 'order 1099 abc' ) ),
			'a key with a newline'     => array( array( 'idempotency_key' => "order-1099-abcdef\n" ) ),
			'a malformed token'        => array( array( 'token_hash' => 'not-a-digest' ) ),
			'an uppercase token'       => array( array( 'token_hash' => str_repeat( 'A', 64 ) ) ),
			'a negative value'         => array( array( 'value_micros' => -1 ) ),
			'a bogus currency'         => array( array( 'currency' => 'dollars' ) ),
			'an unknown source'        => array( array( 'source' => 'server' ) ),
			'no occurrence time'       => array( array( 'occurred_at_ts' => 0 ) ),
		);
	}

	/**
	 * Asserts one malformed conversion is refused.
	 *
	 * @dataProvider refusals
	 *
	 * @param array<string, mixed> $overrides The one thing wrong with it.
	 */
	public function test_a_malformed_conversion_is_refused( array $overrides ): void {
		global $wpdb;

		$wpdb->last_error = '';

		$this->assertFalse( $this->conversions->insert( $this->conversion( $overrides ) ) );
		$this->assertSame( 0, $this->row_count() );

		// Refused by this plugin, not by the database happening to disagree
		// with the value. See the note on the over-long key test.
		$this->assertSame( '', $wpdb->last_error );
	}

	/**
	 * A valueless conversion is a real one. A signup has no money attached, and
	 * an empty currency is how that is stored.
	 */
	public function test_a_valueless_conversion_is_recorded(): void {
		$this->assertTrue(
			$this->conversions->insert(
				$this->conversion(
					array(
						'value_micros' => 0,
						'currency'     => '',
					)
				)
			)
		);

		$this->assertSame( 1, $this->row_count() );
	}

	/**
	 * Counting is by occurrence, not receipt.
	 *
	 * A server-to-server report that arrives days late describes an outcome
	 * that happened when it happened. Counting by `created_at_ts` would move a
	 * Monday purchase into the day the reporter got around to sending it.
	 */
	public function test_conversions_count_on_the_day_they_occurred(): void {
		$monday = (int) strtotime( '2026-03-02 09:00:00 UTC' );

		$this->assertTrue(
			$this->conversions->insert(
				$this->conversion(
					array(
						'idempotency_key' => 'monday-00000001',
						'occurred_at_ts'  => $monday,
					)
				)
			)
		);

		$this->assertSame( 1, $this->conversions->count_for_campaign_day( 22, '2026-03-02' ) );
		$this->assertSame( 0, $this->conversions->count_for_campaign_day( 22, '2026-03-03' ), 'Receipt day must not be the reported day.' );
		$this->assertSame( 0, $this->conversions->count_for_campaign_day( 999, '2026-03-02' ), 'Another campaign must not see it.' );
	}

	/**
	 * The rollup column exists and stays NULL on a row nothing has projected.
	 *
	 * NULL is the whole reporting distinction, one phase after viewability made
	 * the same one: a day before conversions were measured did not convert
	 * nobody, nobody was counting.
	 */
	public function test_the_rollup_conversions_column_defaults_to_null(): void {
		global $wpdb;

		$rollups = $wpdb->prefix . Schema::ROLLUPS_TABLE;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Schema introspection in a test.
		$row = $wpdb->get_row( "SHOW COLUMNS FROM {$rollups} LIKE 'conversions'", ARRAY_A );

		$this->assertIsArray( $row, 'The column must exist before its nullability means anything.' );
		$this->assertSame( 'YES', $row['Null'] );
		$this->assertNull( $row['Default'] );
	}
}
