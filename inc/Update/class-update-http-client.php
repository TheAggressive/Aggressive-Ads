<?php
/**
 * Bounded HTTP access for the plugin updater.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Update;

/**
 * Makes SSRF-safe requests to the updater's fixed GitHub endpoints.
 */
final class Update_Http_Client {

	/**
	 * Fetch a remote updater resource.
	 *
	 * @param string               $url  Trusted HTTPS URL.
	 * @param array<string, mixed> $args WordPress HTTP arguments.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function get( string $url, array $args = array() ): array|\WP_Error {
		$timeout         = min( 5, max( 1, (int) ( $args['timeout'] ?? 3 ) ) );
		$args['timeout'] = $timeout;

		if ( function_exists( 'vip_safe_wp_remote_get' ) ) {
			$response = \vip_safe_wp_remote_get( $url, false, 3, $timeout, 20, $args );

			return is_array( $response ) ? $response : new \WP_Error( 'laao_ads_update_request_failed' );
		}

		return wp_safe_remote_get( $url, $args );
	}
}
