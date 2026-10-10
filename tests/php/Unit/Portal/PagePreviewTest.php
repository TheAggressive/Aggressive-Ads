<?php
/**
 * Where each ad is drawn in the Review step's page sketch.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Portal;

use Aggressive\Ads\Portal\Page_Preview;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Covers Page_Preview::arrange().
 *
 * The sketch is believed by the person looking at it, so the rule that decides
 * where a tile goes is pinned size by size, and the inputs it must refuse —
 * a malformed size, a zero dimension — are asserted to leave no tile at all
 * rather than a tile with an invented shape.
 */
final class PagePreviewTest extends TestCase {

	/**
	 * A placement as `creative_slots()` returns it, trimmed to what is read.
	 *
	 * @param string                         $size      The placement's size.
	 * @param array<int, array<string,mixed>> $creatives Creatives on it.
	 * @return array<string, mixed>
	 */
	private static function slot( string $size, array $creatives = array() ): array {
		return array(
			'id'        => 1,
			'name'      => 'Slot',
			'size'      => $size,
			'creatives' => $creatives,
		);
	}

	/**
	 * Where each common size lands.
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public static function size_provider(): array {
		return array(
			'leaderboard'           => array( '728x90', 'column' ),
			'large leaderboard'     => array( '970x90', 'column' ),
			'billboard'             => array( '970x250', 'column' ),
			'mobile banner'         => array( '320x50', 'column' ),
			'exactly twice as wide' => array( '600x300', 'column' ),
			'just under twice'      => array( '599x300', 'rail' ),
			'medium rectangle'      => array( '300x250', 'rail' ),
			'half page'             => array( '300x600', 'rail' ),
			'skyscraper'            => array( '160x600', 'rail' ),
			'square'                => array( '250x250', 'rail' ),
		);
	}

	/**
	 * A size goes to the area its shape says.
	 *
	 * @param string $size Size as stored.
	 * @param string $area `column` or `rail`.
	 * @return void
	 */
	#[DataProvider( 'size_provider' )]
	public function test_size_goes_to_its_area( string $size, string $area ): void {
		$arranged = Page_Preview::arrange( array( self::slot( $size ) ) );
		$other    = 'column' === $area ? 'rail' : 'column';

		self::assertCount( 1, $arranged[ $area ] );
		self::assertSame( array(), $arranged[ $other ], 'A tile must be in one area, not both.' );
	}

	/**
	 * Sizes that are not a real pair of pixels draw nothing.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function refused_provider(): array {
		return array(
			'empty'               => array( '' ),
			'no height'           => array( '728x' ),
			'uppercase x'         => array( '728X90' ),
			'unicode multiply'    => array( '728×90' ),
			'trailing text'       => array( '728x90px' ),
			'negative'            => array( '-728x90' ),
			'zero width'          => array( sprintf( '%dx%d', 0, 90 ) ),
			'zero height'         => array( '728x0' ),
			'not a string at all' => array( null ),
		);
	}

	/**
	 * A malformed size leaves no tile in either area.
	 *
	 * A guessed shape would be believed, so none is drawn.
	 *
	 * @param mixed $size What the placement reported.
	 * @return void
	 */
	#[DataProvider( 'refused_provider' )]
	public function test_malformed_size_draws_nothing( mixed $size ): void {
		$arranged = Page_Preview::arrange(
			array(
				array(
					'size'      => $size,
					'creatives' => array(),
				),
			)
		);

		self::assertSame( array(), $arranged['column'] );
		self::assertSame( array(), $arranged['rail'] );
	}

	/**
	 * A tile carries the placement's own dimensions and first creative's file.
	 *
	 * @return void
	 */
	public function test_tile_carries_dimensions_and_the_first_creative(): void {
		$arranged = Page_Preview::arrange(
			array(
				self::slot(
					'300x250',
					array(
						array( 'preview' => 'https://example.test/first' ),
						array( 'preview' => 'https://example.test/second' ),
					)
				),
			)
		);

		self::assertSame(
			array(
				array(
					'width'   => 300,
					'height'  => 250,
					'preview' => 'https://example.test/first',
				),
			),
			$arranged['rail']
		);
	}

	/**
	 * A placement with no creative has an empty preview, which draws a marker.
	 *
	 * @return void
	 */
	public function test_a_placement_without_a_file_has_an_empty_preview(): void {
		$arranged = Page_Preview::arrange( array( self::slot( '728x90' ) ) );

		self::assertSame( '', $arranged['column'][0]['preview'] );
	}

	/**
	 * Order within an area follows the order of the placements.
	 *
	 * @return void
	 */
	public function test_order_is_kept_within_each_area(): void {
		$arranged = Page_Preview::arrange(
			array(
				self::slot( '300x250' ),
				self::slot( '728x90' ),
				self::slot( '160x600' ),
				self::slot( '970x250' ),
				'not a slot',
			)
		);

		self::assertSame( array( 728, 970 ), array_column( $arranged['column'], 'width' ) );
		self::assertSame( array( 300, 160 ), array_column( $arranged['rail'], 'width' ) );
	}
}
