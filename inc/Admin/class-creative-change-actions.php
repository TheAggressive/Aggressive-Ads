<?php
/**
 * Staff delivery for creative replacement decisions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Creative_Change_Manager;
use WP_Error;

/**
 * Nonce-protected form edge for replacement approval and rejection.
 */
final class Creative_Change_Actions implements Service {

	public const ACTION = 'aggr_review_creative_replacement';

	/**
	 * Constructor.
	 *
	 * @param Creative_Change_Manager $manager Replacement workflow.
	 */
	public function __construct( private readonly Creative_Change_Manager $manager ) {
	}

	/**
	 * Attaches the authenticated staff handler.
	 *
	 * @return void
	 */
	public function init(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
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

		$replacement_id = isset( $_POST['replacement_id'] ) ? absint( $_POST['replacement_id'] ) : 0;
		$campaign_id    = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;

		check_admin_referer( self::nonce_action( $replacement_id ) );

		$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
		$notes    = isset( $_POST['review_notes'] ) ? sanitize_textarea_field( wp_unslash( $_POST['review_notes'] ) ) : '';
		$result   = $this->process( $replacement_id, $decision, $notes );

		$this->redirect( $campaign_id, $result, 'approve' === $decision ? 'creative_update_approved' : 'creative_update_rejected' );
	}

	/**
	 * Testable staff decision edge.
	 *
	 * @param int    $replacement_id Replacement id.
	 * @param string $decision       approve or reject.
	 * @param string $notes          Rejection feedback.
	 * @return true|WP_Error
	 */
	public function process( int $replacement_id, string $decision, string $notes = '' ): bool|WP_Error {
		if ( ! current_user_can( Capabilities::REVIEW_CAMPAIGNS ) ) {
			return new WP_Error( 'aggr_forbidden', __( 'You do not have permission to review ad updates.', 'aggressive-ads' ) );
		}

		return match ( $decision ) {
			'approve' => $this->manager->approve( $replacement_id ),
			'reject'  => $this->manager->reject( $replacement_id, $notes ),
			default   => new WP_Error( 'aggr_replacement_decision_invalid', __( 'That ad-update decision is not valid.', 'aggressive-ads' ) ),
		};
	}

	/**
	 * Nonce scoped to one replacement revision.
	 *
	 * @param int $replacement_id Replacement id.
	 * @return string
	 */
	public static function nonce_action( int $replacement_id ): string {
		return self::ACTION . '_' . max( 0, $replacement_id );
	}

	/**
	 * Redirects to the owning campaign without reflecting request text.
	 *
	 * @param int           $campaign_id Campaign id.
	 * @param true|WP_Error $result      Workflow result.
	 * @param string        $success     Stable success code.
	 * @return never
	 */
	private function redirect( int $campaign_id, bool|WP_Error $result, string $success ): never {
		$url = add_query_arg(
			array(
				'aggr_result' => is_wp_error( $result ) ? 'error' : 'success',
				'aggr_code'   => is_wp_error( $result ) ? sanitize_key( (string) $result->get_error_code() ) : $success,
			),
			Review_Screen::campaign_url( $campaign_id, 'updates' )
		);

		wp_safe_redirect( $url, 303 );
		exit;
	}
}
