<?php
/**
 * Provider boundary for campaign publication and delivery lifecycle effects.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Integration;

use LAAO_Advertiser_Portal\Domain\Publication_Result;
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
	 * Effect handlers keyed by the transition table's provider effect names.
	 *
	 * @return array<string, callable>
	 */
	public function transition_effects(): array;
}
