<?php
/**
 * Catching a redirect that production code follows with exit().
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests;

/**
 * Stops a redirecting code path without stopping the test process.
 *
 * Portal\Router::gate() ends `wp_safe_redirect( … ); exit;`, which is correct
 * for production and fatal for a test run: exit() kills PHPUnit itself. The
 * process then dies mid-suite, prints no summary, and — because the runner sees
 * a clean exit — reports success.
 *
 * That is not hypothetical. It is what this trait was written to stop: ten test
 * classes, including the whole REST suite and the upgrader, silently stopped
 * running while `pnpm test:php:integration` reported a pass.
 *
 * Cancelling the redirect is not enough. Returning false from the `wp_redirect`
 * filter makes wp_redirect() return false, and the very next statement is still
 * exit(). The filter has to raise instead, so control leaves the function before
 * exit() is reached.
 */
trait Redirect_Trap {

	/**
	 * Runs a callable that is expected to redirect, and reports where to.
	 *
	 * Returns every location attempted. That is a list rather than one string
	 * because "redirected exactly once" is usually the assertion worth making —
	 * an empty list means the code did not redirect at all, which is a different
	 * failure from redirecting somewhere wrong.
	 *
	 * @param callable $run Code under test.
	 * @return list<string>
	 */
	protected function trap_redirects( callable $run ): array {
		$locations = array();

		$capture = static function ( string $location ) use ( &$locations ): never {
			$locations[] = $location;

			$signal           = new Redirect_Signal( 'Redirect trapped by the test suite.' );
			$signal->location = $location;

			throw $signal;
		};

		add_filter( 'wp_redirect', $capture );

		try {
			$run();
		} catch ( Redirect_Signal ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- The throw is the point: it replaces exit(), and the location is already recorded.
		} finally {
			remove_filter( 'wp_redirect', $capture );
		}

		return $locations;
	}
}
