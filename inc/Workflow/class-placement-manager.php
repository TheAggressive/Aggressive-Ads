<?php
/**
 * Authorized placement catalogue writes.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Audit\Audit_Event;
use Aggressive\Ads\Domain\Ad_Sizes;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Audit_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\Security\Capabilities;
use WP_Error;

/**
 * Validates, persists, verifies, and audits one placement change.
 */
final class Placement_Manager {

	public const MAX_NAME_LENGTH = 120;
	public const MAX_SORT_ORDER  = 9999;

	/**
	 * Constructor.
	 *
	 * @param Placement_Repository $placements Placement persistence.
	 * @param Audit_Repository     $audit      Audit persistence.
	 * @param Fill_Cache           $cache      Native fill cache to bust.
	 */
	public function __construct(
		private readonly Placement_Repository $placements,
		private readonly Audit_Repository $audit,
		private readonly Fill_Cache $cache
	) {
	}

	/**
	 * Creates a placement from a validated field set.
	 *
	 * @param array<string, mixed> $input Raw, already-allowlisted fields.
	 * @return int|WP_Error
	 */
	public function create( array $input ) {
		$fields = $this->validated( $input, 0 );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) ) {
			$this->record( 0, Audit_Event::OUTCOME_DENIED, 'Placement create denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage placements.', 'aggressive-ads' )
			);
		}

		$placement_id = $this->placements->create( $fields['name'], $fields['slug'] );

		if ( is_wp_error( $placement_id ) ) {
			$code = $placement_id->get_error_code();

			if ( in_array( $code, array( 'aggr_placement_limit', 'aggr_placement_slug_taken', 'aggr_invalid_placement_slug' ), true ) ) {
				return $placement_id;
			}

			$this->record( 0, Audit_Event::OUTCOME_FAILED, 'Placement create failed.' );

			return new WP_Error(
				'aggr_placement_not_saved',
				__( 'The placement could not be created.', 'aggressive-ads' )
			);
		}

		if ( ! $this->placements->save( $placement_id, $fields ) ) {
			$this->placements->delete( $placement_id );
			$this->record( $placement_id, Audit_Event::OUTCOME_FAILED, 'Placement create failed.' );

			return new WP_Error(
				'aggr_placement_not_saved',
				__( 'The placement could not be created.', 'aggressive-ads' )
			);
		}

		$house = $this->apply_house( $placement_id, $input );

		if ( is_wp_error( $house ) ) {
			$this->placements->delete( $placement_id );

			return $house;
		}

		$this->audit->insert(
			new Audit_Event(
				event: 'placement.created',
				object_type: 'placement',
				object_id: $placement_id,
				message: 'Placement created.',
				context: array(
					'slug'      => $fields['slug'],
					'size'      => $fields['size'],
					'is_active' => $fields['is_active'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return $placement_id;
	}

	/**
	 * Updates an existing placement.
	 *
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $input        Raw, already-allowlisted fields.
	 * @return true|WP_Error
	 */
	public function update( int $placement_id, array $input ) {
		if ( ! current_user_can( Capabilities::MANAGE_PLACEMENTS ) || ! current_user_can( 'edit_aggr_placement', $placement_id ) ) {
			$this->record( $placement_id, Audit_Event::OUTCOME_DENIED, 'Placement update denied.' );

			return new WP_Error(
				'aggr_forbidden',
				__( 'You do not have permission to manage placements.', 'aggressive-ads' )
			);
		}

		if ( ! $this->placements->exists( $placement_id ) ) {
			return new WP_Error(
				'aggr_placement_not_found',
				__( 'That placement could not be found.', 'aggressive-ads' )
			);
		}

		$fields = $this->validated( $input, $placement_id );

		if ( is_wp_error( $fields ) ) {
			return $fields;
		}

		if ( ! $this->placements->save( $placement_id, $fields ) ) {
			$this->record( $placement_id, Audit_Event::OUTCOME_FAILED, 'Placement update failed.' );

			return new WP_Error(
				'aggr_placement_not_saved',
				__( 'The placement could not be saved.', 'aggressive-ads' )
			);
		}

		$house = $this->apply_house( $placement_id, $input );

		if ( is_wp_error( $house ) ) {
			return $house;
		}

		$this->cache->delete( $placement_id );

		$this->audit->insert(
			new Audit_Event(
				event: 'placement.updated',
				object_type: 'placement',
				object_id: $placement_id,
				message: 'Placement updated.',
				context: array(
					'slug'      => $fields['slug'],
					'size'      => $fields['size'],
					'is_active' => $fields['is_active'],
				),
				actor_user_id: get_current_user_id()
			)
		);

		return true;
	}

	/**
	 * Normalizes catalogue fields. House is applied separately.
	 *
	 * @param array<string, mixed> $input        Raw fields.
	 * @param int                  $placement_id Existing id, or 0 on create.
	 * @return array{name: string, slug: string, size: string, is_active: bool, sort_order: int}|WP_Error
	 */
	private function validated( array $input, int $placement_id ) {
		$name = trim( sanitize_text_field( isset( $input['name'] ) && is_string( $input['name'] ) ? $input['name'] : '' ) );

		if ( '' === $name || strlen( $name ) > self::MAX_NAME_LENGTH ) {
			return new WP_Error(
				'aggr_invalid_placement_name',
				__( 'Enter a placement name.', 'aggressive-ads' )
			);
		}

		$slug = sanitize_title( isset( $input['slug'] ) && is_string( $input['slug'] ) ? $input['slug'] : $name );

		if ( '' === $slug ) {
			return new WP_Error(
				'aggr_invalid_placement_slug',
				__( 'Enter a slot slug.', 'aggressive-ads' )
			);
		}

		$taken = $this->placements->id_by_slug( $slug );

		if ( $taken > 0 && $taken !== $placement_id ) {
			return new WP_Error(
				'aggr_placement_slug_taken',
				__( 'That slot slug is already in use.', 'aggressive-ads' )
			);
		}

		$size = $this->size_from_input( $input );

		if ( null === $size ) {
			return new WP_Error(
				'aggr_invalid_placement_size',
				__( 'Choose a common size or enter a custom width and height in pixels.', 'aggressive-ads' )
			);
		}

		$sort = isset( $input['sort_order'] ) ? (int) $input['sort_order'] : 0;

		if ( $sort < 0 || $sort > self::MAX_SORT_ORDER ) {
			return new WP_Error(
				'aggr_invalid_placement_sort',
				__( 'Sort order must be between 0 and 9999.', 'aggressive-ads' )
			);
		}

		return array(
			'name'       => $name,
			'slug'       => $slug,
			'size'       => $size,
			'is_active'  => ! empty( $input['is_active'] ),
			'sort_order' => $sort,
		);
	}

	/**
	 * Resolves the stored size from a preset or custom dimensions.
	 *
	 * @param array<string, mixed> $input Raw fields.
	 */
	private function size_from_input( array $input ): ?string {
		$preset = isset( $input['size_preset'] ) && is_string( $input['size_preset'] ) ? $input['size_preset'] : '';

		if ( Ad_Sizes::CUSTOM === $preset ) {
			$width  = isset( $input['size_width'] ) ? (int) $input['size_width'] : 0;
			$height = isset( $input['size_height'] ) ? (int) $input['size_height'] : 0;
			$size   = Ad_Sizes::from_dimensions( $width, $height );

			return Ad_Sizes::is_valid( $size ) ? $size : null;
		}

		if ( Ad_Sizes::is_listed( $preset ) && Ad_Sizes::is_valid( $preset ) ) {
			return $preset;
		}

		$size = isset( $input['size'] ) && is_string( $input['size'] ) ? $input['size'] : '';

		return Ad_Sizes::is_valid( $size ) ? $size : null;
	}

	/**
	 * Stores house creative when the form sent those fields.
	 *
	 * @param int                  $placement_id Placement post id.
	 * @param array<string, mixed> $input        Raw fields.
	 * @return true|WP_Error
	 */
	private function apply_house( int $placement_id, array $input ) {
		if ( ! array_key_exists( 'house_attachment_id', $input ) ) {
			return true;
		}

		$attachment_id = isset( $input['house_attachment_id'] ) ? (int) $input['house_attachment_id'] : 0;
		$click_url     = isset( $input['house_click_url'] ) && is_string( $input['house_click_url'] ) ? trim( $input['house_click_url'] ) : '';
		$alt           = isset( $input['house_alt'] ) && is_string( $input['house_alt'] ) ? sanitize_text_field( $input['house_alt'] ) : '';

		if ( $attachment_id < 0 ) {
			$attachment_id = 0;
		}

		if ( $attachment_id > 0 ) {
			$type = $this->placements->attachment_type( $attachment_id );

			if (
				null === $type
				|| ! Upload_Rules::is_allowed_mime( $type['mime'] )
				|| ( '' !== $type['extension'] && ! Upload_Rules::is_allowed_extension( $type['extension'] ) )
			) {
				return new WP_Error(
					'aggr_invalid_house_attachment',
					__( 'House creative must be a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' )
				);
			}

			$file = $this->placements->attachment_file( $attachment_id );

			if ( '' === $file || ! is_readable( $file ) ) {
				return new WP_Error(
					'aggr_invalid_house_attachment',
					__( 'House creative must be a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' )
				);
			}

			$dimensions = getimagesize( $file );

			if ( ! is_array( $dimensions ) ) {
				return new WP_Error(
					'aggr_invalid_house_attachment',
					__( 'House creative must be a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' )
				);
			}

			$detected = (string) $dimensions['mime'];

			if (
				! Upload_Rules::is_allowed_mime( $detected )
				|| Upload_Rules::exceeds_pixels( (int) $dimensions[0], (int) $dimensions[1] )
			) {
				return new WP_Error(
					'aggr_invalid_house_attachment',
					__( 'House creative must be a JPEG, PNG, GIF, or WebP image.', 'aggressive-ads' )
				);
			}

			if ( ! Campaign_Rules::is_valid_click_url( $click_url ) || false === wp_http_validate_url( $click_url ) ) {
				return new WP_Error(
					'aggr_invalid_house_url',
					__( 'House destination must be an http or https URL without credentials.', 'aggressive-ads' )
				);
			}
		}

		if (
			$this->placements->house_attachment_id( $placement_id ) === $attachment_id
			&& $this->placements->house_click_url( $placement_id ) === $click_url
			&& $this->placements->house_alt( $placement_id ) === $alt
		) {
			return true;
		}

		if ( ! $this->placements->set_house( $placement_id, $attachment_id, $click_url, $alt ) ) {
			$this->record( $placement_id, Audit_Event::OUTCOME_FAILED, 'Placement house write failed.' );

			return new WP_Error(
				'aggr_house_not_saved',
				__( 'The house creative could not be saved.', 'aggressive-ads' )
			);
		}

		$this->cache->delete( $placement_id );

		return true;
	}

	/**
	 * Records a denied or failed change without request payloads.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $outcome      Audit outcome.
	 * @param string $message      Fixed summary.
	 */
	private function record( int $placement_id, string $outcome, string $message ): void {
		$this->audit->insert(
			new Audit_Event(
				event: 'placement.write_failed',
				outcome: $outcome,
				object_type: 'placement',
				object_id: max( 0, $placement_id ),
				message: $message,
				actor_user_id: get_current_user_id()
			)
		);
	}
}
