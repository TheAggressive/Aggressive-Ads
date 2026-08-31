<?php
/**
 * What may be presented as a server-to-server credential.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Conversion_Credential;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule here runs on a public endpoint against unauthenticated input,
 * before any lookup. A shape that slipped through would not be a database
 * error — it would be an indexed read an anonymous caller gets to choose.
 */
final class ConversionCredentialTest extends TestCase {

	/** A well-formed secret of exactly the length this plugin issues. */
	private const GOOD = 'abcdefghijklmnopqrstuvwxyz0123456789ABCDE-_';

	/**
	 * The fixture must actually be the shape under test.
	 */
	public function test_the_fixture_is_a_valid_token(): void {
		$this->assertSame( Conversion_Credential::TOKEN_LENGTH, strlen( self::GOOD ) );
		$this->assertTrue( Conversion_Credential::is_valid_token( self::GOOD ) );
	}

	/**
	 * **A trailing newline is not a valid token.**
	 *
	 * PCRE's `$` matches before one, so `/^[A-Za-z0-9\-_]+$/` would accept a
	 * secret with `\n` appended — which digests to something else entirely and
	 * fails to verify, turning a working credential into an unexplained 401
	 * nobody can reproduce. The same defect was found in
	 * `Conversion_Rules::is_valid_idempotency_key()` by its own test.
	 *
	 * The length check alone does not cover this: the newline makes it 44, so
	 * this asserts the charset rule directly at 43.
	 */
	public function test_a_trailing_newline_is_refused_by_the_charset_and_not_only_the_length(): void {
		$with_newline = substr( self::GOOD, 0, Conversion_Credential::TOKEN_LENGTH - 1 ) . "\n";

		$this->assertSame( Conversion_Credential::TOKEN_LENGTH, strlen( $with_newline ) );
		$this->assertFalse( Conversion_Credential::is_valid_token( $with_newline ) );
	}

	/**
	 * Shapes that are not ours.
	 *
	 * @param string $token  Candidate.
	 * @param string $reason What it is testing.
	 */
	#[DataProvider( 'malformed_tokens' )]
	public function test_a_malformed_token_is_refused( string $token, string $reason ): void {
		$this->assertFalse( Conversion_Credential::is_valid_token( $token ), $reason );
	}

	/**
	 * Candidates that must not verify, with what each one is about.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function malformed_tokens(): array {
		return array(
			'empty'          => array( '', 'An empty string is not a credential.' ),
			'one short'      => array( substr( self::GOOD, 0, 42 ), 'A truncated secret must not verify.' ),
			'one long'       => array( self::GOOD . 'x', 'A padded secret must not verify.' ),
			'standard b64'   => array( substr( self::GOOD, 0, 41 ) . '+/', 'This plugin issues URL-safe base64 only.' ),
			'padded'         => array( substr( self::GOOD, 0, 41 ) . '==', 'Padding is stripped at issue.' ),
			'with space'     => array( substr( self::GOOD, 0, 42 ) . ' ', 'Whitespace is not in the charset.' ),
			'with null byte' => array( substr( self::GOOD, 0, 42 ) . "\0", 'A null byte is not in the charset.' ),
		);
	}

	/**
	 * The header forms a real client sends.
	 *
	 * @param string $header   Authorization header value.
	 * @param string $expected Secret it should yield.
	 * @param string $reason   What it is testing.
	 */
	#[DataProvider( 'headers' )]
	public function test_the_secret_is_taken_from_the_header( string $header, string $expected, string $reason ): void {
		$this->assertSame( $expected, Conversion_Credential::token_from_header( $header ), $reason );
	}

	/**
	 * Authorization header forms, and the secret each should yield.
	 *
	 * @return array<string, array{0: string, 1: string, 2: string}>
	 */
	public static function headers(): array {
		return array(
			'bearer'            => array( 'Bearer ' . self::GOOD, self::GOOD, 'The ordinary form must work.' ),
			'lowercase scheme'  => array( 'bearer ' . self::GOOD, self::GOOD, 'RFC 7235 makes the scheme case-insensitive.' ),
			'surrounding space' => array( '  Bearer ' . self::GOOD . '  ', self::GOOD, 'Proxies add whitespace.' ),
			'no scheme'         => array( self::GOOD, '', 'A bare secret is not an Authorization header.' ),
			'wrong scheme'      => array( 'Basic ' . self::GOOD, '', 'Basic would look like a password to every log scrubber in between.' ),
			'three parts'       => array( 'Bearer ' . self::GOOD . ' extra', '', 'A credential has no parameters.' ),
			'empty'             => array( '', '', 'No header is no credential.' ),
			'scheme only'       => array( 'Bearer', '', 'A scheme with nothing after it is malformed.' ),
			'malformed secret'  => array( 'Bearer not-a-real-token', '', 'The shape is checked before any lookup.' ),
		);
	}

	/**
	 * **A null byte in a label is caught before the trim, not after.**
	 *
	 * `trim()` strips `\0`, so checking a trimmed string would report this
	 * clean — the defect already found and fixed in `Conversion_Definition`.
	 */
	public function test_a_control_character_in_a_label_is_refused(): void {
		$this->assertFalse( Conversion_Credential::is_valid_label( "Shop\0" ) );
		$this->assertFalse( Conversion_Credential::is_valid_label( "Shop\n integration" ) );
		$this->assertFalse( Conversion_Credential::is_valid_label( "Shop\x7F" ) );
	}

	/**
	 * A label has to fit the column and say something.
	 */
	public function test_a_label_is_bounded_at_both_ends(): void {
		$this->assertFalse( Conversion_Credential::is_valid_label( '' ) );
		$this->assertTrue( Conversion_Credential::is_valid_label( 'W' ) );
		$this->assertTrue(
			Conversion_Credential::is_valid_label( str_repeat( 'a', Conversion_Credential::MAX_LABEL_LENGTH ) )
		);
		$this->assertFalse(
			Conversion_Credential::is_valid_label( str_repeat( 'a', Conversion_Credential::MAX_LABEL_LENGTH + 1 ) ),
			'varchar(191) truncates rather than refusing, and two labels that differ only past the cut become one.'
		);
	}

	/**
	 * Revocation is a timestamp, so any non-zero value means revoked.
	 */
	public function test_only_an_unrevoked_credential_is_live(): void {
		$this->assertTrue( Conversion_Credential::is_live( 0 ) );
		$this->assertFalse( Conversion_Credential::is_live( 1 ) );
		$this->assertFalse( Conversion_Credential::is_live( 1700000000 ) );
	}
}
