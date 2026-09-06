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
use Aggressive\Ads\Domain\Refresh_Policy;
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
	 * @return array{sizes: array<string, string>, refresh_defaults: array{enabled: bool, seconds: int, max_per_view: int}, refresh_ceiling: int, rows: array<int, array{id: int, name: string, slug: string, size: string, size_preset: string, size_width: int, size_height: int, active: bool, sort_order: int, refresh_enabled: bool, refresh_seconds: int, refresh_max_per_view: int, house_attachment_id: int, house_click_url: string, house_alt: string, breakpoints: array<int, string>, groups: array<int, string>}>, all_groups: array<int, string>}
	 */
	public function view(): array {
		$rows     = array();
		$defaults = Refresh_Policy::defaults();

		foreach ( $this->placements->all_ids() as $placement_id ) {
			$size   = $this->placements->size( $placement_id );
			$parsed = Campaign_Rules::parse_size( $size );
			$policy = $this->placements->refresh_policy( $placement_id );
			$sizes  = $this->placements->size_map( $placement_id );

			$rows[] = array(
				'id'                   => $placement_id,
				'name'                 => $this->placements->name( $placement_id ),
				'slug'                 => $this->placements->slug( $placement_id ),
				'size'                 => $size,
				'size_preset'          => Ad_Sizes::is_listed( $size ) ? $size : Ad_Sizes::CUSTOM,
				'size_width'           => $parsed[0] ?? 0,
				'size_height'          => $parsed[1] ?? 0,
				'active'               => $this->placements->is_active( $placement_id ),
				'sort_order'           => $this->placements->sort_order( $placement_id ),
				'refresh_enabled'      => $policy->enabled,
				'refresh_seconds'      => $policy->interval_seconds,
				'refresh_max_per_view' => $policy->max_per_view,
				'house_attachment_id'  => $this->placements->house_attachment_id( $placement_id ),
				'house_click_url'      => $this->placements->house_click_url( $placement_id ),
				'house_alt'            => $this->placements->house_alt( $placement_id ),

				/*
				 * Keyed by floor and stringified, because JSON object keys are
				 * strings and a numeric key would come back as one anyway. The
				 * screen sends them back in the same shape, so the round trip
				 * has no place to reorder or re-type them.
				 */
				'breakpoints'          => array_map(
					'strval',
					$sizes->is_responsive() ? $sizes->breakpoints() : array()
				),

				/*
				 * Already normalised and sorted by the repository, so the
				 * screen renders them in a stable order without sorting again
				 * — and two placements filed the same way look the same.
				 */
				'groups'               => $this->placements->groups( $placement_id ),
			);
		}

		return array(
			'sizes'            => Ad_Sizes::catalogue(),
			'refresh_defaults' => array(
				'enabled'      => $defaults->enabled,
				'seconds'      => $defaults->interval_seconds,
				'max_per_view' => $defaults->max_per_view,
			),
			'refresh_ceiling'  => Refresh_Policy::LEGACY_CLIENT_MAX_PER_VIEW,

			/*
			 * Every group in use anywhere, so the form can offer what already
			 * exists instead of making a publisher retype a label exactly and
			 * silently creating a near-duplicate when they do not.
			 */
			'all_groups'       => $this->all_groups( $rows ),
			'rows'             => $rows,
		);
	}

	/**
	 * Every distinct group across the placements already loaded.
	 *
	 * Derived from the rows rather than queried, which costs nothing and
	 * cannot disagree with what the screen is showing.
	 *
	 * A group nobody is filed under does not appear, and that is the intended
	 * reading of "in use". An empty group is not a category of inventory; it is
	 * a label somebody stopped using, and offering it forever is how a
	 * suggestion list becomes useless.
	 *
	 * @param array<int, array<string, mixed>> $rows Placement rows.
	 * @return array<int, string>
	 */
	private function all_groups( array $rows ): array {
		$seen = array();

		foreach ( $rows as $row ) {
			if ( ! isset( $row['groups'] ) || ! is_array( $row['groups'] ) ) {
				continue;
			}

			foreach ( $row['groups'] as $group ) {
				$seen[ (string) $group ] = true;
			}
		}

		$groups = array_keys( $seen );
		sort( $groups );

		return $groups;
	}
}
