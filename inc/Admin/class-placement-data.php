<?php
/**
 * Read model for the staff placement catalogue.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Domain\Ad_Sizes;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Repository\Placement_Repository;

/**
 * Assembles placement rows and the common-size catalogue.
 */
final class Placement_Data {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement catalogue.
	 */
	public function __construct(
		private readonly Placement_Repository $placements
	) {
	}

	/**
	 * Complete inventory-screen state.
	 *
	 * @return array{sizes: array<string, string>, rows: array<int, array{id: int, name: string, slug: string, size: string, size_preset: string, size_width: int, size_height: int, active: bool, sort_order: int, house_attachment_id: int, house_click_url: string, house_alt: string}>}
	 */
	public function view(): array {
		$rows = array();

		foreach ( $this->placements->all_ids() as $placement_id ) {
			$size   = $this->placements->size( $placement_id );
			$parsed = Campaign_Rules::parse_size( $size );

			$rows[] = array(
				'id'                  => $placement_id,
				'name'                => $this->placements->name( $placement_id ),
				'slug'                => $this->placements->slug( $placement_id ),
				'size'                => $size,
				'size_preset'         => Ad_Sizes::is_listed( $size ) ? $size : Ad_Sizes::CUSTOM,
				'size_width'          => $parsed[0] ?? 0,
				'size_height'         => $parsed[1] ?? 0,
				'active'              => $this->placements->is_active( $placement_id ),
				'sort_order'          => $this->placements->sort_order( $placement_id ),
				'house_attachment_id' => $this->placements->house_attachment_id( $placement_id ),
				'house_click_url'     => $this->placements->house_click_url( $placement_id ),
				'house_alt'           => $this->placements->house_alt( $placement_id ),
			);
		}

		return array(
			'sizes' => Ad_Sizes::catalogue(),
			'rows'  => $rows,
		);
	}
}
