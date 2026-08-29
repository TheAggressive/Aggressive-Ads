<?php
/**
 * Attribution and idempotency, asserted at their edges.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Conversion_Rules;
use Aggressive\Ads\Domain\Measurement_Event_Type;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every rule here exists because the storage layer cannot enforce it, so every
 * one is asserted on its boundary rather than in its middle.
 */
final class ConversionRulesTest extends TestCase {

	/**
	 * Idempotency keys, at and either side of both limits.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function keys(): array {
		return array(
			'a uuid'                        => array( '3f2504e0-4f89-11d3-9a0c-0305e82c3301', true ),
			'a woocommerce order key'       => array( 'wc_order_a1B2c3D4e5F6', true ),
			'dots and colons'               => array( 'shop.example:order:1099', true ),
			'exactly the minimum'           => array( '12345678', true ),
			'one below the minimum'         => array( '1234567', false ),
			'exactly the maximum'           => array( str_repeat( 'k', 64 ), true ),
			'one past the maximum'          => array( str_repeat( 'k', 65 ), false ),
			'empty'                         => array( '', false ),
			'a space'                       => array( 'order 1099', false ),
			'a quote'                       => array( "order'1099", false ),
			'a percent, for a LIKE pattern' => array( 'order%1099', false ),
			'a newline'                     => array( "order1099\n", false ),
		);
	}

	/**
	 * Asserts one candidate key.
	 *
	 * @param string $key      Candidate key.
	 * @param bool   $expected Whether it is usable.
	 */
	#[DataProvider( 'keys' )]
	public function test_idempotency_keys_are_bounded_and_safe( string $key, bool $expected ): void {
		$this->assertSame( $expected, Conversion_Rules::is_valid_idempotency_key( $key ) );
	}

	/**
	 * The 65th character is the whole reason this rule exists.
	 *
	 * The column is `varchar(64)`. MySQL outside strict mode truncates rather
	 * than refusing, so two keys differing only past character 64 would collapse
	 * onto one row and the second conversion would be refused as a duplicate —
	 * an undercount with nothing in any log to show for it. Asserting the two
	 * are distinct *and* both refused is what stops the column deciding.
	 */
	public function test_two_keys_differing_only_past_the_column_width_are_both_refused(): void {
		$base  = str_repeat( 'k', 64 );
		$one   = $base . 'A';
		$other = $base . 'B';

		$this->assertNotSame( $one, $other, 'The fixture must actually differ, or this proves nothing.' );
		$this->assertSame( substr( $one, 0, 64 ), substr( $other, 0, 64 ), 'They must be identical within the column width.' );

		$this->assertFalse( Conversion_Rules::is_valid_idempotency_key( $one ) );
		$this->assertFalse( Conversion_Rules::is_valid_idempotency_key( $other ) );
	}

	/**
	 * Only an interaction the lifecycle already permits may be attributed.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function interactions(): array {
		return array(
			'a click'      => array( Measurement_Event_Type::TYPE_CLICK, true ),
			'a view'       => array( Measurement_Event_Type::TYPE_VIEWABLE, true ),
			'a served ad'  => array( Measurement_Event_Type::TYPE_SERVED, false ),
			'a fill'       => array( Measurement_Event_Type::TYPE_FILL, false ),
			'a request'    => array( Measurement_Event_Type::TYPE_REQUEST, false ),
			'a no-fill'    => array( Measurement_Event_Type::TYPE_NO_FILL, false ),
			'a conversion' => array( Measurement_Event_Type::TYPE_CONVERSION, false ),
			'nonsense'     => array( 'purchase', false ),
			'empty'        => array( '', false ),
		);
	}

	/**
	 * Asserts one interaction type.
	 *
	 * @param string $event    Attributed interaction.
	 * @param bool   $expected Whether a conversion may follow it.
	 */
	#[DataProvider( 'interactions' )]
	public function test_only_a_click_or_a_view_may_be_attributed( string $event, bool $expected ): void {
		$this->assertSame( $expected, Conversion_Rules::is_attributable_event( $event ) );
	}

	/**
	 * A served ad is the one worth stating plainly: it is in the lifecycle,
	 * it precedes both attributable events, and it is not one of them. An
	 * implementation that allowed it would attribute every filled slot.
	 */
	public function test_a_served_ad_alone_attributes_nothing(): void {
		$this->assertFalse( Conversion_Rules::is_attributable_event( Measurement_Event_Type::TYPE_SERVED ) );
	}

	/**
	 * Window boundaries, in seconds from the interaction.
	 *
	 * @return array<string, array{int, int, bool}>
	 */
	public static function windows(): array {
		$window = 3600;

		return array(
			'the same second'       => array( 0, $window, true ),
			'one second inside'     => array( $window - 1, $window, true ),
			'exactly on the window' => array( $window, $window, true ),
			'one second outside'    => array( $window + 1, $window, false ),
			'a second before'       => array( -1, $window, false ),
			'long before'           => array( -86400, $window, false ),
		);
	}

	/**
	 * Asserts one point relative to the window.
	 *
	 * @param int  $offset   Seconds from the interaction to the conversion.
	 * @param int  $window   Configured window.
	 * @param bool $expected Whether it attributes.
	 */
	#[DataProvider( 'windows' )]
	public function test_the_window_is_inclusive_at_its_last_second( int $offset, int $window, bool $expected ): void {
		$interaction = 1700000000;

		$this->assertSame(
			$expected,
			Conversion_Rules::is_within_window( $interaction, $interaction + $offset, $window )
		);
	}

	/**
	 * A conversion before its own cause is a skewed or forged clock, and
	 * attributing it would let a backdated report claim a click that had not
	 * happened when it claims to have converted.
	 */
	public function test_a_conversion_cannot_precede_its_interaction(): void {
		$this->assertFalse( Conversion_Rules::is_within_window( 1700000000, 1699999999, 2592000 ) );
	}

	/**
	 * Nonsense timestamps attribute nothing rather than attributing to the epoch.
	 */
	public function test_missing_timestamps_attribute_nothing(): void {
		$this->assertFalse( Conversion_Rules::is_within_window( 0, 1700000000, 3600 ) );
		$this->assertFalse( Conversion_Rules::is_within_window( 1700000000, 0, 3600 ) );
		$this->assertFalse( Conversion_Rules::is_within_window( 1700000000, 1700000000, 0 ) );
	}

	/**
	 * Configured windows, clamped rather than refused.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function configured_windows(): array {
		return array(
			'a sane thirty days' => array( 2592000, 2592000 ),
			'below the floor'    => array( 60, Conversion_Rules::MIN_WINDOW_SECONDS ),
			'zero'               => array( 0, Conversion_Rules::MIN_WINDOW_SECONDS ),
			'negative'           => array( -1, Conversion_Rules::MIN_WINDOW_SECONDS ),
			'above the ceiling'  => array( 99999999, Conversion_Rules::MAX_WINDOW_SECONDS ),
			'not a number'       => array( 'thirty days', Conversion_Rules::DEFAULT_WINDOW_SECONDS ),
			'null'               => array( null, Conversion_Rules::DEFAULT_WINDOW_SECONDS ),
		);
	}

	/**
	 * Asserts one stored window value.
	 *
	 * @param mixed $stored   Configured value.
	 * @param int   $expected Clamped result.
	 */
	#[DataProvider( 'configured_windows' )]
	public function test_a_stored_window_always_produces_a_working_one( mixed $stored, int $expected ): void {
		$this->assertSame( $expected, Conversion_Rules::window_seconds( $stored ) );
	}

	/**
	 * Currency codes.
	 *
	 * @return array<string, array{string, bool}>
	 */
	public static function currencies(): array {
		return array(
			'USD'          => array( 'USD', true ),
			'EUR'          => array( 'EUR', true ),
			'lowercase'    => array( 'usd', false ),
			'four letters' => array( 'USDD', false ),
			'two letters'  => array( 'US', false ),
			'digits'       => array( '840', false ),
			'empty'        => array( '', false ),
		);
	}

	/**
	 * Asserts one currency code.
	 *
	 * @param string $code     Candidate code.
	 * @param bool   $expected Whether it is storable.
	 */
	#[DataProvider( 'currencies' )]
	public function test_currency_is_iso_4217( string $code, bool $expected ): void {
		$this->assertSame( $expected, Conversion_Rules::is_valid_currency( $code ) );
	}

	/**
	 * Zero is a real value: a signup converts and is worth nothing.
	 */
	public function test_a_valueless_conversion_is_valid(): void {
		$this->assertTrue( Conversion_Rules::is_valid_value_micros( 0 ) );
	}

	/**
	 * A negative or absurd value is refused rather than stored into an
	 * unsigned column, where it would wrap into an enormous one.
	 */
	public function test_value_is_bounded_on_both_sides(): void {
		$this->assertFalse( Conversion_Rules::is_valid_value_micros( -1 ) );
		$this->assertTrue( Conversion_Rules::is_valid_value_micros( Conversion_Rules::MAX_VALUE_MICROS ) );
		$this->assertFalse( Conversion_Rules::is_valid_value_micros( Conversion_Rules::MAX_VALUE_MICROS + 1 ) );
	}

	/**
	 * Sources are a closed set, because each one is authorized differently.
	 */
	public function test_sources_are_a_closed_set(): void {
		$this->assertTrue( Conversion_Rules::is_valid_source( Conversion_Rules::SOURCE_BROWSER ) );
		$this->assertTrue( Conversion_Rules::is_valid_source( Conversion_Rules::SOURCE_SERVER ) );
		$this->assertFalse( Conversion_Rules::is_valid_source( 'server' ) );
		$this->assertFalse( Conversion_Rules::is_valid_source( '' ) );
	}
}
