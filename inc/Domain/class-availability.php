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
 * **Three answers, not two.** A window can have room, be short of it, or be
 * unmeasured — and the third is a different fact from the second. Treating
 * "we have never forecast this placement" as unlimited is how an oversell
 * starts; treating it as zero refuses every booking on a placement nobody has
 * measured yet, which is every new placement. Both readings are wrong, so the
 * verdict says which situation it is and lets the caller decide.
 *
 * **Being short is a warning, not a refusal.** The inventory-commerce contract
 * is explicit: oversell warns and logs the override rather than silently
 * blocking staff. A forecast is a conservative estimate — the twentieth
 * percentile of observed days — so a publisher who knows their inventory
 * better than a model does is often right to sell past it. What must not
 * happen is selling past it *without noticing*.
 *
 * Pure domain: no WordPress, no storage. The capability check and the audit
 * row belong to the workflow that acts on this answer.
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
	 * `remaining` is null when nothing has been forecast, for the same reason
	 * {@see Fill_Figures} reports a null rate with no denominator: a placement
	 * nobody has measured has not been measured as full, and a screen showing
	 * `0` would say it is sold out.
	 *
	 * `shortfall` is how much of the request the forecast cannot cover — the
	 * figure an override has to record as its expected impact, because "we
	 * oversold" is not actionable and "we sold 4,000 more than we expect to
	 * have" is.
	 *
	 * @param int|null $capacity  What the forecast says the window will supply, or null.
	 * @param int      $committed What reservations already claim.
	 * @param int      $requested What is being asked for now.
	 * @return array{verdict: string, remaining: int|null, shortfall: int}
	 */
	public static function decide( ?int $capacity, int $committed, int $requested ): array {
		if ( null === $capacity ) {
			return array(
				'verdict'   => self::UNKNOWN,
				'remaining' => null,

				/*
				 * Not a shortfall. Nothing is known to be short — reporting the
				 * whole request as an overrun would put a number on a screen
				 * that means "we have no idea", and a number is read as
				 * knowledge.
				 */
				'shortfall' => 0,
			);
		}

		/*
		 * Clamped at zero. A window already oversold has negative headroom,
		 * and reporting that as remaining invites a caller to add it to the
		 * next request and quietly reduce the shortfall it is about to warn
		 * about.
		 */
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
	 * **Exactly enough for this claim, given what is already held.** The
	 * ledger re-checks capacity inside its lock, where the reading above does
	 * not run, so an acknowledged oversell and an unforecast window both have
	 * to get past it — and the ceiling is what lets them without switching the
	 * guard off.
	 *
	 * `committed + requested` is the whole rule, and it holds for all three
	 * verdicts. What it buys is the race: if another booking lands between the
	 * reading and the claim, the ledger's own total is higher than the one this
	 * was computed from, so `taken + requested` exceeds this ceiling and the
	 * second claim is refused rather than quietly doubling the window. An
	 * unbounded ceiling would pass every such claim, and the oversell would
	 * appear only when the window ran.
	 *
	 * That is also why this is here rather than in the workflow that calls it.
	 * Its value is observable only under concurrency, which the PHP suites run
	 * single-connection and cannot reach — as a rule in the domain it can be
	 * asserted directly instead of inferred from a race nobody can stage.
	 *
	 * @param int $committed What reservations already claim.
	 * @param int $requested What is being asked for now.
	 */
	public static function ceiling( int $committed, int $requested ): int {
		return max( 0, $committed ) + max( 0, $requested );
	}

	/**
	 * The answer an advertiser may be given.
	 *
	 * **A number never crosses this line.** The contract puts forecast value,
	 * confidence, version and error in one sentence and keeps all of them
	 * staff-only, because a forecast is the publisher's negotiating position:
	 * an advertiser who can see which placements are empty knows what to offer
	 * for them. So the advertiser-facing question is "can I book this?", and
	 * the answer is yes or no with nothing behind it.
	 *
	 * `unknown` reads as bookable. A placement nobody has forecast is not
	 * evidence of a full one, and refusing on the strength of no evidence would
	 * make every new placement unsellable until a quarter of history existed.
	 *
	 * @param string $verdict One of the verdicts above.
	 */
	public static function bookable( string $verdict ): bool {
		return self::OVERSELL !== $verdict;
	}
}
