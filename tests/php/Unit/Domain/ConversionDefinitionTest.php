<?php
/**
 * What a conversion definition will and will not accept.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Conversion_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The definition is the trusted half of conversion tracking, so what it refuses
 * matters more than what it stores.
 */
final class ConversionDefinitionTest extends TestCase {

	/**
	 * One valid definition, with only the fields a caller varies.
	 *
	 * @param array<string, mixed> $overrides Fields to replace.
	 * @return array<string, mixed>
	 */
	private static function input( array $overrides = array() ): array {
		return array_merge(
			array(
				'name'                 => 'Purchase',
				'org_id'               => 12,
				'window_seconds'       => 2592000,
				'default_value_micros' => 4990000,
				'currency'             => 'USD',
				'allow_s2s'            => true,
				'status'               => Conversion_Definition::STATUS_ACTIVE,
			),
			$overrides
		);
	}

	public function test_a_complete_definition_validates(): void {
		$result = Conversion_Definition::validate( self::input() );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'Purchase', $result['value']['name'] );
		$this->assertSame( 2592000, $result['value']['window_seconds'] );
		$this->assertTrue( $result['value']['allow_s2s'] );
	}

	/**
	 * Names, at and either side of the column width.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function names(): array {
		return array(
			'ordinary'             => array( 'Purchase', true ),
			'trimmed to something' => array( '  Signup  ', true ),
			'exactly the maximum'  => array( str_repeat( 'n', 191 ), true ),
			'one past the maximum' => array( str_repeat( 'n', 192 ), false ),
			'empty'                => array( '', false ),
			'only whitespace'      => array( "   \t ", false ),
			'a newline'            => array( "Purchase\nSignup", false ),
			'a null byte'          => array( "Purchase\0", false ),
			'an escape character'  => array( "Purchase\x1b[31m", false ),
		);
	}

	/**
	 * Asserts one candidate name.
	 *
	 * @param string $name     Candidate.
	 * @param bool   $expected Whether it validates.
	 */
	#[DataProvider( 'names' )]
	public function test_names_are_bounded_and_printable( string $name, bool $expected ): void {
		$result = Conversion_Definition::validate( self::input( array( 'name' => $name ) ) );

		$this->assertSame( $expected, $result['ok'] );
	}

	/**
	 * A name is refused rather than silently truncated or stripped.
	 *
	 * The name is shown to staff and copied into an audit context. Rewriting
	 * what somebody typed is how the name in the log stops matching the name on
	 * the screen, and nobody ever notices which one is wrong.
	 */
	public function test_an_over_long_name_is_refused_not_truncated(): void {
		$result = Conversion_Definition::validate( self::input( array( 'name' => str_repeat( 'n', 300 ) ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'name', $result['errors'] );
	}

	/**
	 * A value with no currency is a number nobody can add up.
	 */
	public function test_a_value_without_a_currency_is_refused(): void {
		$result = Conversion_Definition::validate(
			self::input(
				array(
					'default_value_micros' => 1000000,
					'currency'             => '',
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'currency', $result['errors'] );
	}

	/**
	 * A valueless definition — a signup — is normal and must validate.
	 */
	public function test_a_valueless_definition_needs_no_currency(): void {
		$result = Conversion_Definition::validate(
			self::input(
				array(
					'name'                 => 'Signup',
					'default_value_micros' => 0,
					'currency'             => '',
				)
			)
		);

		$this->assertTrue( $result['ok'] );
		$this->assertSame( '', $result['value']['currency'] );
	}

	/**
	 * Currency is normalized upward, because ISO 4217 is uppercase and a
	 * publisher typing "usd" meant USD.
	 */
	public function test_currency_is_uppercased(): void {
		$result = Conversion_Definition::validate( self::input( array( 'currency' => 'eur' ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( 'EUR', $result['value']['currency'] );
	}

	public function test_a_malformed_currency_is_refused(): void {
		$result = Conversion_Definition::validate( self::input( array( 'currency' => 'dollars' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'currency', $result['errors'] );
	}

	/**
	 * Windows are clamped, never refused.
	 *
	 * A definition that refused to save because its window was out of range
	 * would be a definition nobody could fix from the screen that shows it.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function windows(): array {
		return array(
			'a sane thirty days' => array( 2592000, 2592000 ),
			'below the floor'    => array( 60, Conversion_Rules::MIN_WINDOW_SECONDS ),
			'above the ceiling'  => array( 99999999, Conversion_Rules::MAX_WINDOW_SECONDS ),
			'missing'            => array( null, Conversion_Rules::DEFAULT_WINDOW_SECONDS ),
			'not a number'       => array( 'a month', Conversion_Rules::DEFAULT_WINDOW_SECONDS ),
		);
	}

	/**
	 * Asserts one submitted window.
	 *
	 * @param mixed $submitted Raw value.
	 * @param int   $expected  Clamped result.
	 */
	#[DataProvider( 'windows' )]
	public function test_windows_are_clamped_into_something_workable( mixed $submitted, int $expected ): void {
		$result = Conversion_Definition::validate( self::input( array( 'window_seconds' => $submitted ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( $expected, $result['value']['window_seconds'] );
	}

	/**
	 * A missing window is the default, never zero.
	 *
	 * Zero would mean every conversion is out of window, so the definition would
	 * accept reports and attribute none of them — working, and silently
	 * measuring nothing.
	 */
	public function test_a_missing_window_is_never_zero(): void {
		$result = Conversion_Definition::validate( self::input( array( 'window_seconds' => null ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertGreaterThan( 0, $result['value']['window_seconds'] );
	}

	public function test_an_unknown_status_is_refused(): void {
		$result = Conversion_Definition::validate( self::input( array( 'status' => 'deleted' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'status', $result['errors'] );
	}

	public function test_a_missing_status_defaults_to_active(): void {
		$result = Conversion_Definition::validate( self::input( array( 'status' => null ) ) );

		$this->assertTrue( $result['ok'] );
		$this->assertSame( Conversion_Definition::STATUS_ACTIVE, $result['value']['status'] );
	}

	/**
	 * Only an active definition accepts a report.
	 */
	public function test_only_an_active_definition_accepts_reports(): void {
		$this->assertTrue( Conversion_Definition::accepts_reports( Conversion_Definition::STATUS_ACTIVE ) );
		$this->assertFalse( Conversion_Definition::accepts_reports( Conversion_Definition::STATUS_ARCHIVED ) );
		$this->assertFalse( Conversion_Definition::accepts_reports( '' ) );
		$this->assertFalse( Conversion_Definition::accepts_reports( 'active ' ) );
	}

	/**
	 * Public keys, including the trailing-newline hole that `$` would leave.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function public_keys(): array {
		return array(
			'a minted key'     => array( str_repeat( 'a', 32 ), true ),
			'mixed hex'        => array( '0123456789abcdef0123456789abcdef', true ),
			'uppercase'        => array( str_repeat( 'A', 32 ), false ),
			'too short'        => array( str_repeat( 'a', 31 ), false ),
			'too long'         => array( str_repeat( 'a', 33 ), false ),
			'not hex'          => array( str_repeat( 'z', 32 ), false ),
			'empty'            => array( '', false ),
			'trailing newline' => array( str_repeat( 'a', 32 ) . "\n", false ),
			'a sql fragment'   => array( "' OR 1=1 -- ", false ),
		);
	}

	/**
	 * Asserts one candidate public key.
	 *
	 * @param string $key      Candidate.
	 * @param bool   $expected Whether it has the minted shape.
	 */
	#[DataProvider( 'public_keys' )]
	public function test_public_keys_must_have_the_minted_shape( string $key, bool $expected ): void {
		$this->assertSame( $expected, Conversion_Definition::is_valid_public_key( $key ) );
	}

	/**
	 * A negative organization id is refused rather than cast to something.
	 *
	 * `(int) '-1'` is `-1`, and an unsigned column would store it as an
	 * enormous positive number that matches no organization and no tenancy
	 * check — a definition belonging to nobody, visible to everybody's query
	 * only by accident.
	 */
	public function test_a_negative_org_id_is_refused(): void {
		$result = Conversion_Definition::validate( self::input( array( 'org_id' => -1 ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'org_id', $result['errors'] );
	}

	/**
	 * Every problem is reported at once, not one per round trip.
	 */
	public function test_multiple_problems_are_all_named(): void {
		$result = Conversion_Definition::validate(
			self::input(
				array(
					'name'     => '',
					'currency' => 'nope',
					'status'   => 'gone',
				)
			)
		);

		$this->assertFalse( $result['ok'] );
		$this->assertContains( 'name', $result['errors'] );
		$this->assertContains( 'currency', $result['errors'] );
		$this->assertContains( 'status', $result['errors'] );
	}

	/**
	 * A failed validation returns no value at all.
	 *
	 * A partially-applied definition would accept reports under a window nobody
	 * chose, which is worse than refusing the save.
	 */
	public function test_a_rejected_definition_yields_nothing_to_store(): void {
		$result = Conversion_Definition::validate( self::input( array( 'name' => '' ) ) );

		$this->assertFalse( $result['ok'] );
		$this->assertArrayNotHasKey( 'value', $result );
	}
}
