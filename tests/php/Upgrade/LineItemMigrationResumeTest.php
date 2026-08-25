<?php
/**
 * The line-item migration resumes both of its passes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Upgrade;

use Aggressive\Ads\Install\Line_Item_Migrator;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Repository\Line_Item_Repository;
use WP_UnitTestCase;

/**
 * Migration 12 runs in two passes: default rows first, then name provenance.
 *
 * They are separate because a row has to exist before its name can be
 * classified, and each is bounded so neither holds a request open. The cost of
 * that split is that "the migration is finished" is two facts rather than one,
 * and `init()` used to ask about only the first.
 *
 * `run_batch()` clears the scheduled hook the moment the first pass completes.
 * The second reschedules itself while it still has rows, so the two agreed for
 * as long as every cron event fired. A lost event broke it permanently: the
 * first pass was marked done, `init()` saw a complete migration and scheduled
 * nothing, and the second sat unfinished with nothing able to wake it.
 *
 * The symptom is quiet — a line item still showing the placeholder name the
 * wizard invented — which reads as a display bug rather than a stranded
 * migration, so it is worth a test rather than an inspection.
 */
final class LineItemMigrationResumeTest extends WP_UnitTestCase {

	/**
	 * Migrator under test.
	 *
	 * @var Line_Item_Migrator
	 */
	private Line_Item_Migrator $migrator;

	public function set_up(): void {
		parent::set_up();

		$line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$line_items->install_table();

		$this->migrator = Plugin::instance()->container()->get( Line_Item_Migrator::class );

		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );
	}

	public function tear_down(): void {
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );

		delete_option( Line_Item_Migrator::OPTION_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_DONE );
		delete_option( Line_Item_Migrator::OPTION_NAME_CURSOR );
		delete_option( Line_Item_Migrator::OPTION_NAME_DONE );

		parent::tear_down();
	}

	/** Whether the migration hook is on the schedule. */
	private function is_scheduled(): bool {
		return false !== wp_next_scheduled( Line_Item_Migrator::HOOK );
	}

	/**
	 * The failure this exists for.
	 *
	 * The first pass is done, its completion cleared the hook, and the cron
	 * event that would have carried the second pass never arrived. Runtime
	 * initialization is the only thing left that can notice.
	 */
	public function test_a_lost_event_after_the_first_pass_does_not_strand_the_second(): void {
		update_option( Line_Item_Migrator::OPTION_DONE, 1, false );

		// Exactly what run_batch() does when the first pass finishes.
		wp_clear_scheduled_hook( Line_Item_Migrator::HOOK );

		// Assert the fixture is the state under test before asserting on it.
		$this->assertTrue( $this->migrator->is_complete() );
		$this->assertFalse( $this->migrator->name_provenance_is_complete() );
		$this->assertFalse( $this->is_scheduled() );

		$this->migrator->init();

		$this->assertTrue(
			$this->is_scheduled(),
			'The name-provenance pass was left with nothing able to resume it.'
		);
	}

	/** An unfinished first pass is resumed, which was always true. */
	public function test_an_unfinished_first_pass_is_resumed(): void {
		$this->assertFalse( $this->migrator->is_complete() );

		$this->migrator->init();

		$this->assertTrue( $this->is_scheduled() );
	}

	/**
	 * A finished migration schedules nothing.
	 *
	 * The negative half, and the one that stops the fix from becoming "always
	 * schedule". An init that scheduled unconditionally would satisfy both
	 * tests above and put a cron event on every site forever.
	 */
	public function test_a_complete_migration_schedules_nothing(): void {
		update_option( Line_Item_Migrator::OPTION_DONE, 1, false );
		update_option( Line_Item_Migrator::OPTION_NAME_DONE, 1, false );

		$this->migrator->init();

		$this->assertFalse( $this->is_scheduled() );
	}

	/**
	 * The second pass releases the hook when it finishes.
	 *
	 * Otherwise a completed migration leaves an event firing against two passes
	 * that both return immediately — harmless, and still a scheduled job on
	 * every installed site with nothing to do.
	 */
	public function test_the_finished_second_pass_leaves_nothing_scheduled(): void {
		update_option( Line_Item_Migrator::OPTION_DONE, 1, false );

		$this->migrator->init();
		$this->assertTrue( $this->is_scheduled() );

		// No line items exist, so the pass examines nothing and is complete.
		$this->migrator->backfill_name_provenance_batch();

		$this->assertTrue( $this->migrator->name_provenance_is_complete() );
		$this->assertFalse( $this->is_scheduled() );
	}

	/**
	 * Nothing is scheduled before the table exists.
	 *
	 * The migration reads a custom table. Scheduling work against a table that
	 * has not been installed would fire a job that can only fail.
	 */
	public function test_no_work_is_scheduled_without_the_table(): void {
		$line_items = Plugin::instance()->container()->get( Line_Item_Repository::class );
		$line_items->drop_table();

		$this->migrator->init();

		$this->assertFalse( $this->is_scheduled() );

		// Put it back for whatever runs next: this is DDL, and the suite's
		// transaction rollback does not undo it.
		$line_items->install_table();
	}
}
