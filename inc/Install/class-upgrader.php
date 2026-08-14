<?php
/**
 * Version-driven upgrades.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Security\Roles;
use Throwable;

/**
 * Reconciles a site's stored versions against the code's, on every request.
 *
 * The activation hook does not run on a Git or rsync deploy, an in-place
 * update, a database restore, or a multisite network activation reaching a site
 * that was never individually activated. Every one of those produces new code
 * against old schema, silently, until something reads a column that is not
 * there. So the check runs always, and does nothing when the versions match.
 *
 * See docs/data-schema.md.
 */
final class Upgrader {

	/**
	 * A lock older than this is assumed to belong to a request that died.
	 *
	 * Without the sweep, a fatal inside a migration wedges the site
	 * permanently: the lock is never released, and every later request declines
	 * to upgrade.
	 */
	private const STALE_LOCK_SECONDS = 300;

	/**
	 * Constructor.
	 *
	 * Migration steps are keyed by the db version they produce. Each step must
	 * be idempotent, and the walker stamps the version after each one succeeds
	 * — so a fatal partway through the sequence never replays completed work,
	 * and the next request resumes at the first unfinished step. One bump after
	 * the whole loop would mean any failure re-runs every earlier ALTER, which
	 * is how one bad deploy becomes a corrupted schema.
	 *
	 * The map is a constructor argument rather than a class constant so the
	 * walker can be exercised with real steps before a real migration exists.
	 * A walker whose first execution is a production upgrade is a walker nobody
	 * has ever seen run.
	 *
	 * @param Installer                    $installer        Installation steps.
	 * @param Audit_Repository             $audit_repository Audit persistence.
	 * @param array<int, callable(): void> $migrations       Steps, keyed by target db version.
	 */
	public function __construct(
		private readonly Installer $installer,
		private readonly Audit_Repository $audit_repository,
		private readonly array $migrations = array()
	) {
	}

	/**
	 * Runs any outstanding upgrade work.
	 *
	 * Cheap on the overwhelming majority of requests: three autoloaded option
	 * reads and three comparisons.
	 *
	 * @return void
	 */
	public function maybe_upgrade(): void {
		if ( ! $this->needs_work() ) {
			return;
		}

		if ( ! $this->acquire_lock() ) {
			return;
		}

		try {
			$this->run();
		} catch ( Throwable $e ) {
			$this->audit_repository->insert(
				new Audit_Event(
					event: 'plugin.upgrade_failed',
					outcome: Audit_Event::OUTCOME_FAILED,
					object_type: 'plugin',
					message: $e->getMessage()
				)
			);
		} finally {
			$this->release_lock();
		}
	}

	/**
	 * Reports whether any stored version is behind the code.
	 *
	 * @return bool
	 */
	public function needs_work(): bool {
		if ( Installer::stored_db_version() < Schema::DB_VERSION ) {
			return true;
		}

		if ( Installer::stored_roles_version() < Roles::VERSION ) {
			return true;
		}

		return Installer::stored_plugin_version() !== AGGR_VERSION;
	}

	/**
	 * Performs the outstanding work.
	 *
	 * @return void
	 */
	private function run(): void {
		$from = Installer::stored_db_version();

		if ( 0 === $from ) {
			// Nothing installed: the installer is the migration.
			$this->installer->install();

			return;
		}

		$this->migrate( $from );

		if ( Installer::stored_roles_version() < Roles::VERSION ) {
			$this->installer->install_roles();

			$this->audit_repository->insert(
				new Audit_Event(
					event: 'plugin.roles_upgraded',
					object_type: 'plugin',
					message: 'Reapplied the role capability matrix.',
					context: array( 'roles_version' => Roles::VERSION )
				)
			);
		}

		update_option( Installer::OPTION_PLUGIN_VERSION, AGGR_VERSION, true );
	}

	/**
	 * Walks the migration map in order, stamping each step as it succeeds.
	 *
	 * @param int $from The stored db version.
	 * @return void
	 */
	private function migrate( int $from ): void {
		$steps = $this->migrations;

		// Sorted rather than trusting declaration order: a step added out of
		// sequence during a merge must still run in version order.
		ksort( $steps, SORT_NUMERIC );

		foreach ( $steps as $target => $step ) {
			if ( $target <= $from ) {
				continue;
			}

			$step();

			update_option( Installer::OPTION_DB_VERSION, $target, true );

			$this->audit_repository->insert(
				new Audit_Event(
					event: 'plugin.migrated',
					object_type: 'plugin',
					message: sprintf( 'Applied database migration to version %d.', $target ),
					context: array( 'to' => $target )
				)
			);
		}
	}

	/**
	 * Takes the upgrade lock.
	 *
	 * `add_option` returns false when the row already exists, which is the
	 * closest thing to an atomic test-and-set WordPress offers without a direct
	 * query. Two simultaneous requests after a deploy do not both migrate.
	 *
	 * The option is deliberately not autoloaded: it is written and deleted
	 * rather than read, and an autoloaded option that churns is a
	 * cache-invalidation cost for nothing.
	 *
	 * @return bool
	 */
	private function acquire_lock(): bool {
		$this->clear_stale_lock();

		foreach ( $this->lock_keys() as $key ) {
			if ( false !== get_option( $key, false ) ) {
				return false;
			}
		}

		return add_option( Installer::OPTION_UPGRADE_LOCK, time(), '', false );
	}

	/**
	 * Releases the upgrade lock.
	 *
	 * @return void
	 */
	private function release_lock(): void {
		foreach ( $this->lock_keys() as $key ) {
			delete_option( $key );
		}
	}

	/**
	 * Clears a lock left behind by a request that died mid-upgrade.
	 *
	 * @return void
	 */
	private function clear_stale_lock(): void {
		foreach ( $this->lock_keys() as $key ) {
			$held = get_option( $key, false );

			if ( false === $held ) {
				continue;
			}

			if ( ( time() - (int) $held ) > self::STALE_LOCK_SECONDS ) {
				delete_option( $key );
			}
		}
	}

	/**
	 * Lock option names.
	 *
	 * @return array<int, string>
	 */
	private function lock_keys(): array {
		return array( Installer::OPTION_UPGRADE_LOCK );
	}
}
