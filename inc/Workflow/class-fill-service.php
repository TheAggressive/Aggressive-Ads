<?php
/**
 * Resolves one slot to a live creative or house ad.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Workflow;

use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Domain\Fill_Rotation;
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Campaign_Repository;
use Aggressive\Ads\Repository\Creative_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;

/**
 * Native fill. The live set is campaign status, not an ads CPT.
 */
final class Fill_Service {

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings   Module and delivery flags.
	 * @param Placement_Repository $placements Slot catalogue.
	 * @param Campaign_Repository  $campaigns  Live membership.
	 * @param Creative_Repository  $creatives  Active artwork.
	 * @param Fill_Cache           $cache      Short-TTL payload cache.
	 * @param Fill_Token           $tokens     Signed beacon/click tokens.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Placement_Repository $placements,
		private readonly Campaign_Repository $campaigns,
		private readonly Creative_Repository $creatives,
		private readonly Fill_Cache $cache,
		private readonly Fill_Token $tokens
	) {
	}

	/**
	 * Whether public native surfaces exist.
	 */
	public function is_enabled(): bool {
		return $this->settings->module_enabled( Settings_Schema::MODULE_NATIVE_DELIVERY );
	}

	/**
	 * House policy for the slot renderer. Cached HTML may include noscript
	 * house; it still must not mint a token.
	 */
	public function house_policy(): string {
		return $this->settings->house_policy();
	}

	/**
	 * Fill payload for a placement slug, or null when the slot does not exist.
	 *
	 * Identity of the candidate set is cached. The winner and tokens are
	 * chosen per request so a TTL hit is not a replay of the previous
	 * visitor's impression, and is not a frozen rotation.
	 *
	 * @param string $slug Placement post_name.
	 * @return array<string, mixed>|null
	 */
	public function for_slug( string $slug ): ?array {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		$placement_id = $this->placements->id_by_slug( $slug );

		if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
			return null;
		}

		$cached = $this->cache->get( $placement_id );

		if ( ! is_array( $cached ) ) {
			$cached = $this->build( $placement_id, $slug );
			$this->cache->put( $placement_id, $cached );
		}

		return $this->present( $cached );
	}

	/**
	 * Whether a parsed token still names a live, servable fill.
	 *
	 * Pause, complete, and house removal must stop leftover tokens within
	 * the five-minute TTL, not at the next cache bust.
	 *
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed Token.
	 */
	public function accepts( array $parsed ): bool {
		if ( ! $this->is_enabled() ) {
			return false;
		}

		$placement_id = $parsed['placement_id'];

		if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
			return false;
		}

		if ( 0 === $parsed['campaign_id'] && 0 === $parsed['creative_id'] ) {
			return $this->house_is_servable( $placement_id );
		}

		if ( $parsed['campaign_id'] <= 0 || $parsed['creative_id'] <= 0 ) {
			return false;
		}

		if ( ! in_array( $parsed['campaign_id'], $this->campaigns->live_ids_for_placement( $placement_id ), true ) ) {
			return false;
		}

		foreach ( $this->creatives->for_campaign( $parsed['campaign_id'] ) as $row ) {
			if ( $parsed['creative_id'] === $row['id'] && $placement_id === $row['placement_id'] ) {
				return Campaign_Rules::is_valid_click_url( $row['click_url'] ) && false !== wp_http_validate_url( $row['click_url'] );
			}
		}

		return false;
	}

	/**
	 * House creative is an allowed image with a real destination.
	 *
	 * @param int $placement_id Placement post id.
	 */
	public function house_is_servable( int $placement_id ): bool {
		if ( ! $this->is_allowed_house_attachment( $this->placements->house_attachment_id( $placement_id ) ) ) {
			return false;
		}

		$click = $this->placements->house_click_url( $placement_id );

		return Campaign_Rules::is_valid_click_url( $click ) && false !== wp_http_validate_url( $click );
	}

	/**
	 * Candidate set for one placement. The winner is not cached.
	 *
	 * @param int    $placement_id Placement post id.
	 * @param string $slug         Placement post_name.
	 * @return array<string, mixed>
	 */
	private function build( int $placement_id, string $slug ): array {
		$candidates = $this->paid_creatives( $placement_id );
		$house      = null;

		if ( array() === $candidates && Settings_Schema::HOUSE_WHEN_EMPTY === $this->settings->house_policy() ) {
			$house = $this->house_creative( $placement_id );
		}

		return array(
			'slot'       => $slug,
			'size'       => $this->placements->size( $placement_id ),
			'candidates' => $candidates,
			'house'      => $house,
			'beacon'     => rest_url( Creative_File_Controller::NAMESPACE . '/i' ),
		);
	}

	/**
	 * Picks one candidate and mints a token. The set never leaves the cache.
	 *
	 * @param array<string, mixed> $cached Cached identity.
	 * @return array<string, mixed>
	 */
	private function present( array $cached ): array {
		$candidates = isset( $cached['candidates'] ) && is_array( $cached['candidates'] ) ? $cached['candidates'] : array();
		$count      = count( $candidates );
		$draw       = $count > 0 ? random_int( 0, $count - 1 ) : 0;
		$paid       = Fill_Rotation::at( $candidates, $draw );
		$house      = null === $paid && isset( $cached['house'] ) && is_array( $cached['house'] ) ? $cached['house'] : null;

		return $this->with_tokens(
			array(
				'slot'     => isset( $cached['slot'] ) && is_string( $cached['slot'] ) ? $cached['slot'] : '',
				'size'     => isset( $cached['size'] ) && is_string( $cached['size'] ) ? $cached['size'] : '',
				'creative' => is_array( $paid ) ? $paid : null,
				'house'    => $house,
				'beacon'   => isset( $cached['beacon'] ) && is_string( $cached['beacon'] ) ? $cached['beacon'] : '',
			)
		);
	}

	/**
	 * Mints a fresh token onto a chosen identity payload.
	 *
	 * @param array<string, mixed> $payload One creative or house, no candidates.
	 * @return array<string, mixed>
	 */
	private function with_tokens( array $payload ): array {
		foreach ( array( 'creative', 'house' ) as $key ) {
			if ( ! isset( $payload[ $key ] ) || ! is_array( $payload[ $key ] ) ) {
				continue;
			}

			$row          = $payload[ $key ];
			$placement_id = isset( $row['placement'] ) ? (int) $row['placement'] : 0;
			$campaign_id  = isset( $row['campaign'] ) ? (int) $row['campaign'] : 0;
			$creative_id  = isset( $row['creative'] ) ? (int) $row['creative'] : 0;
			$minted       = $this->tokens->mint( $placement_id, $campaign_id, $creative_id );

			$row['token'] = $minted['token'];
			$row['click'] = Click_Hop::url( $minted['token'] );

			unset( $row['placement'], $row['campaign'], $row['creative'] );

			$payload[ $key ] = $row;
		}

		return $payload;
	}

	/**
	 * Servable paid creatives occupying this placement, live campaigns first.
	 *
	 * @param int $placement_id Placement post id.
	 * @return list<array<string, mixed>>
	 */
	private function paid_creatives( int $placement_id ): array {
		$candidates = array();

		foreach ( $this->campaigns->live_ids_for_placement( $placement_id ) as $campaign_id ) {
			foreach ( $this->creatives->for_campaign( $campaign_id ) as $row ) {
				if ( $placement_id !== $row['placement_id'] ) {
					continue;
				}

				if ( ! Campaign_Rules::is_valid_click_url( $row['click_url'] ) || false === wp_http_validate_url( $row['click_url'] ) ) {
					continue;
				}

				$attachment_id = $this->creatives->attachment_id( $row['id'] );
				$image         = $attachment_id > 0 ? wp_get_attachment_image_url( $attachment_id, 'full' ) : false;

				if ( ! is_string( $image ) || '' === $image ) {
					continue;
				}

				$candidates[] = array(
					'image'     => $image,
					'alt'       => $row['alt_text'],
					'width'     => $row['width'],
					'height'    => $row['height'],
					'placement' => $placement_id,
					'campaign'  => $campaign_id,
					'creative'  => $row['id'],
				);
			}
		}

		return $candidates;
	}

	/**
	 * House creative for an empty slot.
	 *
	 * @param int $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	private function house_creative( int $placement_id ): ?array {
		$attachment_id = $this->placements->house_attachment_id( $placement_id );

		if ( ! $this->is_allowed_house_attachment( $attachment_id ) ) {
			return null;
		}

		$image = wp_get_attachment_image_url( $attachment_id, 'full' );

		if ( ! is_string( $image ) || '' === $image ) {
			return null;
		}

		return array(
			'image'     => $image,
			'alt'       => $this->placements->house_alt( $placement_id ),
			'placement' => $placement_id,
			'campaign'  => 0,
			'creative'  => 0,
		);
	}

	/**
	 * Staff-chosen house media must be a raster image, never SVG.
	 *
	 * @param int $attachment_id Media attachment id.
	 */
	private function is_allowed_house_attachment( int $attachment_id ): bool {
		$type = $this->placements->attachment_type( $attachment_id );

		if ( null === $type || ! Upload_Rules::is_allowed_mime( $type['mime'] ) ) {
			return false;
		}

		return '' === $type['extension'] || Upload_Rules::is_allowed_extension( $type['extension'] );
	}
}
