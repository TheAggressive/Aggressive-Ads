import { expect, test } from '@playwright/test';

/**
 * Page-level coordination, exercised the way a visitor exercises it.
 *
 * `Page_Decision_Coordinator` was built, tested and reachable at
 * `POST /aggr/v1/decisions` while every real page filled its slots one at a
 * time through `data-aggr-fill`. Competitive separation, roadblocks and
 * category exclusivity were therefore fully implemented and never once ran for
 * a visitor — the tests passed because they called `for_slots()` in PHP, which
 * is the one caller production did not have.
 *
 * These specs are the ones that could not pass over that gap: they watch the
 * network a browser actually produces, and they read a page whose rendering
 * differs depending on which route decided it.
 */

const SLOT_A = '[data-aggr-slot="e2e-coord-a"]';
const SLOT_B = '[data-aggr-slot="e2e-coord-b"]';

test( 'a multi-slot page is decided once, as a page', async ( { page } ) => {
	const batched: string[] = [];
	const perSlot: string[] = [];

	page.on( 'request', ( request ) => {
		const url = request.url();

		if ( url.includes( 'aggr/v1/decisions' ) ) {
			batched.push( request.method() );
		}

		if ( /aggr\/v1\/fill/.test( url ) ) {
			perSlot.push( url );
		}
	} );

	await page.goto( '/e2e-page-coordination/' );
	await expect( page.locator( `${ SLOT_A } img` ) ).toBeVisible();

	/*
	 * One request for the whole page, not one per slot.
	 *
	 * This is the assertion that fails if the client quietly reverts to
	 * independent per-slot decisions: the page still renders, the ads still
	 * appear, and nothing else in the suite would notice.
	 */
	expect( batched ).toEqual( [ 'POST' ] );
	expect( perSlot.filter( ( url ) => /e2e-coord-[ab]/.test( url ) ) ).toEqual(
		[]
	);
} );

test( 'competitive separation keeps a rival off the same page', async ( {
	page,
} ) => {
	await page.goto( '/e2e-page-coordination/' );

	// The first slot is sold, so the page decision definitely ran to an answer.
	await expect( page.locator( `${ SLOT_A } img` ) ).toBeVisible();

	/*
	 * The second slot's only candidate names slot A's organization as a
	 * competitor. Decided as a page it is excluded; decided on its own it has
	 * no idea slot A exists and fills. So an empty box here is the rule
	 * running, and an advertisement here is the rule having been skipped.
	 */
	await expect( page.locator( `${ SLOT_B } img` ) ).toHaveCount( 0 );
} );

test( 'the page decision carries the viewport and the page', async ( {
	page,
} ) => {
	let body: Record< string, unknown > | null = null;

	page.on( 'request', ( request ) => {
		if ( request.url().includes( 'aggr/v1/decisions' ) ) {
			body = JSON.parse( request.postData() ?? '{}' );
		}
	} );

	await page.goto( '/e2e-page-coordination/' );
	await expect( page.locator( `${ SLOT_A } img` ) ).toBeVisible();

	expect( body ).not.toBeNull();

	const sent = body as unknown as { slots: string[]; w: number; p?: number };

	expect( sent.slots.sort() ).toEqual( [ 'e2e-coord-a', 'e2e-coord-b' ] );

	/*
	 * Responsive sizing and page targeting both depend on these reaching the
	 * batch route. The per-slot route got them from the URL the server baked;
	 * a POST has no URL to carry them, so the client lifts them off a slot and
	 * sends them — and this is what proves it does.
	 */
	expect( sent.w ).toBeGreaterThan( 0 );
	expect( sent.p ).toBeGreaterThan( 0 );
} );
