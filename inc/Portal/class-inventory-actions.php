<?php
/**
 * Progressive, nonce-protected handoff from inventory to a campaign draft.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Service;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Workflow\Storefront_Request;

/** Only the signed-in member's organization can own the created draft. */
final class Inventory_Actions implements Service {

	public const CREATE_ACTION = 'aggr_inventory_request';

	/**
	 * Use the shared campaign workflow for the signed form.
	 *
	 * @param Storefront_Request $requests Existing campaign workflow handoff.
	 */
	public function __construct( private readonly Storefront_Request $requests ) {}

	/** Attach only the authenticated action. */
	public function init(): void {
		add_action( 'admin_post_' . self::CREATE_ACTION, array( $this, 'handle' ) );
	}

	/**
	 * The real form processing entry point, including its authorization and nonce.
	 *
	 * @param array<string, mixed> $input Slashed POST fields.
	 * @return int|\WP_Error
	 */
	public function process( array $input ): int|\WP_Error {
		$nonce = isset( $input['_wpnonce'] ) && is_string( $input['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $input['_wpnonce'] ) ) : '';
		if ( ! is_user_logged_in() || ! current_user_can( Capabilities::ACCESS_PORTAL ) || ! wp_verify_nonce( $nonce, self::CREATE_ACTION ) ) {
			return new \WP_Error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ), array( 'status' => 403 ) );
		}
		$selection = Inventory_Input::read( $input );
		return is_wp_error( $selection ) ? $selection : $this->requests->create( $selection['package_id'], $selection['start_ts'], $selection['end_ts'] );
	}

	/** Preserve a recoverable draft after a failed save; redirects contain no private planning facts. */
	public function handle(): never {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- process() checks capability and nonce, then the shared strict parser allowlists and validates fields.
		$result = $this->process( $_POST );
		$url    = Routes::url( Request::ROUTE_INVENTORY );
		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && 403 === ( $data['status'] ?? 0 ) ) {
				wp_die( esc_html( $result->get_error_message() ), '', array( 'response' => 403 ) );
			}
			$id = is_array( $data ) ? (int) ( $data['campaign_id'] ?? 0 ) : 0;
			if ( $id > 0 ) {
				$url = add_query_arg(
					array(
						'aggr_notice' => 'error',
						'aggr_error'  => 'aggr_campaign_not_saved',
						'step'        => 'details',
					),
					Routes::url( Request::ROUTE_CAMPAIGNS, $id )
				);
			} else {
				$args = array( 'inventory_error' => '1' );
				// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- process() checked authorization and nonce; preserve only strictly validated selection fields after a refusal.
				$selection = Inventory_Input::read( $_POST );
				if ( ! is_wp_error( $selection ) ) {
					$args['package_id'] = $selection['package_id'];
					$args['start_date'] = $selection['start_ts'] > 0 ? wp_date( 'Y-m-d', $selection['start_ts'], wp_timezone() ) : '';
					$args['end_date']   = $selection['end_ts'] > 0 ? wp_date( 'Y-m-d', $selection['end_ts'], wp_timezone() ) : '';
				}
				$url = add_query_arg( $args, $url );
			}
		} else {
			$url = add_query_arg(
				array(
					'step'        => 'details',
					'aggr_notice' => 'created',
				),
				Routes::url( Request::ROUTE_CAMPAIGNS, $result )
			);
		}
		wp_safe_redirect( $url );
		exit;
	}
}
