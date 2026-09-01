<?php
/**
 * The currencies an admin screen offers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

use Aggressive\Ads\Domain\Conversion_Rules;

/**
 * One catalogue, because two would drift.
 *
 * Conversions and packages both denominate money, and both used to ask for
 * three characters of free text against a validator that accepts any
 * `[A-Z]{3}`: "usd", "US$" and a typo were all possible and only the last was
 * caught by anything. A select fixes that, and a second copy of the list is how
 * one screen comes to offer a currency the other does not.
 *
 * **Presentation, not domain.** The codes are the domain's business —
 * `Conversion_Rules::is_valid_currency()` decides what may be stored — and the
 * names here exist only to be read. That is also why this class may call
 * `__()` and `inc/Domain/` may not.
 *
 * Not the full ISO 4217 register. A select of 180 codes is a worse control than
 * a text field, and anything left out is still reachable over REST, which
 * validates the shape rather than membership of this list. A screen editing a
 * record that already carries an unlisted code must add it to its own options —
 * a select whose value is absent renders as something else and saves that
 * instead.
 */
final class Currency_Options {

	/**
	 * The offered options, the site's own currencies first.
	 *
	 * A publisher already pricing in one currency is overwhelmingly likely to
	 * denominate in the same one, and the codes they will never use are noise
	 * in a list they have to search.
	 *
	 * The empty option leads and is not a placeholder trick: "no currency" is a
	 * real state for something worth nothing, and it has to be choosable again
	 * after somebody picks a code by mistake.
	 *
	 * @param array<int, string> $preferred Codes this site already uses, in order.
	 * @param string             $none      Label for the empty option.
	 * @return array<int, array{label: string, value: string}>
	 */
	public static function options( array $preferred, string $none ): array {
		$names   = self::names();
		$options = array(
			array(
				'label' => $none,
				'value' => '',
			),
		);

		foreach ( array_merge( $preferred, array_keys( $names ) ) as $code ) {
			$code = strtoupper( (string) $code );

			if ( ! Conversion_Rules::is_valid_currency( $code ) ) {
				continue;
			}

			foreach ( $options as $existing ) {
				if ( $existing['value'] === $code ) {
					continue 2;
				}
			}

			$options[] = array(
				'label' => isset( $names[ $code ] )
					/* translators: 1: ISO 4217 currency code, such as USD. 2: the currency's name. */
					? sprintf( __( '%1$s — %2$s', 'aggressive-ads' ), $code, $names[ $code ] )
					: $code,
				'value' => $code,
			);
		}

		return $options;
	}

	/**
	 * The one code to reach for, or empty when there is no single answer.
	 *
	 * Only when a site uses exactly one. Guessing between two would fill a
	 * field with a plausible wrong answer, and a wrong currency is not a typo:
	 * it silently changes what every total built from that record means.
	 *
	 * @param array<int, string> $preferred Codes this site already uses.
	 */
	public static function default_for( array $preferred ): string {
		$codes = array_values( $preferred );

		return 1 === count( $codes ) ? strtoupper( (string) $codes[0] ) : '';
	}

	/**
	 * The currencies offered before a site has priced anything.
	 *
	 * Names are translatable because a currency's name is, and its code is not:
	 * ISO 4217 is the same three letters in every locale, which is why the code
	 * leads the label rather than following it.
	 *
	 * @return array<string, string>
	 */
	public static function names(): array {
		return array(
			'USD' => __( 'United States dollar', 'aggressive-ads' ),
			'EUR' => __( 'Euro', 'aggressive-ads' ),
			'GBP' => __( 'Pound sterling', 'aggressive-ads' ),
			'CAD' => __( 'Canadian dollar', 'aggressive-ads' ),
			'AUD' => __( 'Australian dollar', 'aggressive-ads' ),
			'NZD' => __( 'New Zealand dollar', 'aggressive-ads' ),
			'JPY' => __( 'Japanese yen', 'aggressive-ads' ),
			'CNY' => __( 'Chinese yuan', 'aggressive-ads' ),
			'INR' => __( 'Indian rupee', 'aggressive-ads' ),
			'CHF' => __( 'Swiss franc', 'aggressive-ads' ),
			'SEK' => __( 'Swedish krona', 'aggressive-ads' ),
			'NOK' => __( 'Norwegian krone', 'aggressive-ads' ),
			'DKK' => __( 'Danish krone', 'aggressive-ads' ),
			'PLN' => __( 'Polish złoty', 'aggressive-ads' ),
			'CZK' => __( 'Czech koruna', 'aggressive-ads' ),
			'MXN' => __( 'Mexican peso', 'aggressive-ads' ),
			'BRL' => __( 'Brazilian real', 'aggressive-ads' ),
			'ZAR' => __( 'South African rand', 'aggressive-ads' ),
			'SGD' => __( 'Singapore dollar', 'aggressive-ads' ),
			'HKD' => __( 'Hong Kong dollar', 'aggressive-ads' ),
			'AED' => __( 'United Arab Emirates dirham', 'aggressive-ads' ),
			'ILS' => __( 'Israeli new shekel', 'aggressive-ads' ),
			'KRW' => __( 'South Korean won', 'aggressive-ads' ),
			'TRY' => __( 'Turkish lira', 'aggressive-ads' ),
		);
	}
}
