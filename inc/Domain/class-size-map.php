<?php
/**
 * Which size a placement serves at a given viewport.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * One placement, several sizes, and exactly one answer per request.
 *
 * The inventory contract gives P15 an invariant it has to make true rather than
 * merely test: *responsive mappings are deterministic; the same request context
 * cannot map to two conflicting billable inventory units*. A mapping that can
 * answer twice is not a smaller bug than one that answers wrongly — it is an
 * advertiser billed for a 728x90 and a reader shown a 320x50, with the ledger
 * and the page both certain they are right.
 *
 * **Breakpoints, not ranges, and that is the whole design.** A range carries a
 * lower and an upper bound, so two of them can overlap and a width can satisfy
 * both; preventing that means validating every pair on every write and hoping
 * nothing edits the option directly. A breakpoint carries only a floor, and the
 * answer is "the largest floor at or below this width". Two floors cannot both
 * be the largest. Ambiguity is not rejected here, it is unrepresentable.
 *
 * The same shape gives totality for free: the map is invalid without a floor of
 * zero, so every width from zero upward has an answer and there is no gap to
 * fall through.
 *
 * Pure domain: no WordPress, no storage.
 */
final class Size_Map {

	/**
	 * The floor every map must carry.
	 *
	 * Without it a narrow viewport has no answer, and "no answer" on the fill
	 * path means either an unsold slot or a guess. Neither is a thing to decide
	 * per request.
	 */
	public const BASE_WIDTH = 0;

	/**
	 * Most breakpoints one placement may declare.
	 *
	 * Six is not a technical limit. It is the point past which a publisher is
	 * describing a layout rather than an inventory unit, and every extra
	 * breakpoint splits the same demand across another billable size.
	 */
	public const MAX_BREAKPOINTS = 6;

	/**
	 * Constructor.
	 *
	 * Private so a map is only built through a reader that has already
	 * collapsed duplicates and sorted, which is what makes the resolution a
	 * function rather than a search.
	 *
	 * @param array<int, string> $breakpoints Minimum viewport width => size, descending.
	 */
	private function __construct( private readonly array $breakpoints ) {
	}

	/**
	 * A placement that serves one size at every width.
	 *
	 * The overwhelming majority of inventory, and the shape every existing
	 * placement is in. A single size is a map with one breakpoint at zero, not
	 * a special case the resolver has to know about.
	 *
	 * @param string $size Stored `{width}x{height}`.
	 */
	public static function fixed( string $size ): self {
		return new self( array( self::BASE_WIDTH => $size ) );
	}

	/**
	 * Reads a map out of stored breakpoints.
	 *
	 * **Duplicate floors collapse rather than compete.** Two entries claiming
	 * the same width is the one way a stored map could be ambiguous, and the
	 * later one wins — an arbitrary rule, deliberately, because the alternative
	 * is refusing to serve a slot over a configuration nobody can see. What
	 * matters is that the answer is the same on every request, not which of two
	 * indistinguishable entries produced it.
	 *
	 * Anything unusable is dropped: a size the catalogue cannot serve, a
	 * negative width, a key that is not a number. A map left with no floor of
	 * zero is not a map, and the caller gets the fallback instead.
	 *
	 * @param mixed  $stored   Width => size pairs, in any order.
	 * @param string $fallback Size to use when nothing usable was stored.
	 */
	public static function from_stored( mixed $stored, string $fallback ): self {
		if ( ! is_array( $stored ) ) {
			return self::fixed( $fallback );
		}

		$breakpoints = array();

		foreach ( $stored as $width => $size ) {
			if ( ! is_numeric( $width ) || (int) $width < self::BASE_WIDTH ) {
				continue;
			}

			if ( ! is_string( $size ) || ! Ad_Sizes::is_valid( $size ) ) {
				continue;
			}

			$breakpoints[ (int) $width ] = $size;
		}

		if ( ! array_key_exists( self::BASE_WIDTH, $breakpoints ) ) {
			return self::fixed( $fallback );
		}

		krsort( $breakpoints, SORT_NUMERIC );

		/*
		 * **The base survives the trim, whatever else does not.**
		 *
		 * Slicing the sorted list keeps the widest floors, and the base is the
		 * narrowest — so a placement declaring more breakpoints than the limit
		 * lost the one entry the map is invalid without. Totality is the
		 * property this class claims to guarantee by construction, and the
		 * bound quietly took it away: `base()` returned null and a narrow
		 * viewport resolved to a type error.
		 *
		 * So the floor is held out, the rest is trimmed to the remaining room,
		 * and it goes back. A publisher over the limit loses their narrowest
		 * *optional* steps, which is the least surprising thing to lose.
		 */
		$base = $breakpoints[ self::BASE_WIDTH ];

		unset( $breakpoints[ self::BASE_WIDTH ] );

		$kept = array_slice( $breakpoints, 0, self::MAX_BREAKPOINTS - 1, true );

		/*
		 * Appended last, and that already leaves the list sorted: `array_slice`
		 * keeps the descending order it was given, and the base is by
		 * definition the smallest floor. A second `krsort` here was a line no
		 * test could fail over, which is a line the next reader has to prove
		 * harmless before they may touch it.
		 */
		$kept[ self::BASE_WIDTH ] = $base;

		return new self( $kept );
	}

	/**
	 * The one size this placement serves at a viewport width.
	 *
	 * The largest floor at or below the width. Deterministic by construction:
	 * the breakpoints are sorted descending and unique, so the first match is
	 * the only match, and the floor of zero guarantees there is one.
	 *
	 * A width below zero — which the wire can produce and a layout cannot —
	 * resolves to the base rather than to nothing.
	 *
	 * @param int $viewport_width Reported viewport width in CSS pixels.
	 */
	public function for_viewport( int $viewport_width ): string {
		foreach ( $this->breakpoints as $floor => $size ) {
			if ( $viewport_width >= $floor ) {
				return $size;
			}
		}

		return $this->base();
	}

	/**
	 * The size served when nothing narrower applies.
	 */
	public function base(): string {
		return $this->breakpoints[ self::BASE_WIDTH ];
	}

	/**
	 * Every size this placement can serve, widest floor first.
	 *
	 * What a forecast has to spread demand over, and what billing has to treat
	 * as distinct units. A caller that only wants to know "is this responsive"
	 * should ask `is_responsive()` rather than counting this.
	 *
	 * @return array<int, string>
	 */
	public function breakpoints(): array {
		return $this->breakpoints;
	}

	/**
	 * Whether this placement serves more than one size.
	 */
	public function is_responsive(): bool {
		return count( $this->breakpoints ) > 1;
	}
}
