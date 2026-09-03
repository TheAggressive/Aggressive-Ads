import { expect, test } from '@playwright/test';

/**
 * The two slot behaviours that only a real browser can settle.
 *
 * Both are about what happens *after* the server has rendered the box: whether
 * a store hydrates a wrapper the block editor did not build, and whether an
 * unsold slot leaves or stays. Every PHP test here can prove the markup went
 * out; none can prove the Interactivity runtime recognised it.
 *
 * That gap is not hypothetical. The shortcode and `aggr_placement()` share a
 * wrapper that named its attributes by hand, so `data-wp-interactive`,
 * `data-wp-init` and `data-wp-context` were built for every slot and dropped
 * for those two. Neither had ever filled an advertisement, and the result
 * renders exactly like a slot with no inventory — a state this plugin has on
 * purpose — so nothing about it looked wrong on the page.
 */

const SOLD = '[data-aggr-slot="e2e-browser-placement"]';
const UNSOLD = '[data-aggr-slot="e2e-empty-placement"]';

test( 'a shortcode slot fills, the way a block slot does', async ( {
	page,
} ) => {
	await page.goto( '/e2e-slot-surfaces/' );

	const slot = page.locator( SOLD );

	/*
	 * One slot, and it is the shortcode's. The block wrapper carries a
	 * `wp-block-aggr-ad-slot` class that this one cannot have, so asserting its
	 * absence is what stops this test passing over a block slot somebody adds
	 * to the fixture later.
	 */
	await expect( slot ).toHaveCount( 1 );
	await expect( slot ).not.toHaveClass( /wp-block-aggr-ad-slot/ );

	// The directives the server has to emit for any of this to happen.
	await expect( slot ).toHaveAttribute(
		'data-wp-interactive',
		'aggr/ad-slot'
	);
	await expect( slot ).toHaveAttribute( 'data-wp-init', 'callbacks.fill' );

	// And the ad that proves the store acted on them.
	await expect( slot.locator( 'img' ) ).toBeVisible();
	await expect( slot.locator( 'a[href]' ) ).toHaveCount( 1 );
} );

test( 'an unsold slot keeps its space only when it asked to', async ( {
	page,
} ) => {
	await page.goto( '/e2e-slot-surfaces/' );

	/*
	 * The filled shortcode slot first, so everything below is a decision the
	 * store made rather than a script that never ran. Without it, a page whose
	 * JavaScript failed outright would leave both unsold slots standing and
	 * satisfy half of what follows.
	 */
	await expect( page.locator( SOLD ).locator( 'img' ) ).toBeVisible();

	/*
	 * Two identical unsold slots on the same placement went out — one told to
	 * keep its space, one not — so exactly one may remain. A count is the whole
	 * assertion: 0 means the attribute did nothing, 2 means it did something to
	 * every slot, and only 1 is the behaviour.
	 */
	const kept = page.locator( UNSOLD );

	await expect( kept ).toHaveCount( 1 );
	await expect( kept ).toHaveClass( /aggr-slot--empty/ );

	/*
	 * Held open at the placement's own shape, which is the point of keeping it:
	 * a reserved box the wrong shape reserves nothing.
	 *
	 * The ratio rather than 90 pixels. The canvas is `width: 728px` under a
	 * `max-width: 100%`, so a theme whose content column is narrower scales it
	 * down proportionally — a pixel assertion here would pass on this fixture
	 * and fail the first time somebody changed the theme, for a reason that has
	 * nothing to do with collapsing.
	 */
	const canvas = await kept.locator( '.aggr-slot__canvas' ).boundingBox();

	expect( canvas?.height ?? 0 ).toBeGreaterThan( 0 );
	expect( ( canvas?.width ?? 0 ) / ( canvas?.height ?? 1 ) ).toBeCloseTo(
		728 / 90,
		1
	);

	// And it is space, not a silently blank advertisement.
	await expect( kept.locator( 'img' ) ).toHaveCount( 0 );
} );

test( 'the server rendered both unsold slots before the page removed one', async ( {
	page,
	request,
} ) => {
	/*
	 * The count above is only meaningful beside this. A slot the server never
	 * rendered is also absent from the DOM, and "exactly one remains" would
	 * pass over a fixture that only ever had one.
	 */
	const html = await ( await request.get( '/e2e-slot-surfaces/' ) ).text();

	expect(
		html.split( 'data-aggr-slot="e2e-empty-placement"' ).length - 1
	).toBe( 2 );

	// Neither is marked before the client has decided anything.
	expect( html ).not.toContain( 'aggr-slot--empty' );

	await page.goto( '/e2e-slot-surfaces/' );
	await expect( page.locator( UNSOLD ) ).toHaveCount( 1 );
} );

/**
 * The one audience whose experience nothing else here can check.
 *
 * `open-work.md` recorded for a long time that a visitor without JavaScript
 * sees the reserved box whatever happens, on the reasoning that only a
 * render-time decision could avoid it and there could not be one. That
 * conflated two questions. Whether a *paid* ad will fill a slot does need a
 * per-request candidate query, and a cached page would bake the answer in.
 * Whether a *no-JS visitor* sees anything depends only on the house policy and
 * whether a house creative exists — which the server already resolves, from
 * placement configuration, to decide whether to emit the noscript house.
 *
 * Playwright can turn scripting off for a context, so this is assertable
 * rather than arguable.
 */
test.describe( 'without JavaScript', () => {
	test.use( { javaScriptEnabled: false } );

	test( 'an unfillable slot leaves no empty box behind', async ( {
		page,
	} ) => {
		await page.goto( '/e2e-slot-surfaces/' );

		/*
		 * Rendered by the server — the markup is in the document either way, which
		 * is the whole reason the box used to be visible — and laid out at zero
		 * height, because the rule inside <noscript> is live in this context.
		 */
		const slot = page.locator( SOLD );

		await expect( slot ).toHaveCount( 1 );
		await expect( slot ).toBeHidden();

		/*
		 * The unsold pair is where the rule has to discriminate. Both are on the
		 * same placement and both are equally unfillable here; the only difference
		 * is that one asked to keep its space. It keeps it — reserving the box is
		 * a layout decision, and this visitor is still looking at the layout.
		 */
		await expect( page.locator( UNSOLD ) ).toHaveCount( 2 );
		await expect(
			page.locator( `${ UNSOLD }:not(.aggr-slot--needs-js)` )
		).toBeVisible();
		await expect(
			page.locator( `${ UNSOLD }.aggr-slot--needs-js` )
		).toBeHidden();
	} );
} );
