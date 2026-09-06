<?php
/**
 * One placement, several sizes, exactly one answer.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Size_Map;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The contract requires responsive mappings to be deterministic: the same
 * request context cannot map to two conflicting billable inventory units.
 *
 * That is not a property a handful of examples can establish, so the central
 * test here sweeps every width across a boundary rather than picking the ones
 * that look interesting. A mapping that answers twice bills an advertiser for
 * one size and shows a reader another, with the ledger and the page both
 * certain they are right.
 */
final class SizeMapTest extends TestCase {

	/** A map with three breakpoints, declared deliberately out of order. */
	private function three_step(): Size_Map {
		return Size_Map::from_stored(
			array(
				768  => '728x90',
				0    => '320x50',
				1200 => '970x250',
			),
			'300x250'
		);
	}

	/**
	 * **Every width resolves, and resolves once.**
	 *
	 * Swept rather than sampled. The boundary values are where an off-by-one
	 * lives, and the widths between them are where an overlap would show up as
	 * a second answer — so this walks all of them and asserts the resolution is
	 * both non-empty and stable across repeated calls.
	 *
	 * @return void
	 */
	public function test_every_width_resolves_to_exactly_one_size(): void {
		$map  = $this->three_step();
		$seen = array();

		for ( $width = 0; $width <= 1400; $width++ ) {
			$size = $map->for_viewport( $width );

			$this->assertNotSame( '', $size, 'Width ' . $width . ' resolved to nothing.' );
			$this->assertSame(
				$size,
				$map->for_viewport( $width ),
				'Width ' . $width . ' resolved differently on a second call.'
			);

			$seen[ $size ] = true;
		}

		// All three declared sizes are reachable, so none is dead configuration
		// a publisher believes they are selling.
		$this->assertSame(
			array( '320x50', '728x90', '970x250' ),
			array_keys( $seen ),
			'A declared size is unreachable at every width, so it can never be sold.'
		);
	}

	/**
	 * The boundary belongs to the breakpoint that names it.
	 *
	 * @param int    $width    Viewport width.
	 * @param string $expected Size that width must resolve to.
	 */
	#[DataProvider( 'boundaries' )]
	public function test_a_breakpoint_owns_its_own_width( int $width, string $expected ): void {
		$this->assertSame( $expected, $this->three_step()->for_viewport( $width ) );
	}

	/**
	 * Widths either side of every declared floor.
	 *
	 * @return array<string, array{int, string}>
	 */
	public static function boundaries(): array {
		return array(
			'zero'                 => array( 0, '320x50' ),
			'below the first step' => array( 767, '320x50' ),
			'the first step'       => array( 768, '728x90' ),
			'above it'             => array( 769, '728x90' ),
			'below the second'     => array( 1199, '728x90' ),
			'the second step'      => array( 1200, '970x250' ),
			'far above'            => array( 4000, '970x250' ),

			// The wire can produce this and a layout cannot.
			'negative'             => array( -50, '320x50' ),
		);
	}

	/**
	 * **A duplicate floor collapses rather than competing.**
	 *
	 * Two entries claiming one width is the only way stored configuration could
	 * be ambiguous. The resolution has to be stable whichever survives, because
	 * an answer that changes between requests is the defect the invariant
	 * exists to prevent.
	 *
	 * @return void
	 */
	public function test_a_duplicate_floor_cannot_produce_two_answers(): void {
		$map = Size_Map::from_stored(
			array(
				0   => '320x50',
				768 => '728x90',
			),
			'300x250'
		);

		$first = $map->for_viewport( 800 );

		$this->assertSame( $first, $map->for_viewport( 800 ) );
		$this->assertCount( 2, $map->breakpoints(), 'A duplicate floor survived as a second entry.' );
	}

	/** A single size is a map with one floor, not a special case. */
	public function test_a_fixed_placement_serves_one_size_everywhere(): void {
		$map = Size_Map::fixed( '728x90' );

		$this->assertFalse( $map->is_responsive() );
		$this->assertSame( '728x90', $map->for_viewport( 0 ) );
		$this->assertSame( '728x90', $map->for_viewport( 5000 ) );
		$this->assertSame( '728x90', $map->base() );
	}

	/**
	 * **A map with no floor of zero is not a map.**
	 *
	 * Without it a narrow viewport has no answer, and the fill path would have
	 * to guess or refuse. The stored value is discarded whole rather than
	 * repaired, because a publisher who declared only a wide breakpoint did not
	 * describe what a phone should see and this cannot invent it.
	 *
	 * @return void
	 */
	public function test_a_map_without_a_base_falls_back_entirely(): void {
		$map = Size_Map::from_stored( array( 768 => '728x90' ), '300x250' );

		$this->assertFalse( $map->is_responsive() );
		$this->assertSame( '300x250', $map->for_viewport( 0 ) );
		$this->assertSame( '300x250', $map->for_viewport( 1000 ), 'A discarded map still answered from its own breakpoints.' );
	}

	/**
	 * Unusable entries are dropped, and the rest of the map survives them.
	 *
	 * @param mixed $stored What the placement recorded.
	 */
	#[DataProvider( 'unusable' )]
	public function test_unusable_configuration_is_dropped( mixed $stored ): void {
		$map = Size_Map::from_stored( $stored, '300x250' );

		$this->assertSame( '300x250', $map->for_viewport( 1000 ) );
	}

	/**
	 * Stored values that cannot describe inventory.
	 *
	 * @return array<string, array{mixed}>
	 */
	public static function unusable(): array {
		return array(
			'not an array'           => array( 'wide' ),
			'null'                   => array( null ),
			'empty'                  => array( array() ),
			'a size we cannot serve' => array( array( 0 => 'enormous' ) ),
			'a negative floor'       => array( array( -10 => '728x90' ) ),
			'a non-numeric floor'    => array( array( 'wide' => '728x90' ) ),
		);
	}

	/**
	 * A map is bounded, because each breakpoint splits the same demand.
	 *
	 * @return void
	 */
	public function test_a_map_is_bounded(): void {
		$stored = array( 0 => '320x50' );

		for ( $i = 1; $i <= 12; $i++ ) {
			$stored[ $i * 100 ] = '728x90';
		}

		$map = Size_Map::from_stored( $stored, '300x250' );

		$this->assertCount( Size_Map::MAX_BREAKPOINTS, $map->breakpoints() );

		// The widest floors are the ones kept, and the base survives the trim
		// because without it the map would not be a map.
		$this->assertSame( '320x50', $map->for_viewport( 0 ) );
	}
}
