<?php
/**
 * Provider boundary for campaign publication and delivery lifecycle effects.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Integration;

use Aggressive\Ads\Domain\Publication_Result;
use WP_Error;

/**
 * The provider operations campaign orchestration is allowed to depend on.
 *
 * Provider-specific post types, taxonomies, metadata, and reconciliation
 * details stay behind this boundary. The portal remains the system of record;
 * these operations only project its authoritative state into a delivery
 * system. See ADR-0006.
 */
interface Ad_Provider_Interface {

	/**
	 * Creates or reconciles every provider object for a campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return Publication_Result|WP_Error
	 */
	public function publish_campaign( int $campaign_id );

	/**
	 * Permanently removes a campaign from provider rotation.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function unpublish_campaign( int $campaign_id );

	/**
	 * Reversibly removes a campaign from provider rotation.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function suppress_campaign( int $campaign_id );

	/**
	 * Restores a suppressed campaign using its authoritative current window.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return true|WP_Error
	 */
	public function resume_campaign( int $campaign_id );

	/**
	 * Reconciles one reviewed replacement onto an existing provider ad.
	 *
	 * The current creative remains authoritative unless this operation returns
	 * true; implementations must restore it when replacement verification fails.
	 *
	 * @param int $campaign_id       Campaign id.
	 * @param int $current_id        Current creative id.
	 * @param int $replacement_id    Reviewed replacement id.
	 * @return true|WP_Error
	 */
	public function replace_creative( int $campaign_id, int $current_id, int $replacement_id );

	/**
	 * Reconciles one current creative back onto its existing provider ad.
	 *
	 * @param int $campaign_id Campaign id.
	 * @param int $creative_id Current creative id.
	 * @return true|WP_Error
	 */
	public function restore_creative( int $campaign_id, int $creative_id );

	/**
	 * Effect handlers keyed by the transition table's provider effect names.
	 *
	 * @return array<string, callable>
	 */
	public function transition_effects(): array;
}
