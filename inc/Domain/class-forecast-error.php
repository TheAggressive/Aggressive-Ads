<?php
/**
 * How wrong a forecast turned out to be, and in which direction.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Compares what a placement was forecast to supply with what it did.
 *
 * **Direction matters more than magnitude here, and not symmetrically.**
 * {@see Supply_Forecast} estimates the twentieth percentile of observed days,
 * so on roughly four windows in five the placement is expected to supply *more*
 * than it was forecast to. Under-forecasting is the design working, not a
 * defect, and a summary that reported it as error would have staff correcting a
 * model that is behaving exactly as commissioned.
 *
 * Over-forecasting is the number worth watching. It is the case where a
 * publisher sold against inventory that did not arrive, and it is the failure
 * P16 exists to prevent — so it is counted separately rather than averaged into
 * a single accuracy figure that would hide a handful of oversells inside a
 * comfortable mean.
 *
 * Pure domain: no WordPress, no storage. Every figure here is staff-only, like
 * the forecast it judges — the inventory-commerce contract puts forecast error
 * in the same sentence as the forecast value, and neither may reach an
 * advertiser.
 */
final class Forecast_Error {

	/** The window supplied more than was forecast. Expected, by design. */
	public const UNDER = 'under';

	/** The window supplied less than was forecast. The case that oversells. */
	public const OVER = 'over';

	/** The window supplied exactly what was forecast. */
	public const EXACT = 'exact';

	/**
	 * One forecast measured against its outcome.
	 *
	 * **Null when the window has not matured**, rather than zero. A window
	 * nobody has measured has not been forecast accurately; it has not been
	 * judged at all, and reporting a perfect score for it would make an
	 * unmeasured placement the best performing one on the screen.
	 *
	 * `relative` is measured against the actual, which is the convention for
	 * absolute percentage error and the only denominator that answers "how far
	 * off were we, as a share of what really happened". It is null when the
	 * window supplied nothing, because every miss against a zero denominator is
	 * infinite and an infinity is not a percentage anybody can act on.
	 *
	 * @param int|null $estimate What the forecast said, or null when there was none.
	 * @param int|null $actual   What the window supplied, or null while it is open.
	 * @return array{error: int|null, absolute: int|null, direction: string|null, relative: float|null}
	 */
	public static function measure( ?int $estimate, ?int $actual ): array {
		if ( null === $estimate || null === $actual ) {
			return array(
				'error'     => null,
				'absolute'  => null,
				'direction' => null,
				'relative'  => null,
			);
		}

		/*
		 * Actual minus estimate, so the sign reads the way the direction does:
		 * positive means the placement beat its forecast, which is the safe
		 * miss. Estimate minus actual would put the alarming case in positive
		 * numbers and invite a screen to show the reassuring one in red.
		 */
		$error = $actual - $estimate;

		if ( 0 === $error ) {
			$direction = self::EXACT;
		} elseif ( $error > 0 ) {
			$direction = self::UNDER;
		} else {
			$direction = self::OVER;
		}

		return array(
			'error'     => $error,
			'absolute'  => abs( $error ),
			'direction' => $direction,
			'relative'  => $actual > 0 ? (float) abs( $error ) / $actual : null,
		);
	}

	/**
	 * What a run of matured forecasts says about the model.
	 *
	 * **`oversold` is the headline, not `mean_absolute`.** A conservative
	 * forecast is supposed to be beaten, so a large average miss is expected
	 * and says little; a single window the publisher sold against and could not
	 * fill is the thing worth acting on. Averaging the two together produces a
	 * comfortable number with the failures hidden inside it.
	 *
	 * Windows that have not matured are skipped rather than counted as
	 * accurate, and `judged` says how many actually contributed — a summary
	 * over three windows and a summary over three hundred are different claims
	 * and must not look alike.
	 *
	 * @param array<int, array{estimate?: int|null, actual?: int|null}> $rows Matured and unmatured snapshots.
	 * @return array{judged: int, oversold: int, mean_absolute: float|null, mean_relative: float|null}
	 */
	public static function summarise( array $rows ): array {
		$judged   = 0;
		$oversold = 0;
		$absolute = 0;
		$relative = 0.0;
		$rated    = 0;

		foreach ( $rows as $row ) {
			$measured = self::measure(
				isset( $row['estimate'] ) ? (int) $row['estimate'] : null,
				isset( $row['actual'] ) ? (int) $row['actual'] : null
			);

			if ( null === $measured['direction'] ) {
				continue;
			}

			++$judged;
			$absolute += (int) $measured['absolute'];

			if ( self::OVER === $measured['direction'] ) {
				++$oversold;
			}

			if ( null !== $measured['relative'] ) {
				$relative += $measured['relative'];
				++$rated;
			}
		}

		return array(
			'judged'        => $judged,
			'oversold'      => $oversold,

			/*
			 * Null rather than zero when nothing was judged, for the reason
			 * `Fill_Figures` gives about a rate with no denominator: a mean of
			 * nothing is not nought, and a screen rendering 0 would report
			 * perfect accuracy for a placement nobody has measured.
			 */
			'mean_absolute' => $judged > 0 ? (float) $absolute / $judged : null,
			'mean_relative' => $rated > 0 ? $relative / $rated : null,
		);
	}
}
