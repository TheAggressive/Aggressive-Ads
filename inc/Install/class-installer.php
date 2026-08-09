<?php
/**
 * First-run installation.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Install;

use LAAO_Advertiser_Portal\Audit\Audit_Event;
use LAAO_Advertiser_Portal\Repository\Audit_Repository;
use LAAO_Advertiser_Portal\Security\Roles;

/**
 * Brings a site up to the current schema, roles and options.
 *
 * Every step is idempotent, because this runs from more than one place: the
 * activation hook, and the upgrader on any request where a version option is
 * behind. Activation is a hint, not the mechanism — see
 * docs/adr/0014-version-driven-idempotent-installer.md.
 */
final class Installer {

	public const OPTION_DB_VERSION      = 'laao_ads_db_version';
	public const OPTION_PLUGIN_VERSION  = 'laao_ads_plugin_version';
	public const OPTION_ROLES_VERSION   = 'laao_ads_roles_version';
	public const OPTION_REWRITE_VERSION = 'laao_ads_rewrite_version';
	public const OPTION_SEED_VERSION    = 'laao_ads_seed_version';
	public const OPTION_UPGRADE_LOCK    = 'laao_ads_upgrade_lock';
	public const OPTION_DELETE_DATA     = 'laao_ads_delete_data_on_uninstall';

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
			'laao_ads_settings',
		);
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
	 * Ordering matters: the table exists before anything writes an audit row,
	 * and the version options are stamped last so a fatal midway leaves them
	 * behind — which makes the next request retry rather than skip.
	 *
	 * @return void
	 */
	public function install(): void {
		$this->audit_repository->install_table();

		$this->install_roles();

		update_option( self::OPTION_DB_VERSION, Schema::DB_VERSION, true );
		update_option( self::OPTION_PLUGIN_VERSION, LAAO_ADS_VERSION, true );

		$this->audit_repository->insert(
			new Audit_Event(
				event: 'plugin.installed',
				object_type: 'plugin',
				message: 'Installed or repaired plugin schema, roles and options.',
				context: array(
					'db_version'     => Schema::DB_VERSION,
					'roles_version'  => Roles::VERSION,
					'plugin_version' => LAAO_ADS_VERSION,
				)
			)
		);
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
