<?php
/**
 * What a publisher permits a placement to do on a timer.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The publisher's rule about their own inventory, bounding the editor's request.
 *
 * Rotation shipped as a block attribute: an editor picks `rotate` and
 * `rotateSeconds`, and the only limit was a one-second floor the client applied
 * to itself. That makes the person laying out a page the person who decides how
 * much inventory exists, because every rotation is another impression — a
 * hundred per page view at the cap.
 *
 * The delivery code said as much and said it could not fix it from there, which
 * was correct: a clamp on an interval is a client-side courtesy. What was
 * missing is a policy owned by whoever owns the inventory. This is that policy,
 * and the direction is the invariant — **it bounds the block's request, never
 * the reverse.** A block asking for a one-second rotation on a placement that
 * forbids refresh does not refresh.
 *
 * Pure domain: no WordPress, no storage.
 */
final class Refresh_Policy {

	/**
	 * The shortest interval any policy may permit.
	 *
	 * Matches the client's floor and `Slot_Options::MIN_ROTATE_SECONDS`. A
	 * publisher may be stricter and cannot be looser: an interval of no length
	 * is a request loop, and that is not theirs to opt into.
	 */
	public const MIN_INTERVAL_SECONDS = 1;

	/**
	 * The refresh cap the client already enforces on its own.
	 *
	 * Mirrors `MAX_ROTATIONS` in `view.js`, which stops a tab left open
	 * overnight spending a campaign's whole daily cap. It lives here because the
	 * migration needs it: an existing placement is handed what the client
	 * already permitted, so the number has to be nameable from PHP.
	 *
	 * A constant duplicated across two languages is a constant that drifts.
	 * `ClientConstantParityTest` reads the JavaScript and fails if these stop
	 * agreeing — which is what the editor's docblock claimed for the interval
	 * floor long before anything actually checked it.
	 */
	public const LEGACY_CLIENT_MAX_PER_VIEW = 100;

	/**
	 * The interval a placement permits when it says nothing.
	 *
	 * Thirty rather than the block's ten. A default that silently matched the
	 * editor's default would make the policy invisible — it has to be a
	 * publisher's number, not a restatement of somebody else's.
	 */
	public const DEFAULT_INTERVAL_SECONDS = 30;

	/**
	 * Refreshes permitted per page view when a placement says nothing.
	 *
	 * Six, against the client's hard stop of a hundred. At the default interval
	 * that is three minutes of continuous viewing, which is longer than almost
	 * any real dwell on one page — and a number a publisher can raise
	 * deliberately rather than inherit by accident.
	 */
	public const DEFAULT_MAX_PER_VIEW = 6;

	/**
	 * Constructor.
	 *
	 * Private so a policy is only built through a named reader, which is what
	 * keeps the clamping from being skippable.
	 *
	 * @param bool $enabled          Whether the placement may refresh at all.
	 * @param int  $interval_seconds Shortest interval permitted, already floored.
	 * @param int  $max_per_view     Refreshes permitted per page view.
	 */
	private function __construct(
		public readonly bool $enabled,
		public readonly int $interval_seconds,
		public readonly int $max_per_view
	) {
	}

	/**
	 * The policy a placement that has never been configured gets.
	 *
	 * **Refresh is off.** A placement that nobody has made a decision about is
	 * not inventory somebody chose to multiply, and turning it on by default
	 * would inflate every existing publisher's supply on upgrade without anyone
	 * asking for it.
	 */
	public static function defaults(): self {
		return new self( false, self::DEFAULT_INTERVAL_SECONDS, self::DEFAULT_MAX_PER_VIEW );
	}

	/**
	 * Reads a policy out of stored placement values.
	 *
	 * @param mixed $enabled          Whether refresh is permitted.
	 * @param mixed $interval_seconds Shortest interval permitted.
	 * @param mixed $max_per_view     Refreshes permitted per page view.
	 */
	public static function from_stored( mixed $enabled, mixed $interval_seconds, mixed $max_per_view ): self {
		return new self(
			self::truthy( $enabled ),
			self::interval( $interval_seconds ),
			self::cap( $max_per_view )
		);
	}

	/**
	 * The interval a slot should actually use, given what its block asked for.
	 *
	 * The larger of the two, always. A block asking to rotate faster than the
	 * placement permits gets the placement's number; one asking to rotate more
	 * slowly is honoured, because a longer interval records fewer impressions
	 * and refusing it would refuse the safer setting.
	 *
	 * @param int $requested What the block asked for.
	 */
	public function interval_for( int $requested ): int {
		return max( $this->interval_seconds, $requested );
	}

	/**
	 * Whether a fill claiming this sequence is within what the policy permits.
	 *
	 * **The server-side half of an honest-client assumption.** The sequence
	 * arrives from the browser, so a fill claiming to be refresh four hundred on
	 * a placement capped at six is discardable without any client cooperation.
	 * That turns "we trust the count" into "we trust it inside a bound the
	 * publisher set", which is a much smaller thing to trust.
	 *
	 * A page opportunity is always permitted: sequence zero is what every first
	 * fill sends, and refusing it would refuse delivery.
	 *
	 * @param int $sequence Fill number within the page view, zero-based.
	 */
	public function permits_sequence( int $sequence ): bool {
		if ( $sequence <= 0 ) {
			return true;
		}

		return $this->enabled && $sequence <= $this->max_per_view;
	}

	/**
	 * The client's view of this policy.
	 *
	 * Every key is always present, including defaulted ones. The store treats an
	 * absent key as the shipped behaviour, which makes an omission
	 * indistinguishable from a choice.
	 *
	 * @return array<string, bool|int>
	 */
	public function to_context(): array {
		return array(
			'refreshEnabled'    => $this->enabled,
			'refreshSeconds'    => $this->interval_seconds,
			'refreshMaxPerView' => $this->max_per_view,
		);
	}

	/**
	 * A requested interval, floored at what any policy may permit.
	 *
	 * @param mixed $requested Stored value.
	 */
	private static function interval( mixed $requested ): int {
		if ( ! is_numeric( $requested ) ) {
			return self::DEFAULT_INTERVAL_SECONDS;
		}

		return max( self::MIN_INTERVAL_SECONDS, (int) $requested );
	}

	/**
	 * A per-view cap, floored at zero.
	 *
	 * Zero is meaningful and is not the same as refresh being off: a publisher
	 * may leave refresh enabled and set the cap to zero while they decide, and
	 * `permits_sequence()` then refuses every refresh without discarding the
	 * rest of the configuration.
	 *
	 * @param mixed $requested Stored value.
	 */
	private static function cap( mixed $requested ): int {
		if ( ! is_numeric( $requested ) ) {
			return self::DEFAULT_MAX_PER_VIEW;
		}

		return max( 0, (int) $requested );
	}

	/**
	 * A loosely written boolean, read the way the REST API reads one.
	 *
	 * Same rule as `Slot_Options`, and written out for the same reason: this is
	 * the domain layer and calls no WordPress function.
	 * `SlotOptionsRestParityTest` holds that copy to core's; this one is only
	 * ever handed post meta, which is a string or an int.
	 *
	 * @param mixed $value Stored value.
	 */
	private static function truthy( mixed $value ): bool {
		if ( is_string( $value ) && in_array( strtolower( $value ), array( 'false', '0' ), true ) ) {
			return false;
		}

		return (bool) $value;
	}
}
