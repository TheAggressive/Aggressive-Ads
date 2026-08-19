<?php
/**
 * When the self-updater is allowed to run.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Update;

use Aggressive\Ads\Update\Plugin_Updates;
use PHPUnit\Framework\TestCase;

/**
 * A plugin update clears the destination directory and unpacks the release ZIP
 * over it, so enabling this in the wrong place deletes a working copy. The
 * policy is a pure function precisely so every combination can be stated here.
 */
final class PluginUpdatesPolicyTest extends TestCase {

	/**
	 * A distributed copy on a real site updates normally.
	 *
	 * @return void
	 */
	public function test_production_without_a_checkout_may_update(): void {
		$this->assertTrue( Plugin_Updates::should_enable( false, 'production' ) );
	}

	/**
	 * Staging is a real deployment and keeps the updater.
	 *
	 * Named explicitly because the temptation is to treat everything that is
	 * not production as development, which would silently stop staging from
	 * ever testing an update.
	 *
	 * @return void
	 */
	public function test_staging_may_update(): void {
		$this->assertTrue( Plugin_Updates::should_enable( false, 'staging' ) );
	}

	/**
	 * A checkout never updates, whatever the environment claims.
	 *
	 * The checkout signal has to win: a development install left on the default
	 * `production` environment type is the common case, and it is exactly the
	 * one that loses work.
	 *
	 * @return void
	 */
	public function test_a_checkout_never_updates(): void {
		$this->assertFalse( Plugin_Updates::should_enable( true, 'production' ) );
		$this->assertFalse( Plugin_Updates::should_enable( true, 'staging' ) );
		$this->assertFalse( Plugin_Updates::should_enable( true, 'local' ) );
		$this->assertFalse( Plugin_Updates::should_enable( true, 'development' ) );
	}

	/**
	 * A development environment never updates, even without a checkout.
	 *
	 * Covers the deployed-from-artifact development box, which has no .git and
	 * would otherwise be indistinguishable from production.
	 *
	 * @return void
	 */
	public function test_development_environments_never_update(): void {
		$this->assertFalse( Plugin_Updates::should_enable( false, 'local' ) );
		$this->assertFalse( Plugin_Updates::should_enable( false, 'development' ) );
	}

	/**
	 * An unrecognized environment is treated as a real one.
	 *
	 * `wp_get_environment_type()` only ever returns one of four values, but a
	 * filter can return anything. Refusing to update on an unknown string would
	 * silently disable updates for a site that renamed its environments.
	 *
	 * @return void
	 */
	public function test_an_unknown_environment_still_updates(): void {
		$this->assertTrue( Plugin_Updates::should_enable( false, 'qa' ) );
		$this->assertTrue( Plugin_Updates::should_enable( false, '' ) );
	}
}
