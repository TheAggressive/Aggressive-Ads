<?php
/**
 * First-run installation.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Workflow\Reviewer_Access;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Event_Repository;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;
use Aggressive\Ads\Repository\Rollup_Repository;
use Aggressive\Ads\Repository\Conversion_Repository;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Asset_Repository;
use Aggressive\Ads\Repository\Creative_Assignment_Repository;
use Aggressive\Ads\Repository\Line_Item_Repository;
use Aggressive\Ads\Storage\Creative_Cipher;
use Aggressive\Ads\Security\Roles;
use Aggressive\Ads\Workflow\Click_Hop;
use Aggressive\Ads\Workflow\Rollup_Reconciler;

/**
 * Brings a site up to the current schema, roles and options.
 *
 * Every step is idempotent, because this runs from more than one place: the
 * activation hook, and the upgrader on any request where a version option is
 * behind. Activation is a hint, not the mechanism — see
 * docs/data-schema.md.
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
		return array(
			self::OPTION_DB_VERSION,
			self::OPTION_PLUGIN_VERSION,
			self::OPTION_ROLES_VERSION,
			self::OPTION_REWRITE_VERSION,
			self::OPTION_SEED_VERSION,
			self::OPTION_UPGRADE_LOCK,
			self::OPTION_DELETE_DATA,
			Reviewer_Access::OPTION,
			'aggr_settings',
			Click_Hop::OPTION_REWRITE,
			Rollup_Reconciler::OPTION,
			Line_Item_Migrator::OPTION_CURSOR,
			Line_Item_Migrator::OPTION_NAME_CURSOR,
			Line_Item_Migrator::OPTION_NAME_DONE,
			Creative_Assignment_Migrator::OPTION_CURSOR,
			Creative_Assignment_Migrator::OPTION_DONE,
			Line_Item_Migrator::OPTION_DONE,
			Org_Access_Repository::LOOKUP_SALT_OPTION,
			// Removed last, and only on a data-deleting uninstall: the private
			// files it decrypts are deleted in the same run, so leaving it
			// behind would keep a secret for bytes that no longer exist.
			Creative_Cipher::KEY_OPTION,
		);
	}

	/**
	 * Stored schema version.
	 *
	 * @return int
	 */
	public static function stored_db_version(): int {
		return self::stored_int( self::OPTION_DB_VERSION );
	}

	/**
	 * Stored roles-matrix version.
	 *
	 * @return int
	 */
	public static function stored_roles_version(): int {
		return self::stored_int( self::OPTION_ROLES_VERSION );
	}

	/**
	 * Stored plugin version string.
	 *
	 * @return string
	 */
	public static function stored_plugin_version(): string {
		$current = get_option( self::OPTION_PLUGIN_VERSION, null );

		return is_string( $current ) ? $current : '';
	}

	/**
	 * Integer option, or zero when absent.
	 *
	 * @param string $current Current option name.
	 * @return int
	 */
	private static function stored_int( string $current ): int {
		$value = get_option( $current, null );

		return is_numeric( $value ) ? (int) $value : 0;
	}

	/**
	 * Constructor.
	 *
	 * @param Audit_Repository          $audit_repository Audit persistence.
	 * @param Roles                     $roles            Role installer.
	 * @param Line_Item_Repository|null $line_items Line-item persistence.
	 */
	public function __construct(
		private readonly Audit_Repository $audit_repository,
		private readonly Roles $roles,
		private readonly ?Line_Item_Repository $line_items = null
	) {
	}

	/**
	 * Installs everything a fresh site needs, and repairs an existing one.
	 *
	 * Tables exist before anything writes an audit row, and the version
	 * options are stamped last so a fatal midway leaves them behind — which
	 * makes the next request retry rather than skip.
	 *
	 * @return void
	 */
	public function install(): void {
		$this->audit_repository->install_table();
		$this->install_org_access();
		$this->install_delivery_tables();
		$this->install_conversions();
		$this->install_line_items();
		$this->install_creative_model();

		$this->install_roles();

		// Never lower. The marker records how far the *database* has been taken,
		// and dbDelta drops nothing, so an older build reactivating over a newer
		// schema would stamp a version its own tables contradict — and on the way
		// forward re-run migrations whose backfills would restart from zero.
		update_option( self::OPTION_DB_VERSION, max( self::stored_db_version(), Schema::DB_VERSION ), true );
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

	/** Creates or repairs the attributed-conversion ledger. */
	public function install_conversions(): void {
		( new Conversion_Repository() )->install_table();
	}

	/** Creates or repairs the campaign line-item table. */
	public function install_line_items(): void {
		$this->line_items()->install_table();
	}

	/** Creates or repairs the P2 creative asset and assignment tables. */
	public function install_creative_model(): void {
		( new Creative_Asset_Repository() )->install_table();
		( new Creative_Assignment_Repository() )->install_table();
	}

	/** Repository supplied by the container, with a standalone-test fallback. */
	private function line_items(): Line_Item_Repository {
		return $this->line_items ?? new Line_Item_Repository( new Campaign_Repository() );
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
