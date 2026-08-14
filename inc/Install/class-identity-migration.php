<?php
/**
 * Orchestrates the Phase 0 identity rewrite.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Domain\Identity_Maps;
use Aggressive\Ads\Repository\Identity_Rewrite;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use WP_User;

/**
 * Applies every stored-identifier rewrite for schema version 3.
 *
 * SQL lives in Identity_Rewrite. Role assignment, cron, and the private
 * storage directory are WordPress APIs, so they stay here. The whole method
 * is safe to run twice. Role *definitions* are reapplied by the upgrader
 * when Roles::VERSION is behind — this class only moves the stored slugs.
 */
final class Identity_Migration {

	/**
	 * Constructor.
	 *
	 * @param Identity_Rewrite $rewrite Storage rewrite.
	 * @param Private_Storage  $storage Private creative storage.
	 */
	public function __construct(
		private readonly Identity_Rewrite $rewrite,
		private readonly Private_Storage $storage
	) {
	}

	/**
	 * Migration step producing db version 3.
	 *
	 * @return void
	 */
	public function to_3(): void {
		$this->rewrite->rewrite_post_types();
		$this->rewrite->rewrite_post_statuses();
		$this->rewrite->rewrite_meta_keys();
		$this->rewrite->rename_tables();
		$this->rewrite->rewrite_options();
		$this->migrate_role_assignments();
		$this->migrate_granted_capabilities();
		$this->clear_legacy_cron();
		$this->storage->promote_legacy_directory();
	}

	/**
	 * Moves users off the previous role slugs, then removes those roles.
	 *
	 * @return void
	 */
	private function migrate_role_assignments(): void {
		foreach ( Identity_Maps::roles() as $from => $to ) {
			do {
				$users = get_users(
					array(
						'role'   => $from,
						'number' => 200,
						'fields' => 'ID',
					)
				);

				foreach ( $users as $user_id ) {
					$user = new WP_User( (int) $user_id );
					$user->add_role( $to );
					$user->remove_role( $from );
				}
			} while ( array() !== $users );

			remove_role( $from );
		}
	}

	/**
	 * Copies renamed capabilities onto roles that already held the old ones.
	 *
	 * @return void
	 */
	private function migrate_granted_capabilities(): void {
		foreach ( Roles::roles_receiving_all_capabilities() as $slug ) {
			$role = get_role( $slug );

			if ( null === $role ) {
				continue;
			}

			foreach ( Identity_Maps::capabilities() as $from => $to ) {
				if ( $role->has_cap( $from ) ) {
					$role->add_cap( $to );
					$role->remove_cap( $from );
				}
			}
		}
	}

	/**
	 * Clears cron events registered under the previous hook names.
	 *
	 * The current services reschedule themselves on init.
	 *
	 * @return void
	 */
	private function clear_legacy_cron(): void {
		foreach ( array_keys( Identity_Maps::cron_hooks() ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}
}
