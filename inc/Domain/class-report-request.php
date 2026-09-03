<?php
/**
 * Turning untrusted range input into a bounded period, or refusing it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * One place that reads a range off a request, for every screen that offers one.
 *
 * Two surfaces now take a range from a query string — the advertiser dashboard
 * and the publisher report — and they must agree about what "31 days" means,
 * what an impossible date does and which input wins when two are supplied.
 * Written twice, they would agree until the day one of them was edited.
 *
 * **Refusal is recorded, not swallowed.** `Report_Period` returns null for a
 * malformed or over-long range because clamping answers a question nobody
 * asked. That leaves the screen needing to know the difference between "no
 * range was requested" and "the requested range was refused", because only the
 * second one is worth telling the reader about. `rejected` is that difference.
 *
 * Pure domain: no WordPress, no superglobals. The caller reads the parameters;
 * this decides what they mean.
 */
final class Report_Request {

	/**
	 * Not constructible without going through `resolve()`.
	 *
	 * @param Report_Period $period   The window to report on.
	 * @param bool          $rejected Whether input was supplied and could not be used.
	 */
	private function __construct(
		public readonly Report_Period $period,
		public readonly bool $rejected
	) {
	}

	/**
	 * The window a request asks for, or the fallback with a refusal recorded.
	 *
	 * **An explicit range wins over a preset**, because it is the more specific
	 * statement: a reader who typed two dates has said what they want more
	 * precisely than one who left a menu on its default. A preset is only
	 * consulted when no usable range was given.
	 *
	 * Supplying one half of a range is not a range. It reads as a refusal
	 * rather than as an open-ended window, which is the whole reason periods
	 * cannot be built unbounded.
	 *
	 * @param string          $from     Requested first UTC day, or '' when absent.
	 * @param string          $to       Requested last UTC day, or '' when absent.
	 * @param int             $days     Requested preset length, or 0 when absent.
	 * @param array<int, int> $windows Preset lengths this screen offers.
	 * @param string          $today    Current UTC day, `Y-m-d`.
	 * @param int             $fallback Length to fall back to.
	 */
	public static function resolve( string $from, string $to, int $days, array $windows, string $today, int $fallback ): self {
		if ( '' !== $from || '' !== $to ) {
			$period = Report_Period::between( $from, $to );

			return new self(
				$period ?? Report_Period::trailing( $fallback, $today ),
				null === $period
			);
		}

		if ( 0 !== $days ) {
			return in_array( $days, $windows, true )
				? new self( Report_Period::trailing( $days, $today ), false )
				: new self( Report_Period::trailing( $fallback, $today ), true );
		}

		return new self( Report_Period::trailing( $fallback, $today ), false );
	}
}
