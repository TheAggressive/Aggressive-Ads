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
use Aggressive\Ads\Domain\Settings_Schema;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;

/**
 * Native fill. The live set is campaign status, not an ads CPT.
 */
final class Fill_Service {

	/** A corrupt candidate cannot turn a large slot into another linear scan. */
	private const MAX_CANDIDATE_ATTEMPTS = 5;

	/** Bounded wait for the request currently rebuilding a placement. */
	private const REBUILD_WAIT_ATTEMPTS     = 10;
	private const REBUILD_WAIT_MICROSECONDS = 20_000;

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings   Module and delivery flags.
	 * @param Placement_Repository $placements Slot catalogue.
	 * @param Delivery_Repository  $delivery   Indexed live creative reads.
	 * @param Fill_Cache           $cache      Short-TTL payload cache.
	 * @param Fill_Token           $tokens     Signed beacon/click tokens.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Placement_Repository $placements,
		private readonly Delivery_Repository $delivery,
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
	 * The candidate id vector and individual payloads are cached separately.
	 * The winner and token are chosen per request, so a hit is neither a replay
	 * of the previous visitor's impression nor a frozen rotation.
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

		$paid  = $this->paid_creative( $placement_id );
		$house = null;

		if ( null === $paid && Settings_Schema::HOUSE_WHEN_EMPTY === $this->settings->house_policy() ) {
			$house = $this->house_creative( $placement_id );
		}

		return $this->with_tokens(
			array(
				'slot'     => $slug,
				'size'     => $this->placements->size( $placement_id ),
				'creative' => $paid,
				'house'    => $house,
				'beacon'   => rest_url( Creative_File_Controller::NAMESPACE . '/i' ),
			)
		);
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
		return null !== $this->destination( $parsed );
	}

	/**
	 * Destination for an exact, still-live token identity.
	 *
	 * This primary-id read is shared by beacon validation and the click hop so
	 * a click does not resolve and validate the same creative twice.
	 *
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed Token.
	 */
	public function destination( array $parsed ): ?string {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		$placement_id = $parsed['placement_id'];

		if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
			return null;
		}

		if ( 0 === $parsed['campaign_id'] && 0 === $parsed['creative_id'] ) {
			return $this->house_is_servable( $placement_id ) ? $this->placements->house_click_url( $placement_id ) : null;
		}

		if ( $parsed['campaign_id'] <= 0 || $parsed['creative_id'] <= 0 ) {
			return null;
		}

		$row = $this->delivery->candidate( $parsed['creative_id'], $placement_id, $parsed['campaign_id'] );

		return is_array( $row ) && Campaign_Rules::is_valid_click_url( $row['click_url'] ) ? $row['click_url'] : null;
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

		return Campaign_Rules::is_valid_click_url( $click );
	}

	/**
	 * Chooses one creative from a compact cached id vector.
	 *
	 * Candidate payloads have separate keys, so a 1,000-ad placement does not
	 * transfer and deserialize 1,000 image URLs and alt strings on every fill.
	 *
	 * @param int $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	private function paid_creative( int $placement_id ): ?array {
		$ids   = $this->candidate_ids( $placement_id );
		$count = count( $ids );

		if ( 0 === $count ) {
			return null;
		}

		$start    = random_int( 0, $count - 1 );
		$attempts = min( $count, self::MAX_CANDIDATE_ATTEMPTS );

		for ( $offset = 0; $offset < $attempts; ++$offset ) {
			$creative_id = $ids[ ( $start + $offset ) % $count ];
			$candidate   = $this->candidate_payload( $creative_id, $placement_id );

			if ( null !== $candidate ) {
				return $candidate;
			}
		}

		return null;
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
	 * Cached creative ids, rebuilt by one request per placement on a miss.
	 *
	 * @param int $placement_id Placement post id.
	 * @return list<int>
	 */
	private function candidate_ids( int $placement_id ): array {
		$cached = $this->cache->get( $placement_id );

		if ( is_array( $cached ) && isset( $cached['candidate_ids'] ) && is_array( $cached['candidate_ids'] ) ) {
			return $this->positive_ids( $cached['candidate_ids'] );
		}

		$owner = $this->cache->claim_rebuild( $placement_id );

		if ( '' === $owner ) {
			for ( $attempt = 0; $attempt < self::REBUILD_WAIT_ATTEMPTS; ++$attempt ) {
				usleep( self::REBUILD_WAIT_MICROSECONDS );
				$cached = $this->cache->get( $placement_id );

				if ( is_array( $cached ) && isset( $cached['candidate_ids'] ) && is_array( $cached['candidate_ids'] ) ) {
					return $this->positive_ids( $cached['candidate_ids'] );
				}
			}

			/*
			 * Do not turn a slow cache rebuild or cache outage into a database
			 * stampede. This request can safely render no paid ad; the lock owner
			 * will populate the short-lived candidate vector for later requests.
			 */
			return array();
		}

		try {
			$ids = $this->delivery->candidate_ids( $placement_id );
			$this->cache->put( $placement_id, array( 'candidate_ids' => $ids ) );

			return $ids;
		} finally {
			$this->cache->release_rebuild( $placement_id, $owner );
		}
	}

	/**
	 * One token-free candidate payload.
	 *
	 * @param int $creative_id Creative post id.
	 * @param int $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	private function candidate_payload( int $creative_id, int $placement_id ): ?array {
		$cached = $this->cache->get_candidate( $creative_id );

		if ( is_array( $cached ) && (int) ( $cached['placement'] ?? 0 ) === $placement_id ) {
			return $cached;
		}

		$row = $this->delivery->candidate( $creative_id, $placement_id );

		if ( ! is_array( $row ) || ! Campaign_Rules::is_valid_click_url( $row['click_url'] ) ) {
			return null;
		}

		$image = wp_get_attachment_image_url( $row['attachment_id'], 'full' );

		if ( ! is_string( $image ) || '' === $image ) {
			return null;
		}

		$payload = array(
			'image'     => $image,
			'alt'       => $row['alt_text'],
			'width'     => $row['width'],
			'height'    => $row['height'],
			'placement' => $placement_id,
			'campaign'  => $row['campaign_id'],
			'creative'  => $row['creative_id'],
		);

		$this->cache->put_candidate( $creative_id, $payload );

		return $payload;
	}

	/**
	 * Normalizes a cache value into unique positive ids.
	 *
	 * @param array<int, mixed> $values Cached values.
	 * @return list<int>
	 */
	private function positive_ids( array $values ): array {
		$ids = array();

		foreach ( $values as $value ) {
			$id = (int) $value;

			if ( $id > 0 ) {
				$ids[ $id ] = $id;
			}
		}

		return array_values( $ids );
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
