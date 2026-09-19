<?php
/**
 * A proposed change, written out for the people who read it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Money;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * Before and after, one row per field, for the advertiser and the reviewer.
 *
 * One presenter for both screens, because a reviewer approving a change
 * described differently from the one the advertiser asked for is the failure
 * the proposal workflow exists to avoid. Its own class since
 * `Campaign_Change_Manager` reached the length gate; it reads nothing and
 * writes nothing, it only words what it is given.
 */
final class Campaign_Change_Summary {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement names.
	 * @param Package_Repository   $packages   Package names and prices.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Package_Repository $packages
	) {
	}

	/**
	 * Renders one change set as before/after rows.
	 *
	 * @param array<string, mixed> $edits   Change set.
	 * @param array<string, mixed> $current The campaign as it stands.
	 * @return array<int, array{field: string, label: string, from: string, to: string}>
	 */
	public function rows( array $edits, array $current ): array {
		if ( array() === $edits ) {
			return array();
		}

		$rows = array();

		foreach ( $edits as $field => $value ) {
			$rows[] = array(
				'field' => (string) $field,
				'label' => self::field_label( (string) $field ),
				'from'  => $this->render( (string) $field, $current[ $field ] ?? null, $current, false ),
				'to'    => $this->render( (string) $field, $value, $current, true ),
			);
		}

		return $rows;
	}

	/**
	 * Human label for one proposal field.
	 *
	 * @param string $field Field name.
	 */
	public static function field_label( string $field ): string {
		switch ( $field ) {
			case 'title':
				return __( 'Campaign name', 'aggressive-ads' );
			case 'advertiser_notes':
				return __( 'Advertiser notes', 'aggressive-ads' );
			case 'start_ts':
				return __( 'Start date', 'aggressive-ads' );
			case 'end_ts':
				return __( 'End date', 'aggressive-ads' );
			case 'click_urls':
				return __( 'Destination', 'aggressive-ads' );
			case 'placement_ids':
				return __( 'Placements', 'aggressive-ads' );
			case 'package_id':
				return __( 'Package', 'aggressive-ads' );
		}

		return $field;
	}

	/**
	 * Displayable form of one field's value.
	 *
	 * @param string               $field    Field name.
	 * @param mixed                $value    Raw value.
	 * @param array<string, mixed> $current  The campaign as it stands.
	 * @param bool                 $proposed Whether this is the new value rather than the old.
	 */
	private function render( string $field, mixed $value, array $current, bool $proposed ): string {
		/*
		 * A package reads as its name and its price, because the price is
		 * what changes hands. The old one at what the campaign was sold for;
		 * the new one at the package's price today, which is what approval
		 * will freeze onto the campaign.
		 */
		if ( 'package_id' === $field ) {
			$package_id = (int) $value;

			if ( $package_id <= 0 ) {
				return '';
			}

			$price = $proposed
				? Money::format( $this->packages->price_cents( $package_id ), $this->packages->currency( $package_id ) )
				: Money::format( (int) ( $current['budget_cents'] ?? 0 ), (string) ( $current['currency'] ?? '' ) );

			return '' === $price ? $this->packages->name( $package_id ) : $this->packages->name( $package_id ) . ' · ' . $price;
		}

		if ( 'start_ts' === $field || 'end_ts' === $field ) {
			$timestamp = (int) $value;

			if ( $timestamp <= 0 ) {
				return __( 'Open-ended', 'aggressive-ads' );
			}

			// wp_date() returns false when the timezone cannot be resolved.
			// Falling back to the raw ISO date keeps a reviewer looking at a
			// date rather than at an empty cell.
			$formatted = wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $timestamp );

			return false === $formatted ? gmdate( 'Y-m-d', $timestamp ) : $formatted;
		}

		if ( 'placement_ids' === $field ) {
			$names = array();

			foreach ( is_array( $value ) ? $value : array() as $placement_id ) {
				$names[] = $this->placements->name( (int) $placement_id );
			}

			return implode( ', ', array_filter( $names ) );
		}

		if ( 'click_urls' === $field ) {
			return implode( ', ', array_map( 'strval', is_array( $value ) ? $value : array() ) );
		}

		return is_string( $value ) ? $value : '';
	}
}
