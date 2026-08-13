<?php
/**
 * Runtime code must not grow new LAAO identifiers.
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
 * Identity_Maps is the only place in inc/ that may still spell the old names.
 * A new laao_ads_ constant anywhere else is a Phase 0 regression.
 */
final class IdentityHygieneTest extends TestCase {

	/**
	 * Files that must mention the previous identifiers, because they implement
	 * the rewrite or a one-release alias.
	 *
	 * @var array<int, string>
	 */
	private const ALLOWLIST = array(
		'inc/Domain/class-identity-maps.php',
		'inc/REST/class-creative-file-controller.php',
		'inc/Storage/class-private-storage.php',
	);

	/**
	 * Patterns that belong to the retired identity.
	 *
	 * @var array<int, string>
	 */
	private const FORBIDDEN = array(
		'laao_ads_',
		'lap_draft',
		'LAAO_Advertiser_Portal',
		'LAAO_ADS_',
		'--laao-ads-',
		'laao-ads-',
		'@laao-ads/',
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

			if ( in_array( $relative, self::ALLOWLIST, true ) ) {
				continue;
			}

			$source = file_get_contents( $file->getPathname() );

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
