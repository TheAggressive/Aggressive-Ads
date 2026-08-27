<?php
/**
 * The read contract P3 will consume.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use WP_UnitTestCase;

/**
 * The promises P3 is allowed to rely on, asserted before it relies on them.
 *
 * A read contract is only a contract if the guarantees are checked: one query
 * whatever the row count, no postmeta joins, a deterministic order, and only
 * rows that may actually deliver. Each is cheap to break and none announces
 * itself — a second query per candidate looks like nothing until a slot has
 * five hundred of them.
 */
final class CandidateReadContractTest extends WP_UnitTestCase {

	private const NOW = 1_700_000_000;

	/**
	 * A placement id unique to each test.
	 *
	 * Not a constant, because one test rebuilds the table to read its query
	 * plan and DDL implicit-commits in MySQL — which commits every row the
	 * suite's transaction was going to roll back. Shared rows then leak between
	 * tests and quietly satisfy assertions about ordering and limits. Giving
	 * each test its own placement makes the reads disjoint whatever survives.
	 *
	 * @var int
	 */
	private int $placement = 0;

	/**
	 * Assignment persistence.
	 *
	 * @var Creative_Assignment_Repository
	 */
	private Creative_Assignment_Repository $assignments;

	public function set_up(): void {
		parent::set_up();

		$this->assignments = Plugin::instance()->container()->get( Creative_Assignment_Repository::class );
		$this->assignments->install_table();

		static $next     = 90000;
		$this->placement = ++$next;
	}

	/**
	 * Inserts one assignment row directly.
	 *
	 * Written straight to the table rather than through the migrator: this is a
	 * test of the read, and building each row through the whole creative
	 * pipeline would make a thousand-row fixture take minutes.
	 *
	 * @param array<string, mixed> $overrides Column overrides.
	 * @return int Inserted id.
	 */
	private function assignment( array $overrides = array() ): int {
		global $wpdb;

		$row = array_merge(
			array(
				'line_item_id' => 7,
				'campaign_id'  => 11,
				'placement_id' => $this->placement,
				'revision_id'  => 21,
				'status'       => Assignment_Rules::LIVE,
				'weight'       => 100,
				'start_at_ts'  => 0,
				'end_at_ts'    => 0,
				'click_url'    => 'https://example.com/a',
				'alt_text'     => 'Candidate',
				'width'        => 728,
				'height'       => 90,
				'revision'     => 1,
			),
			$overrides
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Test fixture for this plugin's own table.
		$wpdb->insert( $this->assignments->table_name(), $row );

		return (int) $wpdb->insert_id;
	}

	/** Ids the candidate query returns. */
	private function candidates( int $now = self::NOW ): array {
		return array_map(
			static fn ( array $row ): int => (int) $row['id'],
			$this->assignments->candidates_for_placement( $this->placement, $now )
		);
	}

	public function test_a_live_assignment_with_an_open_window_is_a_candidate(): void {
		$id = $this->assignment();

		$this->assertSame( array( $id ), $this->candidates() );
	}

	/**
	 * Only live assignments are candidates.
	 *
	 * Every other status is excluded, including `ready` — a creative approved
	 * but not yet started must not serve, and `paused` and `cancelled` are the
	 * two an operator will expect to have taken effect immediately.
	 */
	public function test_every_non_live_status_is_excluded(): void {
		$live = $this->assignment();

		foreach ( Assignment_Rules::statuses() as $status ) {
			if ( Assignment_Rules::LIVE === $status ) {
				continue;
			}

			$this->assignment( array( 'status' => $status ) );
		}

		$this->assertSame( array( $live ), $this->candidates(), 'A non-live assignment was offered as a candidate.' );
	}

	/**
	 * The window is evaluated, on both ends.
	 *
	 * Zero means "inherit the parent" and is therefore open here; a set bound is
	 * enforced. The end is exclusive so an assignment ending at exactly now has
	 * stopped, which is what an operator reading a schedule expects.
	 */
	public function test_the_delivery_window_is_enforced(): void {
		$open       = $this->assignment();
		$started    = $this->assignment( array( 'start_at_ts' => self::NOW - 100 ) );
		$ending     = $this->assignment( array( 'end_at_ts' => self::NOW + 100 ) );
		$not_yet    = $this->assignment( array( 'start_at_ts' => self::NOW + 100 ) );
		$finished   = $this->assignment( array( 'end_at_ts' => self::NOW - 100 ) );
		$ends_now   = $this->assignment( array( 'end_at_ts' => self::NOW ) );
		$candidates = $this->candidates();

		$this->assertSame( array( $open, $started, $ending ), $candidates );
		$this->assertNotContains( $not_yet, $candidates, 'A future assignment was offered.' );
		$this->assertNotContains( $finished, $candidates, 'An expired assignment was offered.' );
		$this->assertNotContains( $ends_now, $candidates, 'An assignment ending now was still offered.' );
	}

	/** Another placement's assignments never appear. */
	public function test_only_the_requested_placement_is_returned(): void {
		$mine = $this->assignment();
		$this->assignment( array( 'placement_id' => $this->placement + 1 ) );

		$this->assertSame( array( $mine ), $this->candidates() );
	}

	/**
	 * Ordering is deterministic, and by id.
	 *
	 * P3 pages this. Without a stable trailing sort key, two rows sharing a
	 * window can swap between pages and a candidate is seen twice or not at
	 * all — a bug that shows up as a rotation nobody can reproduce.
	 */
	public function test_ordering_is_stable_and_by_id(): void {
		/*
		 * Weight ascends with id, so any weight-based ordering *reverses* this.
		 *
		 * Equal weights make `ORDER BY weight` return id order anyway, and
		 * descending weights make it return id order too — both let a
		 * weight-based sort masquerade as this one. Only ascending weight
		 * disagrees, which is what gives the assertion something to catch.
		 */
		$ids = array(
			$this->assignment( array( 'weight' => 100 ) ),
			$this->assignment( array( 'weight' => 200 ) ),
			$this->assignment( array( 'weight' => 300 ) ),
		);

		sort( $ids );

		$this->assertSame( $ids, $this->candidates() );
		$this->assertSame( $this->candidates(), $this->candidates(), 'Two identical reads disagreed.' );
	}

	/**
	 * The limit is honoured, and clamped above the ceiling.
	 *
	 * The clamp needs more rows than the ceiling to be visible at all: with a
	 * dozen in the table, `LIMIT PHP_INT_MAX` and `LIMIT 500` return the same
	 * twelve, and the test passed with the clamp removed.
	 */
	public function test_the_limit_is_honoured_and_clamped(): void {
		for ( $i = 0; $i < 520; $i++ ) {
			$this->assignment();
		}

		$this->assertCount( 5, $this->assignments->candidates_for_placement( $this->placement, self::NOW, 5 ) );

		$this->assertCount(
			500,
			$this->assignments->candidates_for_placement( $this->placement, self::NOW, PHP_INT_MAX ),
			'An unbounded request was honoured rather than clamped.'
		);
	}

	/**
	 * Everything a decision needs is on the row.
	 *
	 * The point of denormalizing at approval. If P3 has to fetch the creative
	 * to learn its dimensions or destination, the contract has failed and the
	 * cost is one query per candidate.
	 */
	public function test_the_row_carries_everything_a_decision_needs(): void {
		$this->assignment();

		$row = $this->assignments->candidates_for_placement( $this->placement, self::NOW )[0];

		foreach (
			array(
				'id',
				'line_item_id',
				'campaign_id',
				'organization_id',
				'revision_id',
				'placement_id',
				'weight',
				'start_at_ts',
				'end_at_ts',
				'click_url',
				'alt_text',
				'width',
				'height',
				'attachment_id',
			) as $key
		) {
			$this->assertArrayHasKey( $key, $row, "A candidate is missing {$key}, so P3 must fetch it separately." );
		}
	}

	/**
	 * One query at a thousand rows, cold and warm.
	 *
	 * The contract asks for query plans and cold/warm counts against realistic
	 * fixtures. The count is the assertion that matters: a per-candidate read
	 * is invisible at three rows and ruinous at five hundred, and nothing about
	 * the code says which it is doing.
	 *
	 * Cold is two, not one. The extra is `table_exists()`'s `SHOW TABLES`, which
	 * memoises for the rest of the request — so a fill pays it once and every
	 * later read is one query. The promise being asserted is that the count is
	 * *constant*, not that it is one: measured at 1,000 rows, and the same at
	 * three.
	 */
	public function test_a_thousand_candidates_cost_one_query_cold_and_warm(): void {
		global $wpdb;

		for ( $i = 0; $i < 1000; $i++ ) {
			$this->assignment();
		}

		$before       = $wpdb->num_queries;
		$start        = microtime( true );
		$cold         = $this->assignments->candidates_for_placement( $this->placement, self::NOW, 500 );
		$cold_ms      = ( microtime( true ) - $start ) * 1000;
		$cold_queries = $wpdb->num_queries - $before;

		$before = $wpdb->num_queries;
		$start  = microtime( true );
		$this->assignments->candidates_for_placement( $this->placement, self::NOW, 500 );
		$warm_ms      = ( microtime( true ) - $start ) * 1000;
		$warm_queries = $wpdb->num_queries - $before;

		$this->assertCount( 500, $cold );
		$this->assertLessThanOrEqual(
			2,
			$cold_queries,
			sprintf( 'Cold read of 1,000 candidates used %d queries in %.2fms.', $cold_queries, $cold_ms )
		);
		$this->assertSame(
			1,
			$warm_queries,
			sprintf( 'Warm read of 1,000 candidates used %d queries in %.2fms.', $warm_queries, $warm_ms )
		);
	}

	/**
	 * The query uses the delivery index rather than scanning.
	 *
	 * Asserted from `EXPLAIN` rather than from timing, because a thousand rows
	 * are fast enough to scan and a test that only measured milliseconds would
	 * pass on a full table scan right up until it did not.
	 */
	public function test_the_query_plan_uses_the_delivery_index(): void {
		global $wpdb;

		/*
		 * Rebuilt from the current DDL, not reused.
		 *
		 * `dbDelta` adds indexes and never drops one, so against a table left by
		 * an earlier test the plan reflects an older schema.
		 *
		 * **What this can and cannot see.** Removing the index from the DDL
		 * fails this test when it runs alone, which is the assertion working.
		 * In a full-file run it can still pass: `WP_UnitTestCase` builds
		 * per-test tables as TEMPORARY, `SHOW TABLES` does not list them, and
		 * dropping one can reveal a base table underneath that dbDelta then
		 * leaves alone. `CreativeModelSchemaTest` records the same limitation.
		 * A green run here is not proof the index survived a schema edit; a
		 * fresh install on a real site is.
		 */
		$this->assignments->drop_table();
		$this->assignments->install_table();

		for ( $i = 0; $i < 200; $i++ ) {
			$this->assignment();
		}

		$table = $this->assignments->table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query-plan introspection over this plugin's own table.
		$plan = $wpdb->get_row(
			$wpdb->prepare(
				"EXPLAIN SELECT id FROM {$table}
					WHERE placement_id = %d AND status = %s
						AND ( start_at_ts = 0 OR start_at_ts <= %d )
						AND ( end_at_ts = 0 OR end_at_ts > %d )
					ORDER BY id ASC LIMIT 100",
				$this->placement,
				Assignment_Rules::LIVE,
				self::NOW,
				self::NOW
			),
			ARRAY_A
		);

		$this->assertIsArray( $plan, 'EXPLAIN returned nothing.' );
		$this->assertSame(
			'delivery',
			$plan['key'] ?? '',
			sprintf( 'The candidate read chose %s instead of the delivery index.', $plan['key'] ?? 'no index' )
		);
		$this->assertNotSame( 'ALL', $plan['type'] ?? '', 'The candidate read is a full table scan.' );
	}
}
