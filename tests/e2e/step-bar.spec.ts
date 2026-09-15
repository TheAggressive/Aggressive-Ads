import { expect, test, type Locator, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow } from './accessibility';
import { signIn } from './sign-in-helper';

/**
 * The campaign step bar, measured rather than looked at.
 *
 * It was a grid of equal columns with each step at the left of its own column,
 * so the last step stopped a column short of the bar's end while the first sat
 * against its start. Nothing asserted where either end was, and the bar
 * scrolled sideways on a phone with nothing asserting that either.
 */

async function openWizard( page: Page ): Promise< Locator > {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await page.getByRole( 'button', { name: 'Create campaign' } ).click();

	const bar = page.locator( 'ol.aggr-steps' );

	await expect( bar ).toBeVisible();
	await expect( bar.locator( ':scope > li' ) ).toHaveCount( 3 );

	return bar;
}

interface Geometry {
	contentLeft: number;
	contentRight: number;
	firstLeft: number;
	lastRight: number;
	gaps: number[];
	widths: number[];
	scrolls: boolean;
}

function measure( bar: Locator ): Promise< Geometry > {
	return bar.evaluate( ( list ) => {
		const style = getComputedStyle( list );
		const box = list.getBoundingClientRect();
		const items = Array.from( list.children ).map( ( item ) =>
			item.getBoundingClientRect()
		);

		return {
			contentLeft: box.left + parseFloat( style.paddingLeft ),
			contentRight: box.right - parseFloat( style.paddingRight ),
			firstLeft: items[ 0 ].left,
			/*
			 * Where the last step's bar ends. Each step now spans its own bar
			 * under the label, so the bar's end — not the label's — is what has
			 * to meet the end of the list.
			 */
			lastRight: items[ items.length - 1 ].right,
			gaps: items
				.slice( 1 )
				.map( ( item, index ) => item.left - items[ index ].right ),
			widths: items.map( ( item ) => item.width ),
			scrolls: list.scrollWidth > list.clientWidth + 1,
		};
	} );
}

test( 'the first step starts the bar and the last one ends it', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 1280, height: 900 } );

	const bar = await openWizard( page );
	const geometry = await measure( bar );

	expect(
		Math.abs( geometry.firstLeft - geometry.contentLeft ),
		'The first step does not start at the bar’s start.'
	).toBeLessThanOrEqual( 1 );
	expect(
		Math.abs( geometry.contentRight - geometry.lastRight ),
		'The last step does not end at the bar’s end.'
	).toBeLessThanOrEqual( 1 );

	// Evenly spaced between the two ends, not bunched towards either.
	expect(
		Math.max( ...geometry.gaps ) - Math.min( ...geometry.gaps )
	).toBeLessThanOrEqual( 1 );

	// Every label is on screen at this width.
	for ( const name of [ 'Package & dates', 'Ads', 'Review & submit' ] ) {
		await expect(
			bar.getByRole( 'link', { name, exact: true } )
		).toBeVisible();
	}

	await expect( bar.locator( ':scope > li' ).last() ).toHaveText(
		'Review & submit'
	);
} );

test( 'the bar never scrolls sideways, from desktop to a phone', async ( {
	page,
} ) => {
	const bar = await openWizard( page );

	/*
	 * One draft, resized, rather than one draft per width: the question is
	 * how the same bar behaves as its container narrows, and the widths either
	 * side of the narrow tier's threshold are where a layout like this breaks.
	 */
	for ( const width of [ 1280, 1024, 768, 600, 414, 320 ] ) {
		await page.setViewportSize( { width, height: 900 } );

		const geometry = await measure( bar );

		expect(
			geometry.scrolls,
			`The step bar scrolls sideways at ${ width }px.`
		).toBe( false );
		expect(
			geometry.lastRight,
			`The last step runs past the bar at ${ width }px.`
		).toBeLessThanOrEqual( geometry.contentRight + 1 );

		// Steps shrank below their numbers here once, and the circles overlapped.
		expect(
			Math.min( ...geometry.gaps ),
			`Two steps overlap at ${ width }px.`
		).toBeGreaterThanOrEqual( 0 );
		expect(
			Math.min( ...geometry.widths ),
			`A step is narrower than its number at ${ width }px.`
		).toBeGreaterThanOrEqual( 28 );
	}

	await expectNoHorizontalOverflow( page );
} );

test( 'on a phone every step is still named and its number can be tapped', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 320, height: 800 } );

	const bar = await openWizard( page );

	await expect(
		bar.locator( 'li[aria-current="step"] .aggr-steps__label' )
	).toBeVisible();

	/*
	 * The other labels are clipped to a pixel, not removed, so each step is
	 * still a link with its own name. Playwright calls a one-pixel box
	 * visible, so the width is what shows the label is off screen.
	 */
	const creative = bar.getByRole( 'link', { name: 'Ads', exact: true } );

	await expect( creative ).toHaveCount( 1 );

	const labelBox = await creative
		.locator( '.aggr-steps__label' )
		.boundingBox();

	expect( labelBox?.width ?? 0 ).toBeLessThanOrEqual( 1 );

	/*
	 * The number is the touch target. Hit-test the circle's centre and points
	 * 18 pixels either side of it: all of them have to land on this step's
	 * link, which is what a 44-pixel target means in practice.
	 */
	// elementFromPoint() only sees the viewport, and the bar starts below it.
	await bar.scrollIntoViewIfNeeded();

	const circle = await bar.locator( ':scope > li' ).nth( 1 ).boundingBox();

	expect( circle ).not.toBeNull();

	const hits = await page.evaluate(
		( { x, y } ) =>
			[ -18, 0, 18 ].flatMap( ( dx ) =>
				[ -18, 0, 18 ].map(
					( dy ) =>
						document
							.elementFromPoint( x + dx, y + dy )
							?.closest( 'a' )
							?.getAttribute( 'data-aggr-step' ) ?? null
				)
			),
		{
			x: ( circle?.x ?? 0 ) + 14,
			y: ( circle?.y ?? 0 ) + ( circle?.height ?? 0 ) / 2,
		}
	);

	expect(
		hits.every( ( step ) => 'creative' === step ),
		`A tap near the Ads step's number missed its link: ${ JSON.stringify(
			hits
		) }`
	).toBe( true );
} );

test( 'on a phone the current step is named under the numbers, not over them', async ( {
	page,
} ) => {
	await page.setViewportSize( { width: 320, height: 800 } );

	const bar = await openWizard( page );
	const label = bar.locator( 'li[aria-current="step"] .aggr-steps__label' );

	await expect( label ).toHaveText( 'Package & dates' );

	const layout = await bar.evaluate( ( list ) => {
		const caption = list
			.querySelector( 'li[aria-current="step"] .aggr-steps__label' )
			?.getBoundingClientRect();
		// A step that is not current has no caption, so its circle is centred
		// in its own box: that box's middle plus the circle's radius.
		const step = list.children[ 1 ].getBoundingClientRect();

		return {
			captionTop: caption?.top ?? 0,
			captionBottom: caption?.bottom ?? 0,
			captionWidth: caption?.width ?? 0,
			circlesBottom: step.top + step.height / 2 + 14,
			barBottom: list.getBoundingClientRect().bottom,
		};
	} );

	expect(
		layout.captionWidth,
		'The current step’s label is not shown.'
	).toBeGreaterThan( 20 );
	expect(
		layout.captionTop,
		'The current step’s label overlaps the numbers.'
	).toBeGreaterThanOrEqual( layout.circlesBottom - 1 );
	expect(
		layout.captionBottom,
		'The current step’s label runs out of the bar.'
	).toBeLessThanOrEqual( layout.barBottom );
} );

test( 'a tablet-width bar still shows every label', async ( { page } ) => {
	await page.setViewportSize( { width: 768, height: 900 } );

	const bar = await openWizard( page );

	for ( const name of [ 'Ads', 'Review & submit' ] ) {
		const box = await bar
			.getByRole( 'link', { name, exact: true } )
			.locator( '.aggr-steps__label' )
			.boundingBox();

		expect(
			box?.width ?? 0,
			`"${ name }" is hidden at tablet width.`
		).toBeGreaterThan( 1 );
	}
} );
