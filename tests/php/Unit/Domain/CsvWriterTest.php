<?php
/**
 * CSV serialization and spreadsheet-formula neutralization.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Csv_Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The quoting rules decide whether the file parses; the neutralization rules
 * decide whether opening it is safe. Both are asserted here, separately,
 * because passing one while failing the other looks identical in a text editor.
 */
final class CsvWriterTest extends TestCase {

	/**
	 * Plain fields are written unquoted.
	 *
	 * @return void
	 */
	public function test_plain_fields_need_no_quoting(): void {
		$this->assertSame( "2026-08-15,Spring sale,42\r\n", Csv_Writer::row( array( '2026-08-15', 'Spring sale', 42 ) ) );
	}

	/**
	 * Rows end CRLF, as RFC 4180 requires.
	 *
	 * @return void
	 */
	public function test_rows_end_with_crlf(): void {
		$this->assertStringEndsWith( "\r\n", Csv_Writer::row( array( 'a' ) ) );
	}

	/**
	 * Delimiters, quotes and newlines are quoted and doubled.
	 *
	 * @return void
	 */
	public function test_separators_and_quotes_are_escaped(): void {
		$this->assertSame( "\"a,b\"\r\n", Csv_Writer::row( array( 'a,b' ) ) );
		$this->assertSame( "\"say \"\"hi\"\"\"\r\n", Csv_Writer::row( array( 'say "hi"' ) ) );
		$this->assertSame( "\"two\nlines\"\r\n", Csv_Writer::row( array( "two\nlines" ) ) );
	}

	/**
	 * Null is an empty cell, not the string "null" and not a zero.
	 *
	 * @return void
	 */
	public function test_null_is_an_empty_cell(): void {
		$this->assertSame( "a,,b\r\n", Csv_Writer::row( array( 'a', null, 'b' ) ) );
	}

	/**
	 * Numbers are never quoted, so a spreadsheet reads them as numbers.
	 *
	 * @return void
	 */
	public function test_numbers_are_written_bare(): void {
		$this->assertSame( "1,2.5\r\n", Csv_Writer::row( array( 1, 2.5 ) ) );
	}

	/**
	 * The point of the class: a campaign name that is a formula is inert.
	 *
	 * Every one of these is a legal campaign name this plugin accepts, and each
	 * would execute on open in Excel, LibreOffice or Sheets without the prefix.
	 *
	 * @param string $payload Attacker-controlled field.
	 * @return void
	 */
	#[DataProvider( 'formula_provider' )]
	public function test_formula_leading_fields_are_neutralized( string $payload ): void {
		// Parsed back rather than string-matched: quoting doubles any `"` in
		// the payload, so the raw bytes legitimately differ from the input
		// while the *value* must not. Asserting on the serialized form would
		// force the test to re-implement the escaping it is checking.
		$parsed = str_getcsv( rtrim( Csv_Writer::row( array( $payload ) ), "\r\n" ), ',', '"', '\\' );

		$this->assertSame(
			"'" . $payload,
			$parsed[0],
			'A neutralized cell must be the original value behind one leading apostrophe.'
		);
	}

	/**
	 * Payloads that spreadsheets evaluate.
	 *
	 * @return array<string, array{0: string}>
	 */
	public static function formula_provider(): array {
		return array(
			'equals'          => array( '=1+1' ),
			'hyperlink'       => array( '=HYPERLINK("https://attacker.example","Click")' ),
			'plus'            => array( '+1+1' ),
			'minus'           => array( '-1+1' ),
			'at'              => array( '@SUM(A1:A9)' ),
			'tab'             => array( "\t=1+1" ),
			'carriage return' => array( "\r=1+1" ),
			'dde'             => array( '=cmd|\' /C calc\'!A0' ),
		);
	}

	/**
	 * A formula payload that also contains a comma stays one cell — the guard
	 * must not be defeated by making the field require quoting.
	 *
	 * @return void
	 */
	public function test_a_quoted_field_is_still_neutralized(): void {
		$this->assertSame( "\"'=SUM(1,2)\"\r\n", Csv_Writer::row( array( '=SUM(1,2)' ) ) );
	}

	/**
	 * A leader anywhere but the first character is ordinary text.
	 *
	 * @return void
	 */
	public function test_interior_symbols_are_left_alone(): void {
		$this->assertSame( "Buy 1 get 1 - half price\r\n", Csv_Writer::row( array( 'Buy 1 get 1 - half price' ) ) );
	}

	/**
	 * A document leads with the BOM and then the header row.
	 *
	 * @return void
	 */
	public function test_document_starts_with_a_bom_and_header(): void {
		$doc = Csv_Writer::document( array( 'Date', 'Clicks' ), array( array( '2026-08-15', 3 ) ) );

		$this->assertStringStartsWith( Csv_Writer::BOM . "Date,Clicks\r\n", $doc );
		$this->assertStringEndsWith( "2026-08-15,3\r\n", $doc );
	}
}
