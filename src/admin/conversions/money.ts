/**
 * The decimal a person types, and the integer the server stores.
 *
 * Two separate defects live here, and both have been in this repository before.
 *
 * **The value is parsed on the string, never through a float.** `4.99 * 1000000`
 * is not 4990000 in binary floating point, and the packages screen carries the
 * same note for the same reason: multiplying a parsed float is how a price
 * becomes one unit short of what somebody typed.
 *
 * **What a person is typing is not a number yet.** A field that round-tripped
 * its own text through `toFixed( 2 )` on every keystroke rewrote the box under
 * the caret: typing 4, 9, ., 9, 0 produced "4.02" and stored it, because "4"
 * became "4.00" and the next key landed after the decimals. So the form holds
 * the literal text and converts once, and this module is only ever called at
 * the edges.
 */

/** Millionths of a currency unit, which is how a definition stores its value. */
const MICROS = 6;

/**
 * A typed decimal as micros, or 0 for anything that is not a positive amount.
 *
 * Zero rather than an error: "no value" is a real and common state for a
 * definition — a signup is worth nothing in itself — so an empty field and an
 * unparseable one arrive at the same, safe answer.
 *
 * A comma is accepted as the decimal separator. Half of Europe types one, the
 * field is a plain text input, and silently reading "4,99" as no value at all
 * is the worst of the available behaviours.
 */
export function amountToMicros( amount: string ): number {
	const match = /^(\d*)(?:[.,](\d{0,6}))?$/.exec( amount.trim() );

	if ( ! match ) {
		return 0;
	}

	const whole = Number( match[ 1 ] || '0' );
	const fraction = Number(
		( match[ 2 ] ?? '' ).padEnd( MICROS, '0' ) || '0'
	);

	return whole * 10 ** MICROS + fraction;
}

/**
 * Micros as a decimal string, for display only.
 *
 * Trailing zeroes past the cent are dropped, so a whole amount reads "49.90"
 * rather than "49.900000" — but a sub-cent value keeps the digits it needs,
 * because the whole reason the column is micros is that a per-click value is
 * routinely smaller than a cent.
 */
export function microsToAmount( micros: number ): string {
	if ( micros <= 0 ) {
		return '';
	}

	const whole = Math.floor( micros / 10 ** MICROS );
	const fraction = String( micros % 10 ** MICROS )
		.padStart( MICROS, '0' )
		.replace( /(\d\d)(\d*?)0*$/, '$1$2' );

	return `${ whole }.${ fraction }`;
}
