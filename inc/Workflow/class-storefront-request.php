<?php
/**
 * A dated package request enters the existing campaign draft/review workflow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Security\Capabilities;

/** No reservation is created by browsing or drafting a campaign request. */
final class Storefront_Request {

	/**
	 * Compose the existing campaign writer and package duration policy.
	 *
	 * @param Campaign_Editor    $editor Existing authorization and snapshot writer.
	 * @param Package_Repository $packages Package duration policy.
	 */
	public function __construct( private readonly Campaign_Editor $editor, private readonly Package_Repository $packages ) {}

	/**
	 * Resolve a complete plan without creating a draft.
	 *
	 * @param int $package Package id.
	 * @param int $start Start as UTC seconds, at local midnight.
	 * @param int $end End as UTC seconds, ignored for fixed-duration packages.
	 * @return array{package_id:int,start_ts:int,end_ts:int}|\WP_Error
	 */
	public function plan( int $package, int $start, int $end ): array|\WP_Error {
		if ( ! current_user_can( Capabilities::SUBMIT_CAMPAIGN ) || ! current_user_can( 'create_aggr_campaigns' ) ) {
			return new \WP_Error( 'aggr_forbidden', __( 'You do not have permission to create a campaign.', 'aggressive-ads' ) );
		}
		if ( $package <= 0 ) {
			return new \WP_Error( 'aggr_package_required', __( 'Choose a package.', 'aggressive-ads' ) );
		}
		$snapshot = $this->editor->package_snapshot( $package );
		if ( is_wp_error( $snapshot ) ) {
			return $snapshot;
		}
		if ( ! $this->packages->has_custom_duration( $package ) && $start > 0 ) {
			$end = Campaign_Rules::fixed_end_ts( $start, $this->packages->duration_days( $package ), wp_timezone()->getName() );
		}
		$valid = Campaign_Rules::validate_window( $start, $end, Campaign_Rules::day_start_ts( time(), wp_timezone()->getName() ) );
		$valid->absorb( Campaign_Rules::validate_day_boundaries( $start, $end, wp_timezone()->getName() ) );
		if ( ! $valid->is_valid() ) {
			$problem = $valid->problems()[0];
			return new \WP_Error( 'aggr_inventory_window_invalid', Campaign_Validator::message_for( $problem['code'], $problem['context'] ) );
		}
		return array(
			'package_id' => $package,
			'start_ts'   => $start,
			'end_ts'     => $end,
		);
	}

	/**
	 * Revalidate on POST, then use membership-scoped creation and revision-checked saving.
	 *
	 * @param int $package Package id.
	 * @param int $start Start timestamp.
	 * @param int $end End timestamp.
	 * @return int|\WP_Error
	 */
	public function create( int $package, int $start, int $end ): int|\WP_Error {
		$plan = $this->plan( $package, $start, $end );
		if ( is_wp_error( $plan ) ) {
			return $plan;
		}
		$id = $this->editor->create();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$saved = $this->editor->save( $id, $plan, 0 );
		if ( is_wp_error( $saved ) ) {
			$saved->add_data( array( 'campaign_id' => $id ) );
			return $saved;
		}
		return $id;
	}
}
