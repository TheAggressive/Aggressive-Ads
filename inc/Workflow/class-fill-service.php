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
use Aggressive\Ads\Domain\Opportunity;
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
	 * @param Decision_Metrics     $metrics    Per-request decision counters.
	 */
	public function __construct(
		private readonly Settings $settings,
		private readonly Placement_Repository $placements,
		private readonly Delivery_Repository $delivery,
		private readonly Fill_Token $tokens,
		private readonly Decision_Engine $decisions,
		private readonly Decision_Metrics $metrics
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
	 * @param string $slug     Placement post_name.
	 * @param int    $sequence Fill number within the page view, zero-based.
	 * @param int    $viewport_width Reported viewport width in CSS pixels, or 0 for the base size.
	 * @return array<string, mixed>|null
	 */
	public function for_slug( string $slug, int $sequence = 0, int $viewport_width = 0 ): ?array {
		if ( ! $this->is_enabled() ) {
			return null;
		}

		$placement_id = $this->placements->id_by_slug( $slug );

		if ( $placement_id <= 0 || ! $this->placements->is_active( $placement_id ) ) {
			return null;
		}

		/*
		 * The publisher's refresh policy, enforced where a client cannot skip
		 * it.
		 *
		 * The policy also reaches the browser as slot context, and the store
		 * honours it — this is the backstop for a client that does not, which
		 * includes a hand-written request. A placement that forbids refresh
		 * does not refresh, whatever the block attribute says, and a claimed
		 * sequence past the per-view cap is refused rather than served and
		 * counted.
		 *
		 * **Refused the same way a missing placement is, deliberately.** A
		 * distinct response would tell an unauthenticated caller which
		 * placements exist and what each one's refresh policy is, which is a
		 * small disclosure for no benefit: the legitimate client already has
		 * the policy in its context and does not need to discover it by
		 * probing.
		 */
		if ( ! $this->placements->refresh_policy( $placement_id )->permits_sequence( $sequence ) ) {
			return null;
		}

		/*
		 * Declared before decisioning, so every outcome this request records is
		 * filed under the same kind of inventory and `requests` still equals
		 * `fills` plus the no-fill reasons within it.
		 */
		$this->metrics->for_opportunity( Opportunity::from_sequence( $sequence ) );

		$paid  = $this->paid_creative( $placement_id, $viewport_width );
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
	 * @param array<int, string> $slugs          Requested slot slugs.
	 * @param int                $viewport_width Reported viewport width in CSS pixels, or 0 for the base size.
	 * @return array<string, array<string, mixed>> Keyed by slot slug.
	 */
	public function for_slots( array $slugs, int $viewport_width = 0 ): array {
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
			$serving             = $this->placements->size_map( $placement_id )->for_viewport( $viewport_width );
			$slots_map[ $slug ]  = array(
				'placement_id' => $placement_id,
				'candidates'   => $rows,

				/*
				 * Per slot, because two placements on one page can be serving
				 * different sizes at the same viewport. The coordinator merges
				 * this into that slot's facts and the eligibility gate reads it.
				 */
				'size'         => $serving,
			);
			$valid_info[ $slug ] = array(
				'placement_id' => $placement_id,
				'size'         => $serving,
			);
		}

		if ( array() === $slots_map ) {
			return array();
		}

		/*
		 * A page batch is a page opportunity by definition — it exists because
		 * somebody loaded a page and asked for every slot on it at once.
		 *
		 * Declared here rather than inherited. The kind is one field on a
		 * request-scoped service, and a path that does not set it takes
		 * whatever the last request left, which for a rotation would file a
		 * whole page of slots as refreshes and make the placement's supply
		 * shrink the busier its rotation got.
		 */
		$this->metrics->for_opportunity( Opportunity::PAGE );

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
	 * @param int $placement_id   Placement post id.
	 * @param int $viewport_width Reported viewport width in CSS pixels, or 0 for the base size.
	 * @return array<string, mixed>|null
	 */
	private function paid_creative( int $placement_id, int $viewport_width = 0 ): ?array {
		if ( ! $this->decisions->serving_ready() ) {
			return null;
		}

		$now      = time();
		$facts    = $this->facts_for( $placement_id, $viewport_width );
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
	 * The request's facts, plus the size this placement is serving right now.
	 *
	 * **A fact of the request rather than of the placement.** A responsive
	 * placement serves several sizes and which one applies depends on the
	 * viewport that asked, so the size cannot be resolved once and cached with
	 * the slot. `Eligibility_Stage` reads it to refuse a candidate whose
	 * artwork is a different size — without which a placement holding only
	 * 728x90 creatives would serve one into a 320x50 slot.
	 *
	 * A viewport of zero is a caller that reported none, and resolves to the
	 * map's base. Every existing placement is a fixed map, so this is that
	 * placement's only size and the gate is a no-op for it.
	 *
	 * @param int $placement_id  Placement post id.
	 * @param int $viewport_width Reported viewport width in CSS pixels.
	 * @return array<string, mixed>
	 */
	private function facts_for( int $placement_id, int $viewport_width ): array {
		$facts = $this->request_facts();

		$facts['size'] = $this->placements->size_map( $placement_id )->for_viewport( $viewport_width );

		return $facts;
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
