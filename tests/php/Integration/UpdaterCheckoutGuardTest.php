<?php
/**
 * The guard that stops the self-updater deleting a working checkout.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Update\Plugin_Updates;
use WP_UnitTestCase;

/**
 * A plugin update clears the destination directory and unpacks the release ZIP
 * over it, and the release ZIP contains none of the repository. Installing one
 * over a checkout deletes the checkout, so these assert the guard rather than
 * the update path.
 */
final class UpdaterCheckoutGuardTest extends WP_UnitTestCase {

	/**
	 * Removes the override between tests.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_all_filters( 'aggr_enable_plugin_updates' );

		parent::tear_down();
	}

	/**
	 * The suite runs from the repository, which is the case being guarded.
	 *
	 * Asserted before anything else, because every test below would pass for
	 * the wrong reason on an install with no `.git` — the guard would be
	 * inactive and nothing would be proven.
	 *
	 * @return void
	 */
	public function test_the_suite_runs_from_a_checkout(): void {
		$this->assertDirectoryExists(
			trailingslashit( AGGR_PLUGIN_DIR ) . '.git',
			'These tests only mean something when the plugin root is a checkout.'
		);
	}

	/**
	 * A checkout is never offered an update.
	 *
	 * @return void
	 */
	public function test_a_checkout_disables_the_updater(): void {
		$this->assertFalse( Plugin_Updates::is_enabled() );
	}

	/**
	 * Nothing is hooked, so no callback can run at all.
	 *
	 * Stronger than asserting the flag: a later refactor could keep the flag and
	 * still register `upgrader_pre_download`, which would verify a package and
	 * hand it back for a directory that must never be overwritten.
	 *
	 * @return void
	 */
	public function test_a_checkout_registers_no_update_hooks(): void {
		// The real service, not a double: the repositories it depends on are
		// final, and the thing under test is the wiring rather than any
		// collaborator's behaviour.
		$updates = Plugin::instance()->container()->get( Plugin_Updates::class );

		$updates->init();

		$this->assertFalse( has_filter( 'pre_set_site_transient_update_plugins', array( $updates, 'check' ) ) );
		$this->assertFalse( has_filter( 'upgrader_pre_download', array( $updates, 'verify_download' ) ) );
		$this->assertFalse( has_filter( 'plugins_api', array( $updates, 'plugin_information' ) ) );
		$this->assertFalse( has_action( 'upgrader_process_complete', array( $updates, 'clear_after_update' ) ) );
	}

	/**
	 * The filter can force the updater on, for someone who knows why.
	 *
	 * @return void
	 */
	public function test_the_filter_can_re_enable_it(): void {
		add_filter( 'aggr_enable_plugin_updates', '__return_true' );

		$this->assertTrue( Plugin_Updates::is_enabled() );
	}

	/**
	 * The filter can force it off on a distributed copy too.
	 *
	 * @return void
	 */
	public function test_the_filter_can_disable_it_anywhere(): void {
		add_filter( 'aggr_enable_plugin_updates', '__return_false' );

		$this->assertFalse( Plugin_Updates::is_enabled() );
	}

	/**
	 * The filter is told whether this looked like a checkout.
	 *
	 * @return void
	 */
	public function test_the_filter_receives_the_checkout_verdict(): void {
		$seen = null;

		add_filter(
			'aggr_enable_plugin_updates',
			static function ( bool $enabled, bool $is_checkout ) use ( &$seen ): bool {
				$seen = $is_checkout;

				return $enabled;
			},
			10,
			2
		);

		Plugin_Updates::is_enabled();

		$this->assertTrue( $seen );
	}
}
