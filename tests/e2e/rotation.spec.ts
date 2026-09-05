import { expect, test } from '@playwright/test';

/**
 * What the ad slot does after paint, watched in a real browser.
 *
 * Everything else proving this runs against jsdom and a mocked `fetch`, which
 * cannot see a store that never hydrates, a directive the Interactivity runtime
 * does not recognise, or an interval the browser throttles. That gap is not
 * hypothetical: the first version of this store used an `async function*`
 * callback, which the runtime silently never completes, and only a browser test
 * caught it.
 *
 * **Real time, not a faked clock.** `page.clock.install()` was the obvious way
 * to skip the wait, and it does not work here: it freezes timers, the view
 * module is deferred so it hydrates *after* the freeze, and the slot then never
 * fills at all. That reads as "rotation is broken" when it means "the test
 * stopped the page". Waiting is slower and true.
 *
 * The fixture rotates every two seconds, so waiting costs little. One page
 * still carries a rotating slot, a static slot on the same placement, and an
 * unsold one, because the three assertions are cheaper together than apart.
 */

const PLACEMENT = 'e2e-browser-placement';
const UNSOLD = '[data-aggr-slot="e2e-empty-placement"]';

/**
 * The fill sequence the client put on the wire.
 *
 * @param url Absolute fill URL.
 */
const sequenceOf = ( url: string ): string | null =>
	new URL( url ).searchParams.get( 'n' );

test( 'a rotating slot refetches while a static one beside it does not', async ( {
	page,
} ) => {
	const fills: string[] = [];

	page.on( 'request', ( request ) => {
		if ( request.url().includes( `/aggr/v1/fill/${ PLACEMENT }` ) ) {
			fills.push( request.url() );
		}
	} );

	await page.goto( '/e2e-rotation/' );

	/*
	 * A sentinel on `window`, which a reload would destroy.
	 *
	 * Counting `framenavigated` was the first attempt and it is not the same
	 * question: that event fires for subframes and for the initial blank
	 * document, so the count was never 1 and the test failed after the rotation
	 * it was meant to be proving had already succeeded.
	 */
	await page.evaluate( () => {
		( window as unknown as Record< string, unknown > ).aggrE2ESentinel = 1;
	} );

	const slots = page.locator( `[data-aggr-slot="${ PLACEMENT }"]` );

	await expect( slots ).toHaveCount( 2 );
	await expect( slots.first().locator( 'img' ) ).toBeVisible();
	await expect( slots.nth( 1 ).locator( 'img' ) ).toBeVisible();

	/*
	 * Two fills, one per slot. Asserted before the wait so the count after it
	 * means something: a page that fetched three times on load would satisfy
	 * the later assertion without anything having rotated.
	 */
	await expect.poll( () => fills.length ).toBe( 2 );

	/*
	 * Both first fills declare sequence zero. A rotation that omitted `n`
	 * would look identical on the wire to these, and the server would file
	 * it as supply — which is what shipped while every PHP test passed a
	 * sequence in by hand.
	 */
	expect( fills.map( sequenceOf ) ).toEqual( [ '0', '0' ] );

	/*
	 * Marks the rendered images, so the assertion after the interval is about
	 * a replaced element rather than a changed `src`. Selection is
	 * weighted-random per request and can legitimately return the same
	 * creative twice, which would make a URL comparison flaky by design.
	 */
	await slots
		.first()
		.locator( 'img' )
		.evaluate( ( node ) => node.setAttribute( 'data-e2e-first', '1' ) );
	await slots
		.nth( 1 )
		.locator( 'img' )
		.evaluate( ( node ) => node.setAttribute( 'data-e2e-static', '1' ) );

	/*
	 * One interval, in real seconds. Polled for `3` rather than "more than 2",
	 * so a slot rotating faster than it should fails here — the static slot
	 * beside it shares the URL, and a runaway timer on either would overshoot.
	 */
	await expect
		.poll( () => fills.length, { timeout: 15_000, intervals: [ 250 ] } )
		.toBe( 3 );

	// The rotating slot's image was replaced; the static slot's was not. Three
	// fills and exactly one lost marker is the whole proof.
	await expect( slots.first().locator( 'img[data-e2e-first]' ) ).toHaveCount(
		0
	);
	await expect(
		slots.nth( 1 ).locator( 'img[data-e2e-static]' )
	).toHaveCount( 1 );

	await expect( slots.first().locator( 'img' ) ).toBeVisible();

	const rotation = fills[ 2 ];

	expect( rotation ).toBeDefined();
	expect( sequenceOf( rotation ) ).toBe( '1' );

	// The sentinel survived, so this document was never replaced: the creative
	// changed in place, which is the whole claim.
	expect(
		await page.evaluate(
			() =>
				( window as unknown as Record< string, unknown > )
					.aggrE2ESentinel
		)
	).toBe( 1 );
} );

test( 'a slot with no advertisement to show renders nothing at all', async ( {
	page,
} ) => {
	await page.goto( '/e2e-rotation/' );

	// The filled slots prove the page and the store are working, so the absence
	// below is a decision rather than a script that never ran.
	await expect(
		page
			.locator( `[data-aggr-slot="${ PLACEMENT }"]` )
			.first()
			.locator( 'img' )
	).toBeVisible();

	/*
	 * The whole wrapper, not just the canvas. Block supports put the border and
	 * the padding on the outer element, so a slot that only hid its inside
	 * would leave a bordered strip of nothing — which is the empty box this
	 * behaviour exists to remove.
	 */
	await expect( page.locator( UNSOLD ) ).toHaveCount( 0 );
} );

test( 'the unsold slot was rendered by the server before the page removed it', async ( {
	page,
	request,
} ) => {
	/*
	 * The negative above is only meaningful beside this one. A slot the server
	 * never rendered would also be absent from the DOM, and that test would
	 * pass while proving nothing about collapsing.
	 */
	const response = await request.get( '/e2e-rotation/' );

	expect( await response.text() ).toContain(
		'data-aggr-slot="e2e-empty-placement"'
	);

	await page.goto( '/e2e-rotation/' );
	await expect( page.locator( UNSOLD ) ).toHaveCount( 0 );
} );
