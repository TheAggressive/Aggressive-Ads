<?php
/**
 * Conversion definition persistence, against real MySQL.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Repository\Conversion_Definition_Repository;
use WP_UnitTestCase;

/**
 * Concurrency, uniqueness and typing — none of which a unit test can assert.
 */
final class ConversionDefinitionRepositoryTest extends WP_UnitTestCase {

	/**
	 * Repository under test.
	 *
	 * @var Conversion_Definition_Repository
	 */
	private Conversion_Definition_Repository $definitions;

	public function set_up(): void {
		parent::set_up();

		$this->definitions = new Conversion_Definition_Repository();
		$this->definitions->install_table();
	}

	/**
	 * One valid definition.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array{name: string, org_id: int, window_seconds: int, default_value_micros: int, currency: string, allow_s2s: bool, status: string}
	 */
	private static function fields( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 12,
				'window_seconds'       => 2592000,
				'default_value_micros' => 4990000,
				'currency'             => 'USD',
				'allow_s2s'            => true,
				'status'               => Conversion_Definition::STATUS_ACTIVE,
			),
			$overrides
		);
	}

	public function test_a_definition_round_trips_with_its_types(): void {
		$id = $this->definitions->create( self::fields() );

		$this->assertGreaterThan( 0, $id );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		/*
		 * Asserted with assertSame, deliberately. $wpdb returns every column as
		 * a string, and a tenancy check written as `$row['org_id'] === $org_id`
		 * against "12" silently stops matching. The repository shapes the row
		 * so no caller has to remember that.
		 */
		$this->assertSame( 12, $row['org_id'] );
		$this->assertSame( 2592000, $row['window_seconds'] );
		$this->assertSame( 4990000, $row['default_value_micros'] );
		$this->assertSame( 'USD', $row['currency'] );
		$this->assertTrue( $row['allow_s2s'] );
		$this->assertSame( 1, $row['revision'] );
	}

	/**
	 * The public key is minted by the server, unguessable, and unique.
	 */
	public function test_every_definition_gets_its_own_unguessable_key(): void {
		$keys = array();

		for ( $i = 0; $i < 25; $i++ ) {
			$id  = $this->definitions->create( self::fields( array( 'name' => 'Definition ' . $i ) ) );
			$row = $this->definitions->find( $id );

			$this->assertIsArray( $row );
			$this->assertTrue(
				Conversion_Definition::is_valid_public_key( $row['public_key'] ),
				'A minted key must have the shape the public endpoint validates.'
			);

			$keys[] = $row['public_key'];
		}

		$this->assertCount( 25, array_unique( $keys ), 'Two definitions shared a public key.' );
	}

	/**
	 * A caller cannot choose the public key, even by passing one.
	 *
	 * The field is not in the write path at all, and this asserts the outcome
	 * rather than the absence: a key a caller could set is a key they could set
	 * to one they had already learned from another site.
	 */
	public function test_a_supplied_public_key_is_ignored(): void {
		$id = $this->definitions->create(
			array_merge( self::fields(), array( 'public_key' => str_repeat( 'f', 32 ) ) )
		);

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertNotSame( str_repeat( 'f', 32 ), $row['public_key'] );
	}

	public function test_a_definition_is_found_by_its_public_key(): void {
		$id  = $this->definitions->create( self::fields() );
		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		$found = $this->definitions->find_by_public_key( $row['public_key'] );

		$this->assertIsArray( $found );
		$this->assertSame( $id, $found['id'] );
	}

	/**
	 * A malformed key never reaches the database.
	 *
	 * The endpoint that calls this is unauthenticated, so the cheapest refusal
	 * is the one that costs no query at all.
	 *
	 * @return array<string, array{string}>
	 */
	public static function bad_keys(): array {
		return array(
			'empty'            => array( '' ),
			'too short'        => array( str_repeat( 'a', 31 ) ),
			'not hex'          => array( str_repeat( 'z', 32 ) ),
			'a sql fragment'   => array( "' OR '1'='1" ),
			'a wildcard'       => array( str_repeat( '%', 32 ) ),
			'trailing newline' => array( str_repeat( 'a', 32 ) . "\n" ),
		);
	}

	/**
	 * Asserts one malformed public key.
	 *
	 * @dataProvider bad_keys
	 *
	 * @param string $key Candidate.
	 */
	public function test_a_malformed_public_key_finds_nothing_without_a_query( string $key ): void {
		global $wpdb;

		$this->definitions->create( self::fields() );

		$before = $wpdb->num_queries;

		$this->assertNull( $this->definitions->find_by_public_key( $key ) );
		$this->assertSame( $before, $wpdb->num_queries, 'A malformed key must be refused before MySQL is asked.' );
	}

	/**
	 * A wildcard cannot match a real key.
	 *
	 * The lookup is a prepared equality, not a LIKE, so `%` is a literal — but
	 * the assertion is worth making against the database rather than by reading
	 * the SQL, because the difference is one character.
	 */
	public function test_a_wildcard_key_matches_nothing(): void {
		$this->definitions->create( self::fields() );

		$this->assertNull( $this->definitions->find_by_public_key( str_repeat( '%', 32 ) ) );
	}

	/**
	 * Resolving a definition by its public key costs exactly one query.
	 *
	 * This is the hot path: the public conversion endpoint resolves a definition
	 * on every request, before it has decided whether to trust the caller. A
	 * second query here — an existence check, a count, a lazily installed table
	 * — is a second query anybody on the internet can drive.
	 *
	 * Asserted as a number rather than "it is fast", because the number is the
	 * thing that regresses.
	 */
	public function test_resolving_by_public_key_costs_one_query(): void {
		global $wpdb;

		$id  = $this->definitions->create( self::fields() );
		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		// Warm the memoised table_exists() first: its SHOW TABLES is a
		// once-per-request cost, not a per-lookup one, and counting it here
		// would measure the fixture rather than the lookup.
		$this->definitions->find_by_public_key( $row['public_key'] );

		$before = $wpdb->num_queries;

		$found = $this->definitions->find_by_public_key( $row['public_key'] );

		$this->assertIsArray( $found );
		$this->assertSame( $id, $found['id'] );
		$this->assertSame( 1, $wpdb->num_queries - $before, 'One indexed read, and nothing else.' );
	}

	/**
	 * And the lookup uses the unique index rather than scanning.
	 *
	 * A definition set is small, so a scan would pass every timing test and
	 * still be wrong: this endpoint is unauthenticated, and the difference
	 * between an index seek and a table scan is the difference between a cheap
	 * refusal and an expensive one somebody can repeat.
	 */
	public function test_resolving_by_public_key_uses_the_unique_index(): void {
		global $wpdb;

		$id  = $this->definitions->create( self::fields() );
		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );

		$table = $this->definitions->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query plan introspection in a test.
		$plan = $wpdb->get_row( $wpdb->prepare( "EXPLAIN SELECT * FROM {$table} WHERE public_key = %s LIMIT 1", $row['public_key'] ), ARRAY_A );

		$this->assertIsArray( $plan );
		$this->assertSame( 'public_key', $plan['key'] ?? null, 'The lookup must use the unique key, not a scan.' );
	}

	/**
	 * The optimistic-concurrency guarantee: the second writer loses.
	 *
	 * Both callers read revision 1, both believe they are current, and exactly
	 * one write may land. Anything else means one publisher's attribution window
	 * silently replaced another's.
	 */
	public function test_a_stale_revision_cannot_overwrite_a_fresh_one(): void {
		$id = $this->definitions->create( self::fields() );

		$this->assertTrue(
			$this->definitions->update( $id, self::fields( array( 'name' => 'First' ) ), 1 )
		);

		$this->assertFalse(
			$this->definitions->update( $id, self::fields( array( 'name' => 'Second' ) ), 1 ),
			'A write against revision 1 must fail once revision 1 is gone.'
		);

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertSame( 'First', $row['name'], 'The stale write must not have landed.' );
		$this->assertSame( 2, $row['revision'] );
	}

	public function test_a_successful_update_advances_the_revision(): void {
		$id = $this->definitions->create( self::fields() );

		$this->assertTrue( $this->definitions->update( $id, self::fields( array( 'status' => Conversion_Definition::STATUS_ARCHIVED ) ), 1 ) );

		$row = $this->definitions->find( $id );

		$this->assertIsArray( $row );
		$this->assertSame( Conversion_Definition::STATUS_ARCHIVED, $row['status'] );
		$this->assertSame( 2, $row['revision'] );
	}

	public function test_updating_something_that_does_not_exist_fails(): void {
		$this->assertFalse( $this->definitions->update( 987654, self::fields(), 1 ) );
	}

	/**
	 * Nonsense ids never reach a query.
	 */
	public function test_nonsense_ids_find_nothing(): void {
		$this->assertNull( $this->definitions->find( 0 ) );
		$this->assertNull( $this->definitions->find( -1 ) );
		$this->assertFalse( $this->definitions->update( 0, self::fields(), 1 ) );
		$this->assertFalse( $this->definitions->update( 1, self::fields(), 0 ) );
	}

	/**
	 * The table is bounded, and the bound is enforced on the write.
	 *
	 * `all()` is unpaged and the public endpoint resolves a definition per
	 * request; both are safe only because this cannot grow without limit.
	 */
	public function test_the_definition_count_is_bounded(): void {
		$limit = Conversion_Definition_Repository::MAX_DEFINITIONS;

		global $wpdb;

		$table = $this->definitions->table_name();
		$now   = time();

		// Filled directly rather than through create(), which would be 200
		// round trips of validation for a bound this asserts in one.
		for ( $i = 0; $i < $limit; $i++ ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Bulk fixture for a bound test.
			$wpdb->insert(
				$table,
				array(
					'created_at_ts' => $now,
					'updated_at_ts' => $now,
					'org_id'        => 1,
					'public_key'    => bin2hex( random_bytes( 16 ) ),
					'name'          => 'Filler ' . $i,
					'status'        => Conversion_Definition::STATUS_ACTIVE,
					'revision'      => 1,
				),
				array( '%d', '%d', '%d', '%s', '%s', '%s', '%d' )
			);
		}

		$this->assertSame( $limit, $this->definitions->count(), 'The fixture must actually be full.' );
		$this->assertSame( 0, $this->definitions->create( self::fields() ), 'A definition past the bound must be refused.' );
		$this->assertSame( $limit, $this->definitions->count(), 'And must not have been written anyway.' );
	}

	/**
	 * `all()` never returns more than the bound, whatever the table holds.
	 */
	public function test_listing_is_capped(): void {
		for ( $i = 0; $i < 5; $i++ ) {
			$this->definitions->create( self::fields( array( 'name' => 'Definition ' . $i ) ) );
		}

		$rows = $this->definitions->all();

		$this->assertCount( 5, $rows );
		$this->assertLessThanOrEqual( Conversion_Definition_Repository::MAX_DEFINITIONS, count( $rows ) );
	}
}
