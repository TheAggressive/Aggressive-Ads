<?php
/**
 * The configurable audit-log window.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Workflow\Audit_Retention;
use WP_UnitTestCase;

/**
 * Deleting audit history is irreversible and sometimes unlawful, so the
 * assertions worth making are mostly about what survives.
 */
final class AuditRetentionTest extends WP_UnitTestCase {

	/**
	 * Audit persistence.
	 *
	 * @var Audit_Repository
	 */
	private Audit_Repository $audit;

	/**
	 * The sweep under test.
	 *
	 * @var Audit_Retention
	 */
	private Audit_Retention $retention;

	/**
	 * Starts from an empty table.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->audit = new Audit_Repository();
		$this->audit->drop_table();
		$this->audit->install_table();

		$this->retention = Plugin::instance()->container()->get( Audit_Retention::class );

		delete_option( 'aggr_settings' );
	}

	/**
	 * Writes one row at a given age.
	 *
	 * @param int    $days_ago How old the row is.
	 * @param string $outcome  One of the OUTCOME_* constants.
	 * @return void
	 */
	private function row( int $days_ago, string $outcome = Audit_Event::OUTCOME_OK ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Seeding the table under test at a controlled age.
			$this->audit->table_name(),
			array(
				'created_at'    => gmdate( 'Y-m-d H:i:s', time() - ( $days_ago * DAY_IN_SECONDS ) ),
				'created_at_ts' => time() - ( $days_ago * DAY_IN_SECONDS ),
				'event'         => 'campaign.transitioned',
				'object_type'   => 'campaign',
				'object_id'     => 1,
				'org_id'        => 1,
				'outcome'       => $outcome,
				'message'       => 'seeded',
			)
		);
	}

	/**
	 * Rows this test seeded, ignoring anything the plugin wrote itself.
	 *
	 * Scoped to the marker rather than counting the table: booting the plugin
	 * writes its own audit rows, and the first version of this counted those
	 * too — eight where two were expected. The rows are recent, so a window
	 * never deletes them, but they make a total meaningless.
	 *
	 * @return int
	 */
	private function count_rows(): int {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Counting the rows this test seeded.
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COUNT(*) FROM %i WHERE message = %s',
				$this->audit->table_name(),
				'seeded'
			)
		);
	}

	/**
	 * Chooses a window.
	 *
	 * @param int $days Retention days, or zero for never.
	 * @return void
	 */
	private function set_window( int $days ): void {
		update_option( 'aggr_settings', array( 'audit' => array( 'retention_days' => $days ) ) );
	}

	/**
	 * The shipped configuration deletes nothing, however old the rows are.
	 *
	 * @return void
	 */
	public function test_the_default_never_deletes(): void {
		$this->row( 4000 );
		$this->row( 4000 );

		$this->assertSame( 0, $this->retention->purge(), 'Keep-forever must not delete.' );
		$this->assertSame( 2, $this->count_rows() );
	}

	/**
	 * Zero is keep-forever even when set explicitly.
	 *
	 * @return void
	 */
	public function test_zero_is_keep_forever(): void {
		$this->set_window( 0 );
		$this->row( 4000 );

		$this->assertSame( 0, $this->retention->purge() );
		$this->assertSame( 1, $this->count_rows() );
	}

	/**
	 * A window deletes what is past it and keeps what is inside it.
	 *
	 * @return void
	 */
	public function test_a_window_deletes_only_what_is_past_it(): void {
		$this->set_window( 365 );

		$this->row( 400 );
		$this->row( 400 );
		$this->row( 10 );

		$this->assertSame( 2, $this->retention->purge() );
		$this->assertSame( 1, $this->count_rows(), 'A row inside the window must survive.' );
	}

	/**
	 * Refusals outlive the window.
	 *
	 * The row an investigation opens the log for is the one saying somebody
	 * tried what they were not allowed to.
	 *
	 * @return void
	 */
	public function test_denied_rows_survive_any_window(): void {
		$this->set_window( 365 );

		$this->row( 4000, Audit_Event::OUTCOME_DENIED );
		$this->row( 4000, Audit_Event::OUTCOME_OK );

		$this->assertSame( 1, $this->retention->purge() );
		$this->assertSame( 1, $this->count_rows() );

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Asserting which row survived.
		$survivor = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT outcome FROM %i WHERE message = %s',
				$this->audit->table_name(),
				'seeded'
			)
		);

		$this->assertSame( Audit_Event::OUTCOME_DENIED, $survivor );
	}

	/**
	 * Running it again with nothing left deletes nothing.
	 *
	 * @return void
	 */
	public function test_it_is_idempotent(): void {
		$this->set_window( 365 );
		$this->row( 400 );

		$this->assertSame( 1, $this->retention->purge() );
		$this->assertSame( 0, $this->retention->purge() );
	}

	/**
	 * The sweep is scheduled even while retention is off.
	 *
	 * Turning it on should take effect on the next daily run rather than
	 * waiting for a reactivation nobody will perform.
	 *
	 * @return void
	 */
	public function test_the_sweep_is_scheduled(): void {
		wp_clear_scheduled_hook( Audit_Retention::HOOK );

		$this->retention->ensure_scheduled();

		$this->assertNotFalse( wp_next_scheduled( Audit_Retention::HOOK ) );
		$this->assertSame( Audit_Retention::RECURRENCE, wp_get_schedule( Audit_Retention::HOOK ) );
	}
}
