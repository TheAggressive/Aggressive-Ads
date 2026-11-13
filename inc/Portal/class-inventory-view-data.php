<?php
/**
 * Advertiser inventory catalogue and a single dated package assessment.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Domain\Inventory_Availability;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Inventory_Availability_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use Aggressive\Ads\Security\Rate_Limiter;
use Aggressive\Ads\Workflow\Campaign_Editor;
use Aggressive\Ads\Workflow\Storefront_Request;

/** No forecast or reservation row is part of the advertiser view model. */
final class Inventory_View_Data {

	/**
	 * Compose catalogue presentation, shared plan validation and private assessments.
	 *
	 * @param Catalogue_View_Data               $catalogue Shared sellable package presentation.
	 * @param Campaign_Editor                   $editor Authoritative package validation.
	 * @param Placement_Repository              $placements Public preparation details and house images.
	 * @param Storefront_Request                $requests Shared package/window validation.
	 * @param Inventory_Availability_Repository $availability Bounded private planning reads.
	 * @param Rate_Limiter                      $limiter Per-user assessment budget.
	 */
	public function __construct(
		private readonly Catalogue_View_Data $catalogue,
		private readonly Campaign_Editor $editor,
		private readonly Placement_Repository $placements,
		private readonly Storefront_Request $requests,
		private readonly Inventory_Availability_Repository $availability,
		private readonly Rate_Limiter $limiter
	) {}

	/**
	 * Browse without writes to inventory, forecasts or campaigns.
	 *
	 * @param array<string, mixed> $input Slashed query fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function view( array $input ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::ACCESS_PORTAL ) ) {
			return new \WP_Error( 'aggr_forbidden', __( 'You do not have permission to do that.', 'aggressive-ads' ) );
		}
		$packages = $this->catalogue->package_options();
		$first    = $packages[0]['id'] ?? 0;
		foreach ( $packages as $package ) {
			if ( $package['is_default'] ) {
				$first = $package['id'];
			}
		}
		$input     += array(
			'package_id' => $first,
			'start_date' => ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' ),
			'end_date'   => '',
		);
		$selection  = Inventory_Input::read( $input );
		$package_id = is_wp_error( $selection ) ? 0 : $selection['package_id'];
		$selected   = null;
		foreach ( $packages as $package ) {
			if ( $package['id'] === $package_id ) {
				$selected = $package;
			}
		}
		$model = array(
			'packages'     => $packages,
			'selected'     => $selected,
			'start_date'   => is_string( $input['start_date'] ) ? mb_substr( sanitize_text_field( wp_unslash( $input['start_date'] ) ), 0, 10 ) : '',
			'end_date'     => is_string( $input['end_date'] ) ? mb_substr( sanitize_text_field( wp_unslash( $input['end_date'] ) ), 0, 10 ) : '',
			'timezone'     => wp_timezone()->getName(),
			'min_date'     => wp_date( 'Y-m-d', time(), wp_timezone() ),
			'placements'   => array(),
			'formats'      => Upload_Rules::type_list(),
			'error'        => '',
			'plan'         => null,
			'availability' => '',
		);
		if ( array() === $packages ) {
			return $model;
		}
		if ( is_wp_error( $selection ) ) {
			$model['error'] = $selection->get_error_message();
			return $model;
		}
		$snapshot = $this->editor->package_snapshot( $package_id );
		if ( null === $selected || is_wp_error( $snapshot ) ) {
			$model['error'] = __( 'That package is no longer available. Choose another package.', 'aggressive-ads' );
			return $model;
		}
		foreach ( $snapshot['placement_ids'] as $id ) {
			$model['placements'][] = array(
				'name'     => $this->placements->name( $id ),
				'size'     => $this->placements->size( $id ),
				'max_size' => size_format( $this->placements->max_bytes( $id ) ),
				'image'    => $this->placements->public_house_image_url( $id ),
			);
		}
		if ( '1' !== ( $input['check'] ?? '' ) ) {
			return $model;
		}
		$plan = $this->requests->plan( $package_id, $selection['start_ts'], $selection['end_ts'] );
		if ( is_wp_error( $plan ) ) {
			$model['error'] = $plan->get_error_message();
			return $model;
		}
		$model['plan']     = $plan;
		$model['end_date'] = wp_date( 'Y-m-d', $plan['end_ts'], wp_timezone() );
		$allowed           = $this->limiter->attempt( Rate_Limiter::ACTION_INVENTORY, get_current_user_id() );
		$state             = is_wp_error( $allowed ) ? $allowed : $this->availability->assess( $snapshot['placement_ids'], $plan['start_ts'], $plan['end_ts'], time() );
		if ( is_wp_error( $state ) ) {
			$model['error'] = $state->get_error_message();
		} else {
			$model['availability'] = match ( $state ) {
				Inventory_Availability::AVAILABLE => __( 'Available to request', 'aggressive-ads' ),
				Inventory_Availability::UNAVAILABLE => __( 'Unavailable for these dates', 'aggressive-ads' ),
				default => __( 'Limited — confirm with the publisher', 'aggressive-ads' ),
			};
		}
		return $model;
	}
}
