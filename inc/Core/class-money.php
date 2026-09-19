<?php
/**
 * How a price is written, everywhere a price is shown.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Core;

/**
 * One format for money.
 *
 * The catalogue, the campaign screen and the change summary each wrote
 * `sprintf( '%1$s %2$s', $currency, number_format_i18n( $cents / 100, 2 ) )`
 * for themselves. A reviewer comparing an upgrade's two prices has to see
 * them written the same way, and three copies of one format is how one of
 * them comes to differ.
 */
final class Money {

	/**
	 * An amount in minor units, as "USD 450.00" in the site's number format.
	 *
	 * The stored integer is never rounded or re-read from the string: this is
	 * display only.
	 *
	 * @param int    $cents    Amount in cents.
	 * @param string $currency ISO 4217 code.
	 * @return string Empty when there is no currency to name.
	 */
	public static function format( int $cents, string $currency ): string {
		if ( '' === $currency ) {
			return '';
		}

		return sprintf( '%1$s %2$s', $currency, number_format_i18n( $cents / 100, 2 ) );
	}
}
