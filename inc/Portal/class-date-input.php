<?php
/**
 * One reading of a date a person typed into the portal.
 *
 * Extracted from `Campaign_Actions`, which held the only copy, at the point a
 * second form needed it. Copying it would have put the same rule in two files
 * — and the rule is not the obvious part. The stored model is a UTC integer,
 * the person is typing in the site's timezone, and a *start* means the first
 * second of that local day while an *end* means the last. Two copies of that
 * are two chances to store a variant's window a day, or a timezone, off the
 * campaign window it has to fit inside.
 *
 * Not in `Domain\` despite being a rule: it calls `wp_timezone()`, and the
 * boundary that keeps the campaign rules testable without a bootstrap is that
 * `inc/Domain/` calls no WordPress function at all.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Portal;

use DateTimeImmutable;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Parses portal date fields into the model's UTC integers.
 */
final class Date_Input {

	/**
	 * Parses an HTML date in the WordPress timezone into a UTC Unix integer.
	 *
	 * End dates use the last second of the local day; start dates use the
	 * first. An empty input is the model's open/unset value, zero.
	 *
	 * @param string $value      YYYY-MM-DD or empty.
	 * @param bool   $end_of_day Whether to use 23:59:59.
	 * @return int|WP_Error
	 */
	public static function parse( string $value, bool $end_of_day ): int|WP_Error {
		if ( '' === $value ) {
			return 0;
		}

		$date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, wp_timezone() );

		/*
		 * The round-trip comparison is what rejects "2026-02-31": PHP rolls it
		 * forward to 3 March rather than failing, so `false === $date` alone
		 * would accept a date nobody typed.
		 */
		if ( false === $date || $date->format( 'Y-m-d' ) !== $value ) {
			return new WP_Error(
				$end_of_day ? 'aggr_end_date_invalid' : 'aggr_start_date_invalid',
				__( 'Enter a valid date in the required format.', 'aggressive-ads' ),
				array( 'status' => 422 )
			);
		}

		if ( $end_of_day ) {
			$date = $date->setTime( 23, 59, 59 );
		}

		return $date->getTimestamp();
	}

	/**
	 * Formats a stored UTC timestamp for an HTML date input in site time.
	 *
	 * The exact inverse of `parse()`, and here rather than beside its one
	 * caller so that the two halves of the round trip are read together. A
	 * formatter that renders in one timezone and a parser that reads in
	 * another lose a day, and the loss is invisible in both files separately.
	 *
	 * @param int $timestamp UTC Unix timestamp, or zero for unset.
	 * @return string YYYY-MM-DD, or empty for zero.
	 */
	public static function format( int $timestamp ): string {
		return $timestamp > 0 ? (string) wp_date( 'Y-m-d', $timestamp, wp_timezone() ) : '';
	}
}
