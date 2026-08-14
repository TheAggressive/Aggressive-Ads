<?php
/**
 * Authorized package catalogue writes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Ad_Sizes;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Validates, persists, verifies, and audits one package change.
 */
final class Package_Manager {

	public const MAX_DURATION_DAYS = 3650;
	public const MAX_PRICE_CENTS   = 999999999;
	public const MAX_NAME_LENGTH   = 120;

	/**
	 * Constructor.
	 *
	 * @param Package_Repository   $packages   Package persistence.
	 * @param Placement_Repository $placements Placement catalogue.
	 * @param Audit_Repository     $audit      Audit persistence.
	 */
	public function __construct(
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements,
		private readonly Audit_Repository $audit
	) {
	}

	/**
	 * Creates a package from a validated field set.
	 *
	 * @param array<string, mixed> $input Raw, already-allowlisted fields.
	 * @return int|WP_Error
	 */
	public function create( array $input ) {
		$fields = $this->validated( $input );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PACKAGES ) ) {
			$this->record( 0, Audit_Event::OUTCOME_DENIED, 'Package create denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage packages.', 'aggressive-ads' )
			);
		}

		$package_id = $this->packages->create( $fields['name'] );

		if ( is_wp_error( $package_id ) ) {
			$code = $package_id->get_error_code();

			if ( 'aggr_package_limit' === $code ) {
				return new WP_Error(
					'aggr_package_limit',
					__( 'The package catalogue is full.', 'aggressive-ads' )
				);
			}

			$this->record( 0, Audit_Event::OUTCOME_FAILED, 'Package create failed.' );

			return new WP_Error(
				'aggr_package_not_saved',
				__( 'The package could not be created.', 'aggressive-ads' )
			);
		}

		if ( ! $this->packages->save( $package_id, $fields ) ) {
			$this->packages->delete( $package_id );
			$this->record( $package_id, Audit_Event::OUTCOME_FAILED, 'Package create failed.' );

			return new WP_Error(
				'aggr_package_not_saved',
				__( 'The package could not be created.', 'aggressive-ads' )
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'package.created',
				object_type: 'package',
				object_id: $package_id,
				message: 'Package created.',
				context: array(
					'is_active'  => $fields['is_active'],
					'is_default' => $fields['is_default'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $package_id;
	}

	/**
	 * Updates an existing package. Does not rewrite campaign snapshots.
	 *
	 * @param int                  $package_id Package post id.
	 * @param array<string, mixed> $input      Raw, already-allowlisted fields.
	 * @return true|WP_Error
	 */
	public function update( int $package_id, array $input ) {
		if ( ! current_user_can( Capabilities::MANAGE_PACKAGES ) || ! current_user_can( 'edit_aggr_package', $package_id ) ) {
			$this->record( $package_id, Audit_Event::OUTCOME_DENIED, 'Package update denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage packages.', 'aggressive-ads' )
			);
		}

		if ( ! $this->packages->exists( $package_id ) ) {
			return new WP_Error(
				'aggr_package_not_found',
				__( 'That package could not be found.', 'aggressive-ads' )
			);
		}

		$fields = $this->validated( $input );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! $this->packages->save( $package_id, $fields ) ) {
			$this->record( $package_id, Audit_Event::OUTCOME_FAILED, 'Package update failed.' );

			return new WP_Error(
				'aggr_package_not_saved',
				__( 'The package could not be saved.', 'aggressive-ads' )
			);
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'package.updated',
				object_type: 'package',
				object_id: $package_id,
				message: 'Package updated.',
				context: array(
					'is_active'  => $fields['is_active'],
					'is_default' => $fields['is_default'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * Normalizes and validates one field set.
	 *
	 * @param array<string, mixed> $input Raw fields.
	 * @return array{
	 *   name: string,
	 *   placement_ids: array<int, int>,
	 *   duration_days: int,
	 *   custom_duration: bool,
	 *   price_cents: int,
	 *   currency: string,
	 *   is_active: bool,
	 *   is_default: bool
	 * }|WP_Error
	 */
	private function validated( array $input ) {
		$name = trim( sanitize_text_field( isset( $input['name'] ) && is_string( $input['name'] ) ? $input['name'] : '' ) );

		if ( '' === $name || strlen( $name ) > self::MAX_NAME_LENGTH ) {
			return new WP_Error(
				'aggr_invalid_package_name',
				__( 'Enter a package name.', 'aggressive-ads' )
			);
		}

		$raw_ids       = isset( $input['placement_ids'] ) && is_array( $input['placement_ids'] ) ? $input['placement_ids'] : array();
		$placement_ids = array();

		foreach ( $raw_ids as $raw_id ) {
			$id = absint( $raw_id );

			if ( $id > 0 && ! in_array( $id, $placement_ids, true ) ) {
				$placement_ids[] = $id;
			}
		}

		if ( count( $placement_ids ) > Creative_Repository::MAX_PER_CAMPAIGN ) {
			return new WP_Error(
				'aggr_package_too_many_placements',
				sprintf(
					/* translators: %d: maximum placements in one package. */
					__( 'A package may include at most %d placements.', 'aggressive-ads' ),
					Creative_Repository::MAX_PER_CAMPAIGN
				)
			);
		}

		$custom      = ! empty( $input['custom_duration'] );
		$duration    = isset( $input['duration_days'] ) ? absint( $input['duration_days'] ) : 0;
		$price_cents = isset( $input['price_cents'] ) ? (int) $input['price_cents'] : -1;
		$currency    = isset( $input['currency'] ) && is_string( $input['currency'] ) ? strtoupper( sanitize_text_field( $input['currency'] ) ) : '';
		$is_active   = ! empty( $input['is_active'] );
		$is_default  = ! empty( $input['is_default'] );

		if ( $custom ) {
			$duration = 0;
		} elseif ( $duration < 1 || $duration > self::MAX_DURATION_DAYS ) {
			return new WP_Error(
				'aggr_invalid_package_duration',
				__( 'Enter a duration in days, or mark the package as advertiser-scheduled.', 'aggressive-ads' )
			);
		}

		if ( $price_cents < 0 || $price_cents > self::MAX_PRICE_CENTS ) {
			return new WP_Error(
				'aggr_invalid_package_price',
				__( 'Price must be an integer number of cents.', 'aggressive-ads' )
			);
		}

		if ( 1 !== preg_match( '/^[A-Z]{3}$/', $currency ) ) {
			return new WP_Error(
				'aggr_invalid_package_currency',
				__( 'Currency must be a three-letter ISO 4217 code.', 'aggressive-ads' )
			);
		}

		if ( $is_default && ! $is_active ) {
			return new WP_Error(
				'aggr_invalid_package_default',
				__( 'Only an active package can be the catalogue default.', 'aggressive-ads' )
			);
		}

		if ( $is_active && array() === $placement_ids ) {
			return new WP_Error(
				'aggr_invalid_package_placements',
				__( 'An active package must include at least one placement.', 'aggressive-ads' )
			);
		}

		foreach ( $placement_ids as $placement_id ) {
			if ( ! $this->placements->exists( $placement_id ) ) {
				return new WP_Error(
					'aggr_invalid_package_placements',
					__( 'Choose placements that exist in the catalogue.', 'aggressive-ads' )
				);
			}

			if ( $is_active && ( ! $this->placements->is_active( $placement_id ) || ! Ad_Sizes::is_valid( $this->placements->size( $placement_id ) ) ) ) {
				return new WP_Error(
					'aggr_invalid_package_placements',
					__( 'An active package may only include active placements with a valid size.', 'aggressive-ads' )
				);
			}
		}

		return array(
			'name'            => $name,
			'placement_ids'   => $placement_ids,
			'duration_days'   => $duration,
			'custom_duration' => $custom,
			'price_cents'     => $price_cents,
			'currency'        => $currency,
			'is_active'       => $is_active,
			'is_default'      => $is_default,
		);
	}

	/**
	 * Records a denied or failed change without request payloads.
	 *
	 * @param int    $package_id Package post id.
	 * @param string $outcome    Audit outcome.
	 * @param string $message    Fixed summary.
	 */
	private function record( int $package_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'package.update_failed',
				outcome: $outcome,
				object_type: 'package',
				object_id: max( 0, $package_id ),
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
