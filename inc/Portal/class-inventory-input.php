<?php
/**
 * Strict input shared by inventory browsing and its nonce-protected request.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

/** Does not accept tenant ids, quantities, prices or reservation decisions. */
final class Inventory_Input {

	/**
	 * Parse only the package and dates, refusing compound or coerced values.
	 *
	 * @param array<string, mixed> $input Slashed query/form fields.
	 * @return array{package_id:int,start_ts:int,end_ts:int}|\WP_Error
	 */
	public static function read( array $input ): array|\WP_Error {
		$raw = $input['package_id'] ?? '';
		$id  = is_string( $raw ) || is_int( $raw ) ? filter_var( $raw, FILTER_VALIDATE_INT, array( 'options' => array( 'min_range' => 1 ) ) ) : false;
		if ( false === $id ) {
			return new \WP_Error( 'aggr_package_required', __( 'Choose a package.', 'aggressive-ads' ) );
		}
		$dates = array();
		foreach ( array( 'start_date', 'end_date' ) as $key ) {
			$raw = $input[ $key ] ?? '';
			if ( ! is_string( $raw ) || strlen( $raw ) > 10 ) {
				return new \WP_Error( 'aggr_inventory_window_invalid', __( 'Enter a valid date in the required format.', 'aggressive-ads' ) );
			}
			$parsed = Date_Input::parse( wp_unslash( $raw ), 'end_date' === $key );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$dates[] = $parsed;
		}
		return array(
			'package_id' => $id,
			'start_ts'   => $dates[0],
			'end_ts'     => $dates[1],
		);
	}
}
