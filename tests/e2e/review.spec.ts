import { expect, test } from '@playwright/test';
import { expectAdminA11y, expectModalA11y } from './accessibility';
import { signInToAdmin } from './admin-login';
import { wpPluginFile } from './wp-cli';

/**
 * The review screens after the React conversion.
 *
 * The assertions worth making are the ones the server-rendered version got for
 * free and this one has to pay for: that the bundle mounts at all, that a
 * decision reaches the REST route and comes back as new server state, and that
 * the URL still means something — a reviewer bookmarks a campaign, and the
 * notification emails link straight to one.
 */
test( 'a reviewer works the queue, claims a campaign and writes notes', async ( {
	page,
} ) => {
	await signInToAdmin( page );

	await page.goto( '/wp-admin/admin.php?page=aggr-review' );

	// Mounted, before anything else. Without this the locators below would each
	// time out and the failure would read "no such button" rather than "the
	// bundle never ran".
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Campaign review' } )
	).toBeVisible();
	/*
	 * Rows by DataViews' own class, because the queue's table is DataViews now
	 * and the hand-rolled `.aggr-review-table` is gone. Asserting on the
	 * component's markup rather than on a role keeps this failing loudly if the
	 * table stops rendering at all — which is the thing worth catching, since
	 * an unstyled or unmounted DataViews still produces rows for a role query.
	 */
	await expect(
		page.locator( '.dataviews-view-table__row' )
	).not.toHaveCount( 0 );
	await expectAdminA11y( page );

	/*
	 * Into one campaign, and the URL says which.
	 *
	 * The campaign is reached through the link button this screen renders into
	 * the title field, not through a positional cell. DataViews decides its own
	 * column order and may put a checkbox or a primary-column wrapper first, so
	 * "the first `td`" is a guess about somebody else's markup — it timed out
	 * on exactly that. The button is ours and is guaranteed to be there.
	 */
	const firstTitle = page
		.locator( '.dataviews-view-table__row .aggr-linkbutton' )
		.first();

	await expect( firstTitle ).toBeVisible();

	const title = ( await firstTitle.innerText() ).trim();

	await firstTitle.click();

	await expect(
		page.getByRole( 'heading', { level: 1, name: title } )
	).toBeVisible();
	await expect( page ).toHaveURL( /campaign=\d+/ );
	/*
	 * The strategy reaches the screen, in words. It used to be a panel of its
	 * own that printed the slugs (`FLAT`) and repeated the campaign title as
	 * the line item's name; it is now part of the one summary, labelled, and
	 * the name appears only when somebody renamed it.
	 */
	const summary = page.getByRole( 'region', { name: 'Campaign summary' } );
	await expect( summary ).toBeVisible();
	await expect(
		summary
			.locator( '.aggr-fact' )
			.filter( { hasText: 'Pricing' } )
			.getByText( 'Flat fee', { exact: true } )
	).toBeVisible();
	await expect( summary.getByText( 'FLAT', { exact: true } ) ).toHaveCount(
		0
	);
	await expect( summary.getByText( title, { exact: true } ) ).toHaveCount(
		0
	);

	// The saved delivery policy reads as a summary, and opens to edit.
	const delivery = page.getByRole( 'region', { name: 'Delivery policy' } );
	await expect(
		delivery.locator( '.aggr-policy-summary li' )
	).not.toHaveCount( 0 );
	await delivery.getByRole( 'button', { name: 'Edit delivery' } ).click();
	await expect(
		delivery.getByLabel( 'Daily impression limit' )
	).toBeVisible();
	await expectAdminA11y( page );
	await delivery.getByRole( 'button', { name: 'Close' } ).click();
	await expect(
		delivery.getByLabel( 'Daily impression limit' )
	).toBeHidden();
	await expectAdminA11y( page );

	const campaignUrl = page.url();

	// A decision goes to REST and returns the campaign the server now holds.
	const claim = page.getByRole( 'button', { name: 'Start review' } );

	if ( await claim.isVisible() ) {
		await claim.click();
		await expect( page.locator( '.aggr-flash--success' ) ).toBeVisible();
		await expect( claim ).toBeHidden();
	}

	// Internal notes round-trip through the route, not through a form post.
	const note = `Checked by e2e ${ Date.now() }`;

	await page.getByLabel( 'Visible to staff only' ).fill( note );
	await page.getByRole( 'button', { name: 'Save internal notes' } ).click();
	await expect(
		page.locator( '.aggr-flash--success' ).filter( {
			hasText: 'Internal notes saved.',
		} )
	).toBeVisible();

	// Reloading proves it was stored rather than only drawn.
	await page.reload();
	await expect( page.getByLabel( 'Visible to staff only' ) ).toHaveValue(
		note
	);

	// A bookmarked campaign opens on that campaign.
	await page.goto( campaignUrl );
	await expect(
		page.getByRole( 'heading', { level: 1, name: title } )
	).toBeVisible();

	// Back returns to the queue without a full page load.
	await page
		.getByRole( 'button', { name: /Back to campaign review/ } )
		.click();
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Campaign review' } )
	).toBeVisible();
	await expectAdminA11y( page );
} );

test( 'a tall creative stays inside its preview box', async ( { page } ) => {
	/*
	 * A 160x600 skyscraper used to render 204px below its own card and over
	 * the text beneath it. The cause was CSS rather than data — the staff card
	 * redefined a box the portal already sizes, and a percentage max-height
	 * lost the definite parent it resolves against.
	 *
	 * **The creative is inside a sandboxed frame now**, so this can no longer
	 * swap the image and watch what the card does: nothing here can reach into
	 * that document, which is the point of it. What it asserts instead is the
	 * property the swap was a way of reaching — the frame stays inside its box
	 * at every width the preview offers, including the widest, which is the
	 * one that would push a card sideways if the stage did not scroll.
	 */
	await signInToAdmin( page );

	// Seeded here rather than borrowed from whatever the queue happens to hold:
	// no other fixture in the suite carries a creative, and depending on the
	// wizard spec having run first would make this pass or fail by ordering.
	const campaignId = wpPluginFile(
		'tests/e2e/seed-review-creative.php'
	).trim();

	expect( Number( campaignId ) ).toBeGreaterThan( 0 );

	/*
	 * The frame never asks for the bytes itself. Sandboxed to an opaque
	 * origin, its requests carry no login cookie: the file route refused
	 * them, Chrome blocked the refusal, and every unapproved creative
	 * previewed as an empty white box while this test, which measures the
	 * frame, passed. The artwork now travels inside the preview document.
	 */
	const frameFileRequests: string[] = [];

	page.on( 'request', ( request ) => {
		if (
			/creatives(%2F|\/)\d+(%2F|\/)file/.test( request.url() ) &&
			request.frame() !== page.mainFrame()
		) {
			frameFileRequests.push( request.url() );
		}
	} );

	const previewDocument = page.waitForResponse( ( response ) =>
		/creatives(%2F|\/)\d+(%2F|\/)preview/.test( response.url() )
	);

	await page.goto(
		`/wp-admin/admin.php?page=aggr-review&campaign=${ campaignId }`
	);

	const preview = page.locator( '.aggr-creative__preview' ).first();
	const frame = preview.locator( 'iframe.aggr-device__frame' );

	await frame.waitFor();

	const document = await previewDocument;

	expect( document.status() ).toBe( 200 );
	expect( document.headers()[ 'content-type' ] ).toContain( 'text/html' );
	expect( await document.text() ).toContain( 'src="data:image/png;base64,' );

	// The isolation, on the reviewer's screen as on the advertiser's.
	await expect( frame ).toHaveAttribute( 'sandbox', '' );
	expect( frameFileRequests ).toEqual( [] );

	for ( const width of [ 'Phone', 'Tablet', 'Desktop' ] ) {
		await preview
			.getByRole( 'button', { name: new RegExp( width ) } )
			.click();

		await expect
			.poll( async () =>
				page.evaluate( () => {
					const element = document.querySelector(
						'.aggr-device__frame'
					);
					const box = document.querySelector(
						'.aggr-creative__preview'
					);

					if ( ! element || ! box ) {
						return 999;
					}

					return Math.round(
						element.getBoundingClientRect().bottom -
							box.getBoundingClientRect().bottom
					);
				} )
			)
			.toBeLessThanOrEqual( 0 );
	}

	// And the page itself never scrolls sideways because of it: the stage
	// scrolls instead, which is what keeps a 1280px frame usable on a laptop.
	const overflow = await page.evaluate(
		() =>
			document.documentElement.scrollWidth -
			document.documentElement.clientWidth
	);

	expect( overflow ).toBeLessThanOrEqual( 0 );
} );

test( 'a decision that needs feedback is taken in an accessible dialog', async ( {
	page,
} ) => {
	await signInToAdmin( page );

	const campaignId = wpPluginFile(
		'tests/e2e/seed-review-creative.php'
	).trim();

	await page.goto(
		`/wp-admin/admin.php?page=aggr-review&campaign=${ campaignId }`
	);

	// Claim it, so the edges that require feedback become available.
	const claim = page.getByRole( 'button', { name: 'Start review' } );

	if ( await claim.isVisible() ) {
		await claim.click();
		await expect( claim ).toBeHidden();
	}

	const trigger = page.getByRole( 'button', { name: 'Request changes' } );

	await expect( trigger ).toBeVisible();
	await expectAdminA11y( page );

	await trigger.click();

	const dialog = page.getByRole( 'dialog', { name: 'Request changes' } );

	await expect( dialog ).toBeVisible();

	// Focus lands in the feedback box, so the reviewer can type at once.
	await expect(
		dialog.getByLabel( 'Feedback the advertiser will see' )
	).toBeFocused();
	await expectModalA11y( page );

	// The page behind is hidden from assistive technology while it is open.
	await expect( page.locator( '#wpwrap' ) ).toHaveAttribute(
		'aria-hidden',
		'true'
	);

	// Refusing without a reason is refused by the workflow, so the button says
	// so before the click rather than after it.
	const confirm = dialog.getByRole( 'button', { name: 'Request changes' } );

	await expect( confirm ).toBeDisabled();

	// Focus is trapped: a full cycle plus one never leaves the panel.
	const stops = await page.evaluate( () => {
		const panel = document.querySelector( '[role="dialog"]' );
		return panel
			? panel.querySelectorAll(
					'a[href], button:not([disabled]), textarea, input, select'
			  ).length
			: 0;
	} );

	for ( let press = 0; press <= stops; press++ ) {
		await page.keyboard.press( 'Tab' );
		expect(
			await page.evaluate(
				() =>
					document
						.querySelector( '[role="dialog"]' )
						?.contains( document.activeElement ) ?? false
			),
			`Focus left the dialog after ${ press + 1 } Tab press(es).`
		).toBe( true );
	}

	// Escape closes and hands focus back to the button that opened it.
	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toBeHidden();
	await expect( trigger ).toBeFocused();

	// And the decision goes through when the reason is given.
	await trigger.click();
	await dialog
		.getByLabel( 'Feedback the advertiser will see' )
		.fill( 'Please resize the artwork to 728x90.' );
	await dialog.getByRole( 'button', { name: 'Request changes' } ).click();

	await expect( dialog ).toBeHidden();
	await expect( page.locator( '.aggr-flash--success' ) ).toBeVisible();
	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Advertiser-facing feedback',
		} )
	).toBeVisible();
} );

/**
 * Cancelling asks first, and only goes through when confirmed.
 *
 * Cancel needs no feedback, so it skipped the dialog that refusals use and
 * cancelled on one click — from a button beside Pause, and a cancelled campaign
 * has no way back. The browser drive that found it is what this repeats.
 */
test( 'cancelling a campaign asks for confirmation first', async ( {
	page,
} ) => {
	await signInToAdmin( page );

	const campaignId = wpPluginFile( 'tests/e2e/seed-cancellable.php' ).trim();

	expect( campaignId ).not.toBe( '0' );

	await page.goto(
		`/wp-admin/admin.php?page=aggr-review&campaign=${ campaignId }`
	);

	const status = page.locator( '.aggr-admin-head .aggr-pill' );
	const trigger = page.getByRole( 'button', { name: 'Cancel campaign' } );

	await expect( status ).toHaveText( /paused/i );
	await trigger.click();

	const dialog = page.getByRole( 'dialog', { name: 'Cancel campaign' } );

	await expect( dialog ).toBeVisible();
	await expect( dialog ).toContainText( 'cannot be undone' );

	// Asking is not doing: nothing has moved while the question is open.
	await expect( status ).toHaveText( /paused/i );

	// "Go back", not a second "Cancel", and focus returns to the trigger.
	await dialog.getByRole( 'button', { name: 'Go back' } ).click();
	await expect( dialog ).toBeHidden();
	await expect( trigger ).toBeFocused();
	await expect( status ).toHaveText( /paused/i );

	// Confirmed, it goes through.
	await trigger.click();
	await dialog.getByRole( 'button', { name: 'Cancel campaign' } ).click();
	await expect( dialog ).toBeHidden();
	await expect( status ).toHaveText( /cancel/i );
} );

/**
 * The decisions stay in reach on a long page.
 *
 * They sat in the page header, so a reviewer read the artwork, the checklist,
 * the policy and the trail and then scrolled back past all of it to act. They
 * are in one bar at the end of the content now, stuck to the bottom of the
 * screen. The page is made taller than the viewport first, or "the bar is at
 * the bottom of the screen" would also be true of a bar that simply sat in its
 * place under a short page.
 */
test( 'the decisions stay in reach at the bottom of a long page', async ( {
	page,
} ) => {
	await signInToAdmin( page );

	const campaignId = wpPluginFile( 'tests/e2e/seed-cancellable.php' ).trim();

	await page.setViewportSize( { width: 1280, height: 480 } );
	await page.goto(
		`/wp-admin/admin.php?page=aggr-review&campaign=${ campaignId }`
	);

	const bar = page.getByRole( 'region', { name: 'Review actions' } );

	await expect( bar ).toBeVisible();

	const tall = await page.evaluate(
		() => document.documentElement.scrollHeight > window.innerHeight + 200
	);

	expect( tall, 'The page must be longer than the screen.' ).toBe( true );

	await page.evaluate( () => window.scrollTo( 0, 0 ) );

	const box = await bar.boundingBox();

	expect( box ).not.toBeNull();
	expect( Math.round( ( box?.y ?? 0 ) + ( box?.height ?? 0 ) ) ).toBe( 480 );

	// Each decision is on the page once, in the bar. The header keeps Edit,
	// which leaves the screen, and nothing else.
	const decisions = await bar
		.locator( '.aggr-actionbar__actions button' )
		.allInnerTexts();

	expect( decisions.length ).toBeGreaterThan( 0 );

	for ( const name of decisions ) {
		await expect(
			page.getByRole( 'button', { name, exact: true } )
		).toHaveCount( 1 );
	}

	await expect(
		page.locator( '.aggr-admin-head' ).getByRole( 'button' )
	).toHaveText( [ 'Edit' ] );
	await expectAdminA11y( page );
} );

/**
 * The review screen lists who decided.
 *
 * The payload test proves the name is sent. This one proves the bundle
 * renders it: a field the server sends and the screen never reads is how the
 * advertiser's card shipped without a reviewer ever seeing the same rows.
 */
test( 'a reviewer reads who decided about an ad', async ( { page } ) => {
	await signInToAdmin( page );

	wpPluginFile( 'tests/e2e/seed-review-creative.php' );

	const seeded = JSON.parse(
		wpPluginFile( 'tests/e2e/seed-review-history.php' )
	) as { campaign: number; actor: string };

	expect( seeded.actor ).not.toBe( '' );

	await page.goto(
		`/wp-admin/admin.php?page=aggr-review&campaign=${ seeded.campaign }`
	);

	const history = page.locator( '.aggr-ad-history' );

	await expect( history ).toBeVisible();
	await expect( history.getByText( 'Review history' ) ).toBeVisible();
	await expect( history.getByText( 'Not approved' ) ).toBeVisible();
	await expect( history.getByText( 'The logo is stretched.' ) ).toBeVisible();
	await expect(
		history.getByText( seeded.actor, { exact: true } )
	).toBeVisible();
	await expectAdminA11y( page );
} );

/**
 * Creating a campaign for an advertiser, from the queue.
 *
 * The dialog is the only place in the product where a staff member chooses
 * which organization a campaign belongs to, so the assertion that matters is
 * that the choice actually reaches the server: the run ends on the portal
 * wizard for a campaign that did not exist a moment ago.
 */
test( 'a reviewer creates a campaign for an advertiser', async ( { page } ) => {
	await signInToAdmin( page );

	await page.goto( '/wp-admin/admin.php?page=aggr-review' );

	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Campaign review' } )
	).toBeVisible();

	await page.getByRole( 'button', { name: 'Create campaign' } ).click();

	const dialog = page.getByRole( 'dialog' );

	await expect( dialog ).toBeVisible();

	// Core's Modal renders outside .aggr-admin, so it is scanned on its own.
	await expectModalA11y( page );

	// Nothing can be created until an advertiser is named.
	const submit = dialog.getByRole( 'button', { name: 'Create and open' } );

	await expect( submit ).toBeDisabled();

	/*
	 * By name, the fixture's own advertiser. "The first one in the list" was
	 * Bright Angle Media in CI and a real organization on a Studio site, which
	 * collected forty "Created for a client" campaigns before anyone noticed.
	 */
	await dialog
		.getByLabel( 'Advertiser' )
		.selectOption( { label: 'Bright Angle Media' } );
	await dialog.getByLabel( 'Campaign name' ).fill( 'Created for a client' );

	await expect( submit ).toBeEnabled();
	await submit.click();

	// It lands in the advertiser's own wizard, on a campaign that now exists.
	await expect( page ).toHaveURL( /\/campaigns\/\d+\/?$/ );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Created for a client' } )
	).toBeVisible();
} );
