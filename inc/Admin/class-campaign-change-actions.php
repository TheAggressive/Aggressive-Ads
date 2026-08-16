<?php
/**
 * Staff decisions on advertiser-proposed campaign changes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Campaign_Change_Manager;
use WP_Error;

/**
 * The review half of the running-campaign change workflow.
 *
 * Deliberately a sibling of `Creative_Change_Actions` rather than a branch
 * inside it: the two decide different objects with different capabilities, and
 * folding them together would produce one handler whose behaviour depends on
 * which hidden field arrived.
 */
final class Campaign_Change_Actions implements Service {

	public const ACTION         = 'aggr_review_campaign_changes';
	public const DECLINE_ACTION = 'aggr_decline_campaign_request';

	/**
	 * Constructor.
	 *
	 * @param Campaign_Change_Manager $manager Change workflow.
	 */
	public function __construct( private readonly Campaign_Change_Manager $manager ) {
	}

	/**
	 * Attaches the authenticated staff handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'admin_post_' . self::DECLINE_ACTION, array( $this, 'handle_decline' ) );
	}

	/**
	 * Handles one allowlisted decision.
	 *
	 * @return void
	 */
	public function handle(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ), '', array( 'response' => 403 ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce_action() uses this id immediately below.

		check_admin_referer( self::nonce_action( $campaign_id ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$notes    = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		$result = $this->process( $campaign_id, $decision, $notes );

		$this->redirect(
			$campaign_id,
			$result,
			'approve' === $decision ? 'campaign_changes_approved' : 'campaign_changes_rejected'
		);
	}

	/**
	 * Declines an advertiser's request, with an explanation.
	 *
	 * @return void
	 */
	public function handle_decline(): void {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'aggressive-ads' ), '', array( 'response' => 403 ) );
		}

		$campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- decline_nonce_action() uses this id immediately below.

		check_admin_referer( self::decline_nonce_action( $campaign_id ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified immediately above.
		$notes = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) ) : '';

		$this->redirect( $campaign_id, $this->decline( $campaign_id, $notes ), 'campaign_request_declined' );
	}

	/**
	 * Testable staff decision edge for closing a request.
	 *
	 * A sibling of `process()` and here for the same reason: the delivery layer
	 * — a form post or a REST route — should not be the only way to reach the
	 * decision, or each new delivery path grows its own copy of it.
	 *
	 * No capability check of its own, deliberately. `resolve_action()` carries
	 * one and records the denial in the audit trail; repeating it here would add
	 * a second gate that hides whether the first still works.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $notes       Advertiser-facing explanation.
	 * @return true|WP_Error
	 */
	public function decline( int $campaign_id, string $notes = '' ): bool|WP_Error {
		return $this->manager->resolve_action( $campaign_id, $notes );
	}

	/**
	 * Nonce action for declining one campaign's request.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function decline_nonce_action( int $campaign_id ): string {
		return self::DECLINE_ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Testable staff decision edge.
	 *
	 * @param int    $campaign_id Campaign post id.
	 * @param string $decision    approve or reject.
	 * @param string $notes       Rejection feedback.
	 * @return true|WP_Error
	 */
	public function process( int $campaign_id, string $decision, string $notes = '' ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to review campaign changes.', 'aggressive-ads' ) );
		}

		return match ( $decision ) {
			'approve' => $this->manager->approve( $campaign_id ),
			'reject'  => $this->manager->reject( $campaign_id, $notes ),
			default   => new WP_Error( 'aggr_change_decision_invalid', __( 'That campaign-change decision is not valid.', 'aggressive-ads' ) ),
		};
	}

	/**
	 * Nonce action scoped to one campaign's pending change.
	 *
	 * @param int $campaign_id Campaign post id.
	 * @return string
	 */
	public static function nonce_action( int $campaign_id ): string {
		return self::ACTION . '_' . max( 0, $campaign_id );
	}

	/**
	 * Returns to the campaign with an outcome marker.
	 *
	 * @param int           $campaign_id Campaign post id.
	 * @param true|WP_Error $result     Workflow outcome.
	 * @param string        $notice      Success marker.
	 * @return void
	 */
	private function redirect( int $campaign_id, bool|WP_Error $result, string $notice ): void {
		$url = add_query_arg(
			array(
				'page'     => Review_Screen::MENU_SLUG,
				'campaign' => $campaign_id,
			),
			admin_url( 'admin.php' )
		);

		$url = is_wp_error( $result )
			? add_query_arg( 'aggr_error', rawurlencode( (string) $result->get_error_code() ), $url )
			: add_query_arg( 'aggr_notice', $notice, $url );

		wp_safe_redirect( $url );
		exit;
	}
}
