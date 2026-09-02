<?php
/**
 * Nonce action names for advertiser campaign operations.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/**
 * The shared vocabulary between a campaign form and the handler that receives it.
 *
 * Split out of `Campaign_Actions` because a template needs the *name* of an
 * action, not the class that performs it. Twelve pure, dependency-free string
 * builders sitting among request handlers made the handler class the thing
 * every form partial had to reach for, and pushed it to within five lines of
 * the file-length gate.
 *
 * The action constants stay on `Campaign_Actions`, which is where they are
 * registered with `admin_post`; this class only derives per-campaign names from
 * them. Scoping a nonce to one campaign id is what stops a token minted for one
 * campaign from authorising a write to another.
 */
final class Campaign_Nonces {

	/**
	 * Nonce action for one campaign's staff-action request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function action_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::REQUEST_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for withdrawing one campaign's staff-action request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function withdraw_action_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::REQUEST_WITHDRAW . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's cancellation.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function cancel_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::CANCEL_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action bound to one campaign.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function save_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::SAVE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action bound to the campaign being copied.
	 *
	 * @param int $campaign_id Source campaign post id.
	 * @return string
	 */
	public static function copy_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::COPY_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's package selection.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function package_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::SAVE_PACKAGE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's destination-and-schedule step.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function schedule_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::SAVE_SCHEDULE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's final submission.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function submit_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::SUBMIT_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's withdrawal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function withdraw_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::WITHDRAW_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function changes_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::CHANGES_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for cancelling one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function cancel_changes_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::CHANGES_CANCEL . '_' . max( 0, $campaign_id );
	}

	/**
	 * Nonce action for submitting one campaign's change proposal.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function submit_changes_nonce_action( int $campaign_id ): string {
		return Campaign_Actions::CHANGES_SUBMIT . '_' . max( 0, $campaign_id );
	}
}
