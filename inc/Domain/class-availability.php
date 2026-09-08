<?php
/**
 * Whether a window has room for what is being asked of it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Compares a forecast against what has already been claimed.
 *
 * Three verdicts rather than two: unmeasured is not the same as full. Reading
 * it as unlimited oversells; reading it as zero refuses every new placement.
 *
 * Being short warns rather than refuses, per the inventory-commerce contract —
 * the estimate is a low quantile, so selling past it is often correct. Doing so
 * unnoticed is not.
 */
final class Availability {

	/** The window has room for the request. */
	public const AVAILABLE = 'available';

	/** The request exceeds what the forecast says is left. */
	public const OVERSELL = 'oversell';

	/** Nothing has been forecast, so there is no capacity to compare against. */
	public const UNKNOWN = 'unknown';

	/**
	 * Every verdict this can return.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::AVAILABLE, self::OVERSELL, self::UNKNOWN );
	}

	/**
	 * Whether a booking of this size fits.
	 *
	 * `remaining` is null when nothing has been forecast: a screen showing `0`
	 * would say the placement is sold out. `shortfall` is what the forecast
	 * cannot cover, which is the expected impact an override records.
	 *
	 * @param int|null $capacity  What the forecast says the window will supply, or null.
	 * @param int      $committed What reservations already claim.
	 * @param int      $requested What is being asked for now.
	 * @return array{verdict: string, remaining: int|null, shortfall: int}
	 */
	public static function decide( ?int $capacity, int $committed, int $requested ): array {
		if ( null === $capacity ) {
			// Not a shortfall: nothing is known to be short, and a number on a
			// screen is read as knowledge.
			return array(
				'verdict'   => self::UNKNOWN,
				'remaining' => null,
				'shortfall' => 0,
			);
		}

		// Clamped: negative headroom added to the next request would quietly
		// reduce the shortfall it is about to warn about.
		$remaining = max( 0, $capacity - max( 0, $committed ) );
		$shortfall = max( 0, $requested - $remaining );

		return array(
			'verdict'   => 0 === $shortfall ? self::AVAILABLE : self::OVERSELL,
			'remaining' => $remaining,
			'shortfall' => $shortfall,
		);
	}

	/**
	 * The capacity figure a ledger should be given for one claim.
	 *
	 * Exactly enough for this claim, given what is already held. The ledger
	 * re-checks inside its lock, so an acknowledged oversell and an unforecast
	 * window both have to get past it without the guard being switched off.
	 *
	 * The race is what this buys: a booking landing between the reading and the
	 * claim pushes the ledger's total past this ceiling, and the second claim is
	 * refused. An unbounded ceiling passes every such claim and the oversell
	 * appears only when the window runs.
	 *
	 * In the domain rather than the workflow because that value is observable
	 * only under concurrency, which the single-connection PHP suites cannot
	 * stage — here it can be asserted directly.
	 *
	 * @param int $committed What reservations already claim.
	 * @param int $requested What is being asked for now.
	 */
	public static function ceiling( int $committed, int $requested ): int {
		return max( 0, $committed ) + max( 0, $requested );
	}

	/**
	 * The state of a window given only what is already held.
	 *
	 * A different question from {@see self::decide()}, which asks whether one
	 * more claim fits. Asking that with a request of nothing is not the same
	 * question and does not answer this one: nothing always fits, so the
	 * verdict comes back `available` for a window whose commitments already
	 * exceed the forecast. The outlook screen asked it that way and every row
	 * therefore read as available, including the oversold ones the summary
	 * above the table was counting at the same moment.
	 *
	 * Built on `decide()` rather than beside it so the oversell rule has one
	 * definition. The screen's summary had grown a second one — an inline
	 * `committed > forecast` — and two definitions of oversold on one screen is
	 * how the tile and the rows come to disagree.
	 *
	 * @param int|null $capacity  Forecast supply, or null when unmeasured.
	 * @param int      $committed What reservations already hold.
	 * @return array{verdict: string, remaining: int|null, shortfall: int}
	 */
	public static function holdings( ?int $capacity, int $committed ): array {
		$held     = max( 0, $committed );
		$decision = self::decide( $capacity, 0, $held );

		return array(
			'verdict'   => $decision['verdict'],
			'remaining' => null === $capacity ? null : max( 0, $capacity - $held ),
			'shortfall' => $decision['shortfall'],
		);
	}

	/**
	 * The answer an advertiser may be given.
	 *
	 * **No number crosses this line.** A forecast is the publisher's negotiating
	 * position, so the advertiser-facing answer is yes or no with nothing behind
	 * it. `unknown` reads as bookable: refusing on no evidence would make every
	 * new placement unsellable until a quarter of history existed.
	 *
	 * @param string $verdict One of the verdicts above.
	 */
	public static function bookable( string $verdict ): bool {
		return self::OVERSELL !== $verdict;
	}
}
