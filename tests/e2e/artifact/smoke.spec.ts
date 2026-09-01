import { expect, test } from '@playwright/test';

import { signInToAdmin } from '../admin-login';

/**
 * The packaged plugin, installed into a WordPress that has never seen this
 * source tree.
 *
 * Deliberately shallow. Everything about behaviour is proven by the suites that
 * run against the source; the only question here is whether the archive a
 * customer downloads installs, activates, and boots without taking the site
 * down. A deep test would duplicate that coverage and make this lane another
 * thing to keep in step.
 */
test( 'the packaged plugin activates and registers its admin surface', async ( {
	page,
} ) => {
	await signInToAdmin( page, 'password' );

	const plugins = await page.goto( '/wp-admin/plugins.php' );
	expect( plugins?.ok() ).toBe( true );

	// Active, not merely present: an installed-but-inactive plugin would pass a
	// existence check while doing nothing at all.
	await expect(
		page.locator( 'tr[data-slug="aggressive-ads"].active' )
	).toBeVisible();

	// The plugin registers its own top-level menu on boot, so this is the
	// cheapest evidence that it ran rather than merely being switched on.
	const dashboard = await page.goto( '/wp-admin/admin.php?page=aggr' );
	expect( dashboard?.ok() ).toBe( true );
	await expect( page.locator( '#adminmenu' ) ).toContainText( 'Advertising' );
} );

/**
 * The front end still renders with the plugin active.
 *
 * A plugin that fatals on `init` takes the whole site with it, and that failure
 * is invisible from wp-admin if the fatal only happens on a public request.
 */
test( 'the front end still renders', async ( { page } ) => {
	const home = await page.goto( '/' );

	expect( home?.ok() ).toBe( true );
	await expect( page.locator( 'body' ) ).toBeVisible();
} );
