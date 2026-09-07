<?php
/**
 * Whether a fill is supply or a timer multiplying it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The inventory grain: a page opportunity, or a refresh of one.
 *
 * Both kinds are real delivery and both record an impression. What separates
 * them is whether they are *independent supply*.
 *
 * - **Page** — a slot's first fill on a page view, so it is supply and a
 *   forecast may be built on it.
 * - **Refresh** — a later fill inside the same page view, produced by a timer.
 *   Counting it as supply forecasts a `setInterval`: rotation runs to a hundred
 *   fills per view, so one page would look like a hundred.
 */
final class Opportunity {

	/** A slot's first fill on a page view. Supply. */
	public const PAGE = 'page';

	/** A later fill inside the same page view. Delivery, not supply. */
	public const REFRESH = 'refresh';

	/**
	 * Longest value the column must hold, so the DDL and this agree.
	 */
	public const MAX_LENGTH = 8;

	/**
	 * Every kind that may be stored.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array( self::PAGE, self::REFRESH );
	}

	/**
	 * The kind a client's declared sequence describes.
	 *
	 * **The sequence is client-declared and this is the boundary that says so.**
	 * The fill endpoint is stateless and sits behind a page cache, so the server
	 * cannot infer whether a fill is a page's first — the client knows, and
	 * tells it. That is untrusted, and the mitigation is being exact about what
	 * it is trusted *for*: this partitions a supply metric and gates nothing.
	 * It does not decide whether an impression counts, which stays on the
	 * beacon's token path, and it credits no campaign and moves no money.
	 *
	 * A forged sequence can make a publisher's own inventory look busier than
	 * it is. It cannot mint an impression — and anyone able to forge one could
	 * already call the public fill route in a loop, which is strictly more
	 * powerful, so this widens nothing.
	 *
	 * **Anything unreadable is a page opportunity, and the reason is rollout
	 * rather than caution.** Every fill served from a page cached before this
	 * shipped arrives with no sequence at all, for as long as that cache lives.
	 * Reading those as refreshes would collapse a publisher's measured supply to
	 * near zero overnight and make a busy site look like it has no inventory.
	 *
	 * Be clear about the cost, because it runs the other way: over-counting
	 * supply understates utilisation, which is the *oversell* direction, not the
	 * cautious one. A placement that looks less full looks like it has room to
	 * sell. P16 owns the conservative fallback for exactly this reason — the
	 * contract requires it to declare one — and it must not read this column as
	 * if every row were a verified page view.
	 *
	 * @param mixed $sequence Fill number within the page view, zero-based.
	 */
	public static function from_sequence( mixed $sequence ): string {
		if ( ! is_numeric( $sequence ) ) {
			return self::PAGE;
		}

		return (int) $sequence > 0 ? self::REFRESH : self::PAGE;
	}

	/**
	 * Whether a stored kind is one this vocabulary defines.
	 *
	 * @param string $kind Candidate.
	 */
	public static function is_valid( string $kind ): bool {
		return in_array( $kind, self::all(), true );
	}
}
