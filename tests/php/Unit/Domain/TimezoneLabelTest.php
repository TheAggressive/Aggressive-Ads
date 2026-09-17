<?php
/**
 * A timezone's short name.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Timezone_Label;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every shape PHP's `T` format produces becomes something a reader knows.
 */
final class TimezoneLabelTest extends TestCase {

	/**
	 * `T` output and what is shown.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function abbreviations(): array {
		return array(
			'common name'      => array( 'PDT', 'PDT' ),
			'utc'              => array( 'UTC', 'UTC' ),
			'offset, colon'    => array( '+05:30', 'UTC+05:30' ),
			'offset, no colon' => array( '+0530', 'UTC+05:30' ),
			'gmt prefix'       => array( 'GMT+0530', 'UTC+05:30' ),
			'whole hours'      => array( '-0300', 'UTC-03' ),
			'hours only'       => array( '-03', 'UTC-03' ),
			'gmt zero'         => array( 'GMT+0000', 'UTC' ),
			'zero offset'      => array( '+00:00', 'UTC' ),
			'zero hours'       => array( '+00', 'UTC' ),
			'empty'            => array( '', 'UTC' ),
		);
	}

	#[DataProvider( 'abbreviations' )]
	public function test_it_reads_as_a_zone( string $abbreviation, string $expected ): void {
		$this->assertSame( $expected, Timezone_Label::abbreviation( $abbreviation ) );
	}

	/**
	 * The name follows the date: one zone, two names a season apart.
	 */
	public function test_the_name_belongs_to_the_date_not_the_zone(): void {
		$zone = new \DateTimeZone( 'America/Los_Angeles' );

		$this->assertSame( 'PDT', Timezone_Label::abbreviation( ( new \DateTimeImmutable( '2026-09-17 00:00', $zone ) )->format( 'T' ) ) );
		$this->assertSame( 'PST', Timezone_Label::abbreviation( ( new \DateTimeImmutable( '2026-12-17 00:00', $zone ) )->format( 'T' ) ) );
		$this->assertSame( 'UTC-03', Timezone_Label::abbreviation( ( new \DateTimeImmutable( '2026-12-17 00:00', new \DateTimeZone( 'America/Argentina/Buenos_Aires' ) ) )->format( 'T' ) ) );
	}
}
