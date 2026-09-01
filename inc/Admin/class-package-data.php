<?php
/**
 * Read model for the staff package catalogue.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * Assembles package rows and the placement checklist.
 */
final class Package_Data {

	/**
	 * Constructor.
	 *
	 * @param Package_Repository   $packages   Package persistence.
	 * @param Placement_Repository $placements Placement catalogue.
	 */
	public function __construct(
		private readonly Package_Repository $packages,
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Complete package-screen state.
	 *
	 * @return array{default_id: int, placements: array<int, array{id: int, name: string, size: string, active: bool}>, rows: array<int, array{id: int, name: string, placement_ids: array<int, int>, duration_days: int, custom_duration: bool, price_cents: int, currency: string, is_active: bool, is_default: bool}>}
	 */
	public function view(): array {
		$placements = array();

		foreach ( $this->placements->all_ids() as $placement_id ) {
			$placements[] = array(
				'id'     => $placement_id,
				'name'   => $this->placements->name( $placement_id ),
				'size'   => $this->placements->size( $placement_id ),
				'active' => $this->placements->is_active( $placement_id ),
			);
		}

		$rows       = array();
		$default_id = $this->packages->default_id();

		foreach ( $this->packages->all_ids() as $package_id ) {
			$rows[] = array(
				'id'              => $package_id,
				'name'            => $this->packages->name( $package_id ),
				'placement_ids'   => $this->packages->placement_ids( $package_id ),
				'duration_days'   => $this->packages->duration_days( $package_id ),
				'custom_duration' => $this->packages->has_custom_duration( $package_id ),
				'price_cents'     => $this->packages->price_cents( $package_id ),
				'currency'        => $this->packages->currency( $package_id ),
				'is_active'       => $this->packages->is_active( $package_id ),
				'is_default'      => $package_id === $default_id,
			);
		}

		return array(
			'default_id' => $default_id,
			'placements' => $placements,
			'rows'       => $rows,
		);
	}

	/**
	 * Distinct currencies this site's packages are already priced in.
	 *
	 * Presentation data for the screen's currency select rather than part of
	 * `view()`: the table does not render it, and a row shape the browser does
	 * not read is a row shape somebody has to keep in agreement for nothing.
	 *
	 * @return list<string>
	 */
	public function currencies_in_use(): array {
		$found = array();

		foreach ( $this->packages->all_ids() as $package_id ) {
			$code = strtoupper( $this->packages->currency( $package_id ) );

			if ( 1 === preg_match( '/^[A-Z]{3}\z/', $code ) && ! in_array( $code, $found, true ) ) {
				$found[] = $code;
			}
		}

		return $found;
	}
}
