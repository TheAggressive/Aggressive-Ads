import { expect, test } from '@playwright/test';
import { signInToAdmin } from './admin-login';

/**
 * The Conversions screen paints its rows without asking for them.
 *
 * This screen used to fetch both of its lists in `useEffect` and render a
 * `<Spinner />` until they arrived — a full round trip *after* the bundle had
 * downloaded, parsed and mounted, over rows the server had already assembled
 * while rendering the markup around them. It is also the screen that pays for
 * the 530K DataViews bundle first, so it was the slowest one to become useful.
 *
 * `ConversionsScreenTest` proves the payload carries the same rows REST would
 * return. What it cannot prove is the half that made the screen slow: that
 * nothing asks for them again on the way in. Only a browser can say that, and a
 * reinstated `useEffect` would pass every PHP assertion in the suite.
 */

const SCREEN = '/wp-admin/admin.php?page=aggr-conversions';

test( 'the conversions screen renders its lists without fetching them', async ( {
	page,
} ) => {
	await signInToAdmin( page );

	// Counted from before the navigation, so a fetch that races the mount is
	// still caught. Reads only: a write legitimately refetches, and this must
	// not become a test that forbids that.
	const reads: string[] = [];

	page.on( 'request', ( request ) => {
		const url = request.url();

		if (
			'GET' === request.method() &&
			/conversion-(definitions|credentials)/.test( url )
		) {
			reads.push( url );
		}
	} );

	await page.goto( SCREEN );

	const root = page.locator( '#aggr-conversions-root' );
	await expect( root ).toHaveCount( 1 );

	/*
	 * Wait for the screen to be genuinely usable rather than merely mounted.
	 * Asserting the absence of a request before the bundle has run would pass
	 * against a screen that had not started yet — the failure mode this whole
	 * file exists to catch.
	 */
	await expect(
		page.getByRole( 'button', { name: 'New conversion' } )
	).toBeVisible();

	expect(
		reads,
		`The screen asked the server for rows it was already given: ${ reads.join(
			', '
		) }`
	).toHaveLength( 0 );

	// And no spinner survived into the painted screen.
	await expect( root.locator( '.components-spinner' ) ).toHaveCount( 0 );
} );
