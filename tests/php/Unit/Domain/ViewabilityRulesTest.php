<?php
/**
 * The definition of "seen", asserted at its edges.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Viewability_Rules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A threshold is only ever wrong on its boundary, so the boundary is where this
 * asserts. Round numbers either side prove the comparison runs; 999, 1000 and
 * 1001 prove it runs the right way round.
 */
final class ViewabilityRulesTest extends TestCase {

	/**
	 * Observations against the default 50% / 1000ms threshold.
	 *
	 * @return array<string, array{float, int, bool}>
	 */
	public static function observations(): array {
		return array(
			'half the pixels for a full second' => array( 50.0, 1000, true ),
			'fully visible, long enough'        => array( 100.0, 5000, true ),
			'one percent under'                 => array( 49.9, 1000, false ),
			'one millisecond under'             => array( 50.0, 999, false ),
			'one millisecond over'              => array( 50.0, 1001, true ),
			'fully visible, too briefly'        => array( 100.0, 999, false ),
			'a sliver for a long time'          => array( 5.0, 60000, false ),
			'never visible at all'              => array( 0.0, 5000, false ),
			'visible, no dwell'                 => array( 100.0, 0, false ),
			'a negative ratio'                  => array( -1.0, 5000, false ),
			'a negative dwell'                  => array( 100.0, -1, false ),
		);
	}

	#[DataProvider( 'observations' )]
	public function test_the_default_threshold( float $ratio, int $dwell, bool $viewable ): void {
		$this->assertSame( $viewable, Viewability_Rules::is_viewable( $ratio, $dwell ) );
	}

	/**
	 * Both halves are required, not either.
	 *
	 * Stated separately because an `||` would satisfy most of the provider
	 * above: everything fully visible would pass whatever the dwell, and the
	 * two failing rows would look like ordinary edge cases.
	 */
	public function test_both_halves_are_required(): void {
		$this->assertFalse(
			Viewability_Rules::is_viewable( 100.0, 10 ),
			'A fast scroll past a fully visible ad is not a view.'
		);
		$this->assertFalse(
			Viewability_Rules::is_viewable( 10.0, 10000 ),
			'A sliver on screen for ten seconds is not a view.'
		);
	}

	/** A configured threshold is honoured rather than ignored. */
	public function test_a_configured_threshold_replaces_the_default(): void {
		$this->assertTrue( Viewability_Rules::is_viewable( 30.0, 2000, 30, 2000 ) );
		$this->assertFalse( Viewability_Rules::is_viewable( 30.0, 2000, 31, 2000 ) );
		$this->assertFalse( Viewability_Rules::is_viewable( 30.0, 2000, 30, 2001 ) );
	}

	/**
	 * Configured percentages, and what they clamp to.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function percentages(): array {
		return array(
			'the default'      => array( 50, 50 ),
			'the minimum'      => array( 1, 1 ),
			'the maximum'      => array( 100, 100 ),
			'zero'             => array( 0, 1 ),
			'negative'         => array( -20, 1 ),
			'over a hundred'   => array( 150, 100 ),
			'a numeric string' => array( '75', 75 ),
			'not a number'     => array( 'half', 50 ),
			'null'             => array( null, 50 ),
			'an array'         => array( array( 50 ), 50 ),
		);
	}

	#[DataProvider( 'percentages' )]
	public function test_percentages_are_clamped( mixed $value, int $expected ): void {
		$this->assertSame( $expected, Viewability_Rules::ratio_percent( $value ) );
	}

	/**
	 * Configured dwell times, and what they clamp to.
	 *
	 * @return array<string, array{mixed, int}>
	 */
	public static function dwells(): array {
		return array(
			'the default'   => array( 1000, 1000 ),
			'the minimum'   => array( 100, 100 ),
			'the maximum'   => array( 30000, 30000 ),
			'zero'          => array( 0, 100 ),
			'negative'      => array( -500, 100 ),
			'absurdly long' => array( 600000, 30000 ),
			'not a number'  => array( 'a second', 1000 ),
		);
	}

	#[DataProvider( 'dwells' )]
	public function test_dwell_times_are_clamped( mixed $value, int $expected ): void {
		$this->assertSame( $expected, Viewability_Rules::dwell_ms( $value ) );
	}

	/**
	 * A stored value outside the range still measures something.
	 *
	 * Clamped rather than refused, because these arrive from settings that were
	 * already saved. Treating an out-of-range number as "no threshold" would
	 * turn a typo into viewability silently reading zero forever, which looks
	 * exactly like nobody seeing any ads.
	 */
	public function test_an_impossible_setting_still_yields_a_working_threshold(): void {
		$client = Viewability_Rules::for_client( 9999, -1 );

		$this->assertSame( 1.0, $client['ratio'] );
		$this->assertSame( Viewability_Rules::MIN_DWELL_MS, $client['dwell_ms'] );
	}

	/**
	 * The client is handed the fraction `IntersectionObserver` takes.
	 *
	 * Converted server-side so the browser never rounds a percentage its own
	 * way — a threshold that is 0.5 here and 0.49999 there is a bug nobody
	 * would find from the reported numbers.
	 */
	public function test_the_client_receives_a_ratio_not_a_percentage(): void {
		$this->assertSame(
			array(
				'ratio'    => 0.5,
				'dwell_ms' => 1000,
			),
			Viewability_Rules::for_client( 50, 1000 )
		);

		$this->assertSame( 0.3, Viewability_Rules::for_client( 30, 1000 )['ratio'] );
	}
}
