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
use Aggressive\Ads\Domain\Viewability_Rules;
use Aggressive\Ads\Domain\Upload_Rules;
use Aggressive\Ads\Repository\Delivery_Repository;
use Aggressive\Ads\Repository\Placement_Repository;
use Aggressive\Ads\REST\Creative_File_Controller;

/**
 * Native fill. Paid creatives are chosen by the assignment decision engine.
 */
final class Fill_Service {

	/**
	 * Constructor.
	 *
	 * @param Settings             $settings   Module and delivery flags.
	 * @param Placement_Repository $placements Slot catalogue.
	 * @param Delivery_Repository  $delivery   Token validation reads.
	 * @param Fill_Token           $tokens     Signed beacon/click tokens.
	 * @param Decision_Engine      $decisions  Assignment decision engine.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Placement_Repository $placements,
		private readonly Delivery_Repository $delivery,
		private readonly Fill_Token $tokens,
		private readonly Decision_Engine $decisions
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
	 * The candidate set is cached; the winner is chosen per request.
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
				'slot'        => $slug,
				'size'        => $this->placements->size( $placement_id ),
				'creative'    => $paid,
				'house'       => $house,
				'beacon'      => rest_url( Creative_File_Controller::NAMESPACE . '/i' ),
				'viewability' => $this->viewability(),
			)
		);
	}

	/**
	 * The threshold the client measures against.
	 *
	 * Sent with the fill rather than compiled into the script, so changing it
	 * takes effect on the next fill instead of the next release — and so the
	 * browser never converts a percentage the server already converted.
	 *
	 * @return array{ratio: float, dwell_ms: int}
	 */
	private function viewability(): array {
		$delivery = $this->settings->get()['delivery'] ?? array();

		return Viewability_Rules::for_client(
			$delivery['viewable_ratio'] ?? null,
			$delivery['viewable_dwell_ms'] ?? null
		);
	}

	/**
	 * Batch fill payloads for multiple placement slugs on a single page view.
	 *
	 * @param array<int, string> $slugs Requested slot slugs.
	 * @return array<string, array<string, mixed>> Keyed by slot slug.
	 */
	public function for_slots( array $slugs ): array {
		if ( ! $this->is_enabled() || array() === $slugs ) {
			return array();
		}

		$now        = time();
		$slots_map  = array();
		$valid_info = array();

		// Deduplicate and bound to max 20 slots.
		$slugs = array_slice( array_unique( $slugs ), 0, 20 );

		foreach ( $slugs as $slug ) {
			if ( ! is_string( $slug ) || '' === $slug ) {
				continue;
			}

			$placement_id = $this->placements->id_by_slug( $slug );
			if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
				continue;
			}

			$rows                = $this->decisions->cached_rows( $placement_id, $now );
			$slots_map[ $slug ]  = array(
				'placement_id' => $placement_id,
				'candidates'   => $rows,
			);
			$valid_info[ $slug ] = array(
				'placement_id' => $placement_id,
				'size'         => $this->placements->size( $placement_id ),
			);
		}

		if ( array() === $slots_map ) {
			return array();
		}

		$facts     = $this->request_facts();
		$decisions = $this->decisions->decide_page( $slots_map, $now, null, $facts );
		$payloads  = array();

		foreach ( $valid_info as $slug => $info ) {
			$placement_id = $info['placement_id'];
			$decision     = $decisions[ $slug ] ?? null;

			$paid = null;
			if ( null !== $decision && $decision['result']->has_winner() && is_array( $decision['result']->winner ) ) {
				$winner = $decision['result']->winner;

				$this->decisions->record_delivery( $winner, $now, $facts );

				$paid = $this->decisions->payload_from_row( $winner, $placement_id );
			}

			$house = null;
			if ( null === $paid && Settings_Schema::HOUSE_WHEN_EMPTY === $this->settings->house_policy() ) {
				$house = $this->house_creative( $placement_id );
			}

			$payloads[ $slug ] = $this->with_tokens(
				array(
					'slot'        => $slug,
					'size'        => $info['size'],
					'creative'    => $paid,
					'house'       => $house,
					'beacon'      => rest_url( Creative_File_Controller::NAMESPACE . '/i' ),
					'viewability' => $this->viewability(),
				)
			);
		}

		return $payloads;
	}

	/**
	 * Whether a parsed token still names a live, servable fill.
	 *
	 * @param array{placement_id: int, campaign_id: int, creative_id: int, exp: int, nonce: string} $parsed Token.
	 */
	public function accepts( array $parsed ): bool {
		return null !== $this->destination( $parsed );
	}

	/**
	 * Destination for an exact, still-live token identity.
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
	 * Chooses one creative through the assignment decision engine.
	 *
	 * @param int $placement_id Placement post id.
	 * @return array<string, mixed>|null
	 */
	private function paid_creative( int $placement_id ): ?array {
		if ( ! $this->decisions->serving_ready() ) {
			return null;
		}

		$now      = time();
		$facts    = $this->request_facts();
		$rows     = $this->decisions->cached_rows( $placement_id, $now );
		$decision = $this->decisions->decide( $placement_id, $now, null, $rows, true, $facts );

		if ( ! $decision['result']->has_winner() || ! is_array( $decision['result']->winner ) ) {
			return null;
		}

		$winner = $decision['result']->winner;

		$this->decisions->record_delivery( $winner, $now, $facts );

		return $this->decisions->payload_from_row( $winner, $placement_id );
	}

	/**
	 * Request facts the decision stages are allowed to see.
	 *
	 * The visitor identity is the same daily client digest the event ledger
	 * already stores — HMAC of the IP, salted and rotated per UTC day. Frequency
	 * capping needs to recognise a returning visitor for the length of a window
	 * and nothing more, so reusing an identifier the plugin already accepts is
	 * better than minting a second, longer-lived one.
	 *
	 * Returns no visitor id when the address is unusable, which fails open:
	 * `Frequency_Rules` caps nobody it cannot identify.
	 *
	 * @return array<string, mixed>
	 */
	private function request_facts(): array {
		$ip = Delivery_Request::client_ip();

		if ( '' === $ip ) {
			return array();
		}

		return array( 'visitor_id' => $this->tokens->ip_hash( $ip ) );
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
