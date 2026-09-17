<?php
/**
 * Naming a site timezone.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Timezone_Label;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every form `wp_timezone_string()` returns becomes something a reader knows.
 */
final class TimezoneLabelTest extends TestCase {

	/**
	 * Identifiers and their names.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function zones(): array {
		return array(
			'city'             => array( 'America/Los_Angeles', 'Los Angeles' ),
			'two-level region' => array( 'America/Argentina/Buenos_Aires', 'Buenos Aires' ),
			'single segment'   => array( 'Japan', 'Japan' ),
			'utc'              => array( 'UTC', 'UTC' ),
			'etc utc'          => array( 'Etc/UTC', 'UTC' ),
			'etc offset'       => array( 'Etc/GMT+5', 'GMT+5' ),
			'manual offset'    => array( '+05:30', 'UTC+05:30' ),
			'negative offset'  => array( '-03:00', 'UTC-03:00' ),
			'zero offset'      => array( '+00:00', 'UTC' ),
			'empty'            => array( '', 'UTC' ),
			'trailing slash'   => array( 'America/', 'UTC' ),
		);
	}

	#[DataProvider( 'zones' )]
	public function test_it_names_the_zone( string $identifier, string $expected ): void {
		$this->assertSame( $expected, Timezone_Label::name( $identifier ) );
	}
}
