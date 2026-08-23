<?php
/**
 * Uninstall must not leave options behind.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Install\Installer;
use Aggressive\Ads\Repository\Org_Access_Repository;
use WP_UnitTestCase;

/**
 * Asserts the property, not the list.
 *
 * `Installer::options()` is hand-maintained, and the only thing checking it was
 * somebody remembering to add a line. `aggr_org_access_lookup_salt` is what that
 * costs: a new option, written lazily on the first organization lookup, left in
 * the database forever after the plugin and its tables were removed.
 *
 * Asserting that the list contains one particular string would catch that option
 * and no future one. So this sweeps the options table for anything the plugin
 * owns and asserts the declared list covers all of it — which fails for the
 * *next* forgotten option too.
 *
 * **It does not run the uninstaller.** An earlier version did, and it was a
 * menace: `Uninstaller::run_for_current_site()` drops four tables and removes
 * the roles, `WP_UnitTestCase` rolls back rows but cannot roll back DDL, and
 * reinstalling in tear_down did not restore it either. The damage escaped into
 * the shared database and surfaced as failures in CampaignCopyTest,
 * CreativePromoterTest and NotificationServiceTest — suites with nothing to do
 * with uninstall, on a *later run*, which is the worst way to find anything.
 * The coverage that matters is that the declaration is complete; executing the
 * deletion to prove `delete_option()` works tests WordPress, not this plugin.
 */
final class UninstallOptionsTest extends WP_UnitTestCase {

	/**
	 * Every option row whose name this plugin owns.
	 *
	 * @return array<int, string>
	 */
	private function stored_plugin_options(): array {
		global $wpdb;

		$names = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'aggr\\_%'"
		);

		return is_array( $names ) ? array_map( 'strval', $names ) : array();
	}

	/** Every option the plugin actually stores is declared for uninstall. */
	public function test_every_stored_option_is_declared_for_uninstall(): void {
		/*
		 * Touch the paths that write options lazily rather than at install, so
		 * the sweep sees what a used site holds. The lookup salt is created on
		 * first use, which is exactly why it was missed: nothing at install
		 * time writes it, so an install-and-look test would not have found it.
		 *
		 * Reads only, and nothing writes `aggr_settings` here. An earlier
		 * version set it to an empty module list to pad the fixture, which
		 * switched every module off for whatever ran next and failed eleven
		 * unrelated tests. A test that needs a fixture must build one that
		 * costs nothing to the rest of the suite.
		 */
		( new Org_Access_Repository() )->org_id_for_canonical( 'ANY NAME' );

		$stored   = $this->stored_plugin_options();
		$declared = Installer::options();

		// Assert the fixture is real before asserting on it: a sweep that found
		// nothing would pass this test against an empty declaration.
		$this->assertGreaterThan(
			3,
			count( $stored ),
			'The fixture must hold real options, or the assertion below is vacuous.'
		);
		$this->assertContains( Org_Access_Repository::LOOKUP_SALT_OPTION, $stored );

		$undeclared = array_values( array_diff( $stored, $declared ) );

		$this->assertSame(
			array(),
			$undeclared,
			'These options would survive uninstall. Add them to Installer::options(): '
				. implode( ', ', $undeclared )
		);
	}

	/**
	 * The declared list is well formed.
	 *
	 * A stale entry is harmless at runtime and misleading to read; a duplicate
	 * means two constants resolved to one name, which is a collision worth
	 * seeing rather than deleting twice.
	 */
	public function test_the_declared_option_list_is_well_formed(): void {
		$options = Installer::options();

		$this->assertNotEmpty( $options );

		foreach ( $options as $option ) {
			$this->assertIsString( $option );
			$this->assertNotSame( '', $option );
			$this->assertStringStartsWith( 'aggr_', $option );
		}

		$this->assertSame( count( $options ), count( array_unique( $options ) ) );
	}
}
