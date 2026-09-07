import { expect, test } from '@playwright/test';
import type { Page } from '@playwright/test';

/**
 * The browser evidence P12's contract asks for, for the click-through carrier.
 *
 * `FillRoutesTest` already asserts both halves of this against the real hop's
 * `Location` header, which is stronger than a unit test of a URL builder and
 * still not the thing being claimed. Between that header and an advertiser's
 * page sit three steps no PHP test executes: the anchor `fill.js` builds from
 * the payload, the browser's own navigation, and the redirect it follows.
 * A carrier that worked in every one of those assertions and produced a
 * `javascript:` anchor, an unfollowed redirect, or an `aggr_ct` mangled by
 * encoding would look perfectly healthy on the server.
 *
 * The destination is a real page on this site, so the assertion is what the
 * address bar actually says after a real click.
 *
 * **The click lands in a new tab, and that is the behaviour under test too.**
 * These specs used to wait on the current page's URL, which is why they failed
 * the moment advertisements stopped keeping the reader — `waitForURL` sat
 * there until the timeout because the page that navigated was a different one.
 * Waiting on the popup asserts both things at once: the reader is not carried
 * off the publisher's page, and the carrier still works across the hop.
 */

const TOKEN = 'aggr_ct';

/** The rendered advertisement inside one slot, once the fill has drawn it. */
const adIn = ( page: Page, slot: string ) =>
	page.locator( `[data-aggr-slot="${ slot }"] a` ).first();

/** The `aggr_ct` values present in a URL, in order. */
const tokensIn = ( url: string ): string[] =>
	new URL( url ).searchParams.getAll( TOKEN );

/**
 * Clicks an advertisement and returns the tab it opened.
 *
 * The publisher's page has to survive the click, so the popup is awaited
 * rather than the current page's navigation — and the original URL is
 * asserted unchanged, which is the half a `waitForURL` on the popup alone
 * would not cover.
 */
async function clickIntoNewTab( page: Page, ad: ReturnType< typeof adIn > ) {
	const before = page.url();
	const opened = page.context().waitForEvent( 'page' );

	await ad.click();

	const landing = await opened;
	await landing.waitForURL( /e2e-click-landing/ );

	expect(
		page.url(),
		"The reader was carried off the publisher's page. An advertisement opens a new tab so a click costs the publisher nothing."
	).toBe( before );

	return landing;
}

test( 'clicking an advertisement lands on the destination carrying one click token', async ( {
	page,
} ) => {
	await page.goto( '/e2e-carrier/' );

	const ad = adIn( page, 'e2e-browser-placement' );
	await expect( ad ).toBeVisible();

	// The href is the hop, not the destination: the token is minted per fill and
	// the redirect is what appends it. Asserting this first means a failure
	// below is about the hop rather than about the ad never rendering.
	const href = await ad.getAttribute( 'href' );
	expect(
		href,
		'The advertisement is not linked to the click hop.'
	).toContain( '/ads/c' );

	const landing = await clickIntoNewTab( page, ad );
	const landed = tokensIn( landing.url() );

	expect(
		landed,
		`A real click landed on ${ landing.url() }, which carries no click token — nothing on the advertiser's page could report a conversion.`
	).toHaveLength( 1 );

	expect( landed[ 0 ] ).not.toBe( '' );

	// And the page really is the advertiser's, not the hop showing an error.
	await expect( landing.getByText( 'The advertiser’s page.' ) ).toBeVisible();
} );

test( 'a destination that already carries a click token is not given two', async ( {
	page,
} ) => {
	await page.goto( '/e2e-carrier/' );

	const ad = adIn( page, 'e2e-carrier-placement' );
	await expect( ad ).toBeVisible();

	const landing = await clickIntoNewTab( page, ad );
	const landed = tokensIn( landing.url() );

	/*
	 * The failure this guards against is quiet rather than loud. Two values on
	 * one parameter is not an error anywhere: PHP's parser hands the page the
	 * last one, so an appending carrier would look like a working integration
	 * right up until a conversion was attributed to the wrong fill — or, with
	 * the stale value last, to a token this site never minted.
	 */
	expect(
		landed,
		`The destination arrived carrying ${
			landed.length
		} click tokens (${ landed.join(
			', '
		) }). The carrier appended instead of replacing.`
	).toHaveLength( 1 );

	expect(
		landed[ 0 ],
		'The seeded placeholder survived the hop, so the destination was passed through rather than carried.'
	).not.toBe( 'stale' );
} );
