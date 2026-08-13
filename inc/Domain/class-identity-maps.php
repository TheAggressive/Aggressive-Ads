<?php
/**
 * LAAO → Aggressive Ads identifier maps.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure old→new maps for the Phase 0 identity rewrite.
 *
 * No WordPress calls: the maps are unit-testable, and the SQL that applies
 * them lives in the repository layer. Fresh installs never consult this
 * class — they write the new names directly. Existing sites walk it once
 * in migration 3.
 */
final class Identity_Maps {

	public const LEGACY_META_PREFIX   = '_laao_ads_';
	public const META_PREFIX          = '_aggr_';
	public const LEGACY_OPTION_PREFIX = 'laao_ads_';
	public const OPTION_PREFIX        = 'aggr_';

	/**
	 * Custom post types.
	 *
	 * @return array<string, string>
	 */
	public static function post_types(): array {
		return array(
			'laao_ads_org'       => 'aggr_org',
			'laao_ads_placement' => 'aggr_placement',
			'laao_ads_package'   => 'aggr_package',
			'laao_ads_campaign'  => 'aggr_campaign',
			'laao_ads_creative'  => 'aggr_creative',
		);
	}

	/**
	 * Campaign statuses. `aggr_scheduled` is 14 characters; the column is 20.
	 *
	 * @return array<string, string>
	 */
	public static function statuses(): array {
		return array(
			'lap_draft'     => 'aggr_draft',
			'lap_submitted' => 'aggr_submitted',
			'lap_review'    => 'aggr_review',
			'lap_changes'   => 'aggr_changes',
			'lap_approved'  => 'aggr_approved',
			'lap_scheduled' => 'aggr_scheduled',
			'lap_live'      => 'aggr_live',
			'lap_paused'    => 'aggr_paused',
			'lap_complete'  => 'aggr_complete',
			'lap_cancelled' => 'aggr_cancelled',
			'lap_rejected'  => 'aggr_rejected',
		);
	}

	/**
	 * Unprefixed custom table names.
	 *
	 * @return array<string, string>
	 */
	public static function tables(): array {
		return array(
			'laao_ads_audit_log'  => 'aggr_audit_log',
			'laao_ads_org_access' => 'aggr_org_access',
		);
	}

	/**
	 * Option keys this plugin owns.
	 *
	 * @return array<string, string>
	 */
	public static function option_keys(): array {
		return array(
			'laao_ads_db_version'               => 'aggr_db_version',
			'laao_ads_plugin_version'           => 'aggr_plugin_version',
			'laao_ads_roles_version'            => 'aggr_roles_version',
			'laao_ads_rewrite_version'          => 'aggr_rewrite_version',
			'laao_ads_seed_version'             => 'aggr_seed_version',
			'laao_ads_upgrade_lock'             => 'aggr_upgrade_lock',
			'laao_ads_delete_data_on_uninstall' => 'aggr_delete_data_on_uninstall',
			'laao_ads_settings'                 => 'aggr_settings',
		);
	}

	/**
	 * Custom role slugs.
	 *
	 * @return array<string, string>
	 */
	public static function roles(): array {
		return array(
			'laao_ads_advertiser' => 'aggr_advertiser',
			'laao_ads_reviewer'   => 'aggr_reviewer',
		);
	}

	/**
	 * Cron hook names that would otherwise fire into nothing after the rename.
	 *
	 * @return array<string, string>
	 */
	public static function cron_hooks(): array {
		return array(
			'laao_ads_reconcile_campaigns'             => 'aggr_reconcile_campaigns',
			'laao_ads_notify_ending_soon'              => 'aggr_notify_ending_soon',
			'laao_ads_purge_private_creatives'         => 'aggr_purge_private_creatives',
			'laao_ads_retry_submission_notifications'  => 'aggr_retry_submission_notifications',
			'laao_ads_retry_advertiser_notifications'  => 'aggr_retry_advertiser_notifications',
			'laao_ads_retry_ending_soon_notifications' => 'aggr_retry_ending_soon_notifications',
		);
	}

	/**
	 * Filters kept as one-release aliases of the new names.
	 *
	 * @return array<string, string>
	 */
	public static function filter_aliases(): array {
		return array(
			'laao_ads_portal_base'          => 'aggr_portal_base',
			'laao_ads_signup_enabled'       => 'aggr_signup_enabled',
			'laao_ads_roles_receiving_caps' => 'aggr_roles_receiving_caps',
		);
	}

	/**
	 * Actions kept as one-release aliases of the new names.
	 *
	 * @return array<string, string>
	 */
	public static function action_aliases(): array {
		return array(
			'laao_ads_campaign_transitioned'        => 'aggr_campaign_transitioned',
			'laao_ads_notify_campaign_transitioned' => 'aggr_notify_campaign_transitioned',
		);
	}

	/**
	 * Primitive and generated capabilities, including the publish alias.
	 *
	 * @return array<string, string>
	 */
	public static function capabilities(): array {
		$map = array(
			'laao_ads_access_portal'       => 'aggr_access_portal',
			'laao_ads_upload_creative'     => 'aggr_upload_creative',
			'laao_ads_submit_campaign'     => 'aggr_submit_campaign',
			'laao_ads_review_campaigns'    => 'aggr_review_campaigns',
			'laao_ads_publish_to_adsanity' => 'aggr_publish',
			'laao_ads_manage_placements'   => 'aggr_manage_placements',
			'laao_ads_manage_packages'     => 'aggr_manage_packages',
			'laao_ads_manage_orgs'         => 'aggr_manage_orgs',
			'laao_ads_view_audit_log'      => 'aggr_view_audit_log',
			'laao_ads_manage_settings'     => 'aggr_manage_settings',
		);

		$generated = array(
			'edit_',
			'edit_others_',
			'edit_private_',
			'edit_published_',
			'publish_',
			'read_private_',
			'delete_',
			'delete_others_',
			'delete_private_',
			'delete_published_',
			'create_',
		);

		foreach ( self::post_types() as $old => $new ) {
			foreach ( array( 'edit_', 'read_', 'delete_' ) as $prefix ) {
				$map[ $prefix . $old ] = $prefix . $new;
			}

			foreach ( $generated as $prefix ) {
				$map[ $prefix . $old . 's' ] = $prefix . $new . 's';
			}
		}

		return $map;
	}

	/**
	 * The previous option key for a current one, if this was a rename.
	 *
	 * @param string $current Current option name.
	 * @return string|null
	 */
	public static function legacy_option_key( string $current ): ?string {
		$flip = array_flip( self::option_keys() );

		return $flip[ $current ] ?? null;
	}
}
