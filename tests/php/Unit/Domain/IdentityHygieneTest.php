<?php
/**
 * Runtime code must not grow retired identifiers.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * There is no LAAO compatibility layer. A new laao_ads_ string in inc/ is a
 * regression, not an alias. See README.md.
 */
final class IdentityHygieneTest extends TestCase {

	/**
	 * Patterns that belong to the retired identity.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = array(
		'laao_ads_',
		'laao_',
		'lap_draft',
		'LAAO_Advertiser_Portal',
		'LAAO_ADS_',
		'--laao-ads-',
		'laao-ads-',
		'@laao-ads/',
		'laao-advertiser-portal',
		'laao-ads-private',
	);

	/**
	 * Runtime PHP under inc/ does not contain retired identifiers.
	 *
	 * @return void
	 */
	public function test_inc_does_not_grow_retired_identifiers(): void {
		$root  = dirname( __DIR__, 4 );
		$inc   = $root . '/inc';
		$found = array();

		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $inc, FilesystemIterator::SKIP_DOTS )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file instanceof SplFileInfo || 'php' !== $file->getExtension() ) {
				continue;
			}

			$relative = ltrim( str_replace( $root, '', $file->getPathname() ), '/' );
			$source   = file_get_contents( $file->getPathname() );

			if ( false === $source ) {
				continue;
			}

			foreach ( self::FORBIDDEN as $needle ) {
				if ( str_contains( $source, $needle ) ) {
					$found[] = "{$relative}: {$needle}";
				}
			}
		}

		$this->assertSame( array(), $found, "Retired identifiers leaked into runtime code:\n" . implode( "\n", $found ) );
	}
}
