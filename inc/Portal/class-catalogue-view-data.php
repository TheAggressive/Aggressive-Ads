<?php
/**
 * Advertiser-facing placement, package, and help catalogue data.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use Aggressive\Ads\Core\Post_Statuses;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Package_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Workflow\Campaign_Editor;

/**
 * Keeps catalogue derivation out of the multi-screen View_Data coordinator.
 */
final class Catalogue_View_Data {

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Package_Repository   $packages   Package persistence.
	 * @param Campaign_Editor      $editor     Canonical package validation.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Package_Repository $packages,
		private readonly Campaign_Editor $editor
	) {
	}

	/**
	 * Help content derived from domain rules and registered statuses.
	 *
	 * @return array<string, mixed>
	 */
	public function help(): array {
		$statuses = array();

		foreach ( Post_Statuses::all() as $status ) {
			$object     = get_post_status_object( $status );
			$statuses[] = array(
				'label'       => null === $object ? $status : (string) $object->label,
				'pill'        => View_Data::pill_for( $status ),
				'description' => self::status_description( $status ),
			);
		}

		$labels = array(
			'image/jpeg' => 'JPEG',
			'image/png'  => 'PNG',
			'image/gif'  => 'GIF',
			'image/webp' => 'WebP',
		);
		$types  = array();

		foreach ( Upload_Rules::ALLOWED_MIME as $mime ) {
			$types[] = $labels[ $mime ];
		}

		return array(
			'statuses'   => $statuses,
			'placements' => $this->placement_options(),
			'max_size'   => size_format( Upload_Rules::MAX_BYTES ),
			'file_types' => array_values( array_unique( $types ) ),
			'contact'    => (string) get_option( 'admin_email', '' ),
		);
	}

	/**
	 * Active placements with preparation details.
	 *
	 * @return array<int, array{id: int, name: string, size: string}>
	 */
	public function placement_options(): array {
		$options = array();

		foreach ( $this->placements->active_ids() as $placement_id ) {
			$options[] = array(
				'id'   => $placement_id,
				'name' => $this->placements->name( $placement_id ),
				'size' => $this->placements->size( $placement_id ),
			);
		}

		return $options;
	}

	/**
	 * Active, complete packages with advertiser-facing details.
	 *
	 * @return array<int, array{id: int, name: string, duration: string, price: string, placements: array<int, string>, is_default: bool}>
	 */
	public function package_options(): array {
		$options    = array();
		$default_id = $this->packages->default_id();

		foreach ( $this->packages->active_ids() as $package_id ) {
			$snapshot = $this->editor->package_snapshot( $package_id );

			if ( is_wp_error( $snapshot ) ) {
				continue;
			}

			$placement_names = array();

			foreach ( $snapshot['placement_ids'] as $placement_id ) {
				$name              = $this->placements->name( $placement_id );
				$size              = $this->placements->size( $placement_id );
				$placement_names[] = '' === $size ? $name : sprintf( '%1$s (%2$s px)', $name, $size );
			}

			$duration  = $this->packages->duration_days( $package_id );
			$options[] = array(
				'id'         => $package_id,
				'name'       => $this->packages->name( $package_id ),
				'duration'   => $this->packages->has_custom_duration( $package_id )
					? __( 'Custom schedule', 'aggressive-ads' )
					: sprintf(
						/* translators: %s: number of days. */
						_n( '%s day', '%s days', $duration, 'aggressive-ads' ),
						number_format_i18n( $duration )
					),
				'price'      => sprintf( '%1$s %2$s', $snapshot['currency'], number_format_i18n( $snapshot['budget_cents'] / 100, 2 ) ),
				'placements' => $placement_names,
				'is_default' => $package_id === $default_id,
			);
		}

		return $options;
	}

	/**
	 * Advertiser-facing status explanation.
	 *
	 * @param string $status Status slug.
	 * @return string
	 */
	private static function status_description( string $status ): string {
		return match ( $status ) {
			Post_Statuses::DRAFT     => __( 'Yours to edit. Nobody else can see it yet.', 'aggressive-ads' ),
			Post_Statuses::SUBMITTED => __( 'Waiting for the review team. You can still withdraw it until someone starts reviewing.', 'aggressive-ads' ),
			Post_Statuses::REVIEW    => __( 'Someone is reviewing it now.', 'aggressive-ads' ),
			Post_Statuses::CHANGES   => __( 'The review team has asked for changes. Edit it and submit again.', 'aggressive-ads' ),
			Post_Statuses::REJECTED  => __( 'Not approved. The reason is on the campaign.', 'aggressive-ads' ),
			Post_Statuses::APPROVED  => __( 'Approved, and it will start on its scheduled date.', 'aggressive-ads' ),
			Post_Statuses::SCHEDULED => __( 'Ready and waiting for its start date.', 'aggressive-ads' ),
			Post_Statuses::LIVE      => __( 'Being shown on the site right now.', 'aggressive-ads' ),
			Post_Statuses::PAUSED    => __( 'Temporarily not being shown. Get in touch if this is unexpected.', 'aggressive-ads' ),
			Post_Statuses::COMPLETE  => __( 'Finished. Duplicate it to run the campaign again.', 'aggressive-ads' ),
			default                  => __( 'Cancelled and no longer running.', 'aggressive-ads' ),
		};
	}
}
