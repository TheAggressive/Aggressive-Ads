<?php
/**
 * The closed vocabulary of decision outcomes a counter may record.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * What happened to one decision opportunity, as a storable code.
 *
 * P10 named the lifecycle and P13 stores the part of it that is per
 * *opportunity* rather than per fill: a slot was asked for, and it either
 * filled or it did not, and when it did not there is a structured reason the
 * pipeline already computed.
 *
 * **One column rather than three tables.** A reader's questions — how often was
 * this slot asked for, how often did it fill, and when it did not, why — are
 * one grouped read over `(placement, day, outcome)` instead of a join. The cost
 * is that two kinds of thing share a column, which is why membership is closed
 * and checked here rather than trusted at the call site.
 *
 * Pure domain: no WordPress, no storage, so the vocabulary is testable without
 * a bootstrap and cannot drift from what the table accepts.
 */
final class Decision_Outcome {

	/** A slot was presented to decisioning. */
	public const REQUEST = 'request';

	/** A decision returned an advertisement. */
	public const FILL = 'fill';

	/**
	 * Longest code the column must hold.
	 *
	 * `competitive_exclude` is 19 characters. The column is `varchar(32)`, which
	 * leaves room for a longer reason without approaching a truncation trap —
	 * the one `wp_posts.post_status` sets at 20, where a longer value does not
	 * error but writes short and never matches on read.
	 */
	public const MAX_LENGTH = 32;

	/**
	 * Every outcome that may be stored.
	 *
	 * The two lifecycle outcomes plus the whole no-fill taxonomy. Derived from
	 * `No_Fill_Reason` rather than restated, so a reason added there is
	 * storable here without a second edit — and a reason removed there stops
	 * being writable rather than lingering as a code nothing produces.
	 *
	 * @return list<string>
	 */
	public static function all(): array {
		return array_merge(
			array( self::REQUEST, self::FILL ),
			No_Fill_Reason::all()
		);
	}

	/**
	 * Whether this code may be written to the counter table.
	 *
	 * The bound that stops a caller growing the table's cardinality by
	 * inventing an outcome — the flaw the option-backed counters had, where any
	 * string became a key and the store grew with the site.
	 *
	 * @param string $outcome Candidate code.
	 */
	public static function is_storable( string $outcome ): bool {
		return in_array( $outcome, self::all(), true );
	}

	/**
	 * Whether this outcome explains an unfilled opportunity.
	 *
	 * `request` and `fill` are counts of what was asked and what succeeded;
	 * everything else is a reason the slot stayed empty. A reader summing
	 * "no-fills" needs the distinction, and deriving it from the list is what
	 * keeps that sum correct when the taxonomy grows.
	 *
	 * @param string $outcome Storable outcome code.
	 */
	public static function is_no_fill_reason( string $outcome ): bool {
		return self::is_storable( $outcome )
			&& self::REQUEST !== $outcome
			&& self::FILL !== $outcome;
	}
}
