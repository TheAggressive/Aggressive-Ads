import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The browser evidence P11's contract asks for.
 *
 * Everything else proving this behaviour runs against a fake
 * `IntersectionObserver` and fake timers, which cannot see an observer that is
 * never constructed, a threshold the real API rounds differently, or a beacon
 * the browser refuses to send. This is the only test that watches a real ad
 * enter a real viewport.
 *
 * It asserts the **response**, not the request. A 204 comes back only once
 * `Event_Recorder` has written the row, so this is evidence the event was
 * recorded rather than evidence the client tried — the distinction the contract
 * is explicit about.
 */

const SLOT = '[data-aggr-slot="e2e-browser-placement"]';

/** Beacon calls the page makes, with the event each one claims. */
const collectBeacons = ( page: Page ) => {
	const seen: Array< { event: string; status: number } > = [];

	page.on( 'response', async ( response ) => {
		if ( ! response.url().includes( '/aggr/v1/i' ) ) {
			return;
		}

		const body = response.request().postData() ?? '';
		const event = new URLSearchParams( body ).get( 'event' ) ?? 'served';

		seen.push( { event, status: response.status() } );
	} );

	return seen;
};

test( 'an advertisement scrolled into view is recorded as viewable', async ( {
	page,
} ) => {
	const beacons = collectBeacons( page );

	await page.goto( '/e2e-viewability/' );

	const slot = page.locator( SLOT );
	await expect( slot ).toHaveCount( 1 );

	// The slot starts far below the fold, so nothing has been seen yet. Waiting
	// well past the dwell proves the absence is the observer's doing rather
	// than the page simply being slow.
	await expect
		.poll( () => beacons.filter( ( b ) => 'served' === b.event ).length, {
			timeout: 10000,
		} )
		.toBe( 1 );

	await page.waitForTimeout( 2000 );

	expect(
		beacons.filter( ( b ) => 'viewable' === b.event ),
		'An advertisement below the fold reported itself as seen.'
	).toHaveLength( 0 );

	await slot.scrollIntoViewIfNeeded();

	await expect
		.poll( () => beacons.filter( ( b ) => 'viewable' === b.event ).length, {
			timeout: 10000,
		} )
		.toBe( 1 );

	const viewable = beacons.find( ( b ) => 'viewable' === b.event );

	// 204 is returned only after the row is written; a refusal would be 4xx.
	expect(
		viewable?.status,
		'The server did not accept the view, so nothing was recorded.'
	).toBe( 204 );
} );

test( 'scrolling straight past an advertisement does not record a view', async ( {
	page,
} ) => {
	const beacons = collectBeacons( page );

	await page.goto( '/e2e-viewability/' );

	await expect( page.locator( SLOT ) ).toHaveCount( 1 );

	await expect
		.poll( () => beacons.filter( ( b ) => 'served' === b.event ).length, {
			timeout: 10000,
		} )
		.toBe( 1 );

	/*
	 * Past it and away again inside the dwell window. The standard asks for a
	 * continuous second, and a reader who scrolls through an ad has not seen
	 * it — this is the case the dwell timer exists for, and the one a test
	 * against fake timers cannot really exercise.
	 */
	await page.locator( SLOT ).scrollIntoViewIfNeeded();
	await page.waitForTimeout( 200 );
	await page.evaluate( () => window.scrollTo( 0, 0 ) );

	await page.waitForTimeout( 2500 );

	expect(
		beacons.filter( ( b ) => 'viewable' === b.event ),
		'An advertisement scrolled past inside the dwell window counted as seen.'
	).toHaveLength( 0 );
} );
