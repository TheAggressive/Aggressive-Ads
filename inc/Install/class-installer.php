<?php
/**
 * First-run installation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Identity_Maps;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Identity_Rewrite;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Storage\Private_Storage;
use Aggressive\Ads\Workflow\Click_Hop;

/**
 * Brings a site up to the current schema, roles and options.
 *
 * Every step is idempotent, because this runs from more than one place: the
 * activation hook, and the upgrader on any request where a version option is
 * behind. Activation is a hint, not the mechanism — see
 * docs/adr/0014-version-driven-idempotent-installer.md.
 */
final class Installer {

	public const OPTION_DB_VERSION      = 'aggr_db_version';
	public const OPTION_PLUGIN_VERSION  = 'aggr_plugin_version';
	public const OPTION_ROLES_VERSION   = 'aggr_roles_version';
	public const OPTION_REWRITE_VERSION = 'aggr_rewrite_version';
	public const OPTION_SEED_VERSION    = 'aggr_seed_version';
	public const OPTION_UPGRADE_LOCK    = 'aggr_upgrade_lock';
	public const OPTION_DELETE_DATA     = 'aggr_delete_data_on_uninstall';

	/**
	 * Every option this plugin owns, for uninstall.
	 *
	 * @return array<int, string>
	 */
	public static function options(): array {
		return array_values(
			array_unique(
				array_merge(
					array(
						self::OPTION_DB_VERSION,
						self::OPTION_PLUGIN_VERSION,
						self::OPTION_ROLES_VERSION,
						self::OPTION_REWRITE_VERSION,
						self::OPTION_SEED_VERSION,
						self::OPTION_UPGRADE_LOCK,
						self::OPTION_DELETE_DATA,
						'aggr_settings',
						Click_Hop::OPTION_REWRITE,
					),
					array_keys( Identity_Maps::option_keys() ),
					array_values( Identity_Maps::option_keys() )
				)
			)
		);
	}

	/**
	 * Stored schema version, falling back to the pre-rename option.
	 *
	 * Reading only the new key treats an existing LAAO install as a fresh
	 * site and would run install() instead of migration 3 — leaving every
	 * campaign under a post type nothing queries.
	 *
	 * @return int
	 */
	public static function stored_db_version(): int {
		return self::stored_int( self::OPTION_DB_VERSION );
	}

	/**
	 * Stored roles-matrix version, with the same legacy fallback.
	 *
	 * @return int
	 */
	public static function stored_roles_version(): int {
		return self::stored_int( self::OPTION_ROLES_VERSION );
	}

	/**
	 * Stored plugin version string, with the same legacy fallback.
	 *
	 * @return string
	 */
	public static function stored_plugin_version(): string {
		$current = get_option( self::OPTION_PLUGIN_VERSION, null );

		if ( is_string( $current ) && '' !== $current ) {
			return $current;
		}

		$legacy = Identity_Maps::legacy_option_key( self::OPTION_PLUGIN_VERSION );

		if ( null === $legacy ) {
			return '';
		}

		$old = get_option( $legacy, null );

		return is_string( $old ) ? $old : '';
	}

	/**
	 * Integer option with a legacy-key fallback.
	 *
	 * @param string $current Current option name.
	 * @return int
	 */
	private static function stored_int( string $current ): int {
		$value = get_option( $current, null );

		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		$legacy = Identity_Maps::legacy_option_key( $current );

		if ( null === $legacy ) {
			return 0;
		}

		$old = get_option( $legacy, null );

		return is_numeric( $old ) ? (int) $old : 0;
	}

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository $audit_repository Audit persistence.
	 * @param Roles            $roles            Role installer.
	 */
	public function __construct(
		private readonly Audit_Repository $audit_repository,
		private readonly Roles $roles
	) {
	}

	/**
	 * Installs everything a fresh site needs, and repairs an existing one.
	 *
	 * Ordering matters: identity rewrite runs before dbDelta, so activating
	 * new code against a LAAO database cannot create empty new tables beside
	 * populated old ones and then stamp the current version — which would
	 * make the upgrader skip migration 3 and leave every campaign
	 * unqueryable. The rewrite is a no-op on a fresh site. Tables exist
	 * before anything writes an audit row, and the version options are
	 * stamped last so a fatal midway leaves them behind — which makes the
	 * next request retry rather than skip.
	 *
	 * @return void
	 */
	public function install(): void {
		( new Identity_Migration( new Identity_Rewrite(), new Private_Storage() ) )->to_3();

		$this->audit_repository->install_table();
		$this->install_org_access();
		$this->install_delivery_tables();

		$this->install_roles();

		update_option( self::OPTION_DB_VERSION, Schema::DB_VERSION, true );
		update_option( self::OPTION_PLUGIN_VERSION, AGGR_VERSION, true );

		$this->audit_repository->insert(
			new Audit_Event(
				event: 'plugin.installed',
				object_type: 'plugin',
				message: 'Installed or repaired plugin schema, roles and options.',
				context: array(
					'db_version'     => Schema::DB_VERSION,
					'roles_version'  => Roles::VERSION,
					'plugin_version' => AGGR_VERSION,
				)
			)
		);
	}

	/**
	 * Install the organization identity/access registry and backfill identities.
	 *
	 * This is public so the versioned migration can run the exact same
	 * idempotent operation as a fresh installation.
	 *
	 * @return void
	 */
	public function install_org_access(): void {
		$access = new Org_Access_Repository();
		$access->install_table();

		( new Org_Repository( $access ) )->backfill_identities();
	}

	/**
	 * Installs native fill event and rollup tables.
	 */
	public function install_delivery_tables(): void {
		( new Event_Repository() )->install_table();
		( new Rollup_Repository() )->install_table();
	}

	/**
	 * One fill may impression and click; the same event may not replay.
	 *
	 * WordPress dbDelta will add token_event but will not drop the v4 token_hash unique.
	 */
	public function migrate_event_token_uniqueness(): void {
		( new Event_Repository() )->migrate_token_event_unique();
	}

	/**
	 * Applies the role matrix and records the version it came from.
	 *
	 * Split out so the upgrader can re-run roles alone when only the matrix
	 * changed, without touching the schema.
	 *
	 * @return void
	 */
	public function install_roles(): void {
		$this->roles->install();

		update_option( self::OPTION_ROLES_VERSION, Roles::VERSION, true );
	}
}
