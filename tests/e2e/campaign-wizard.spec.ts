import { expect, test } from '@playwright/test';
import {
	expectDialogKeyboard,
	expectOpenDialogA11y,
	expectPortalA11y,
} from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';
import { wp } from './wp-cli';

test( 'advertiser completes and submits the accessible five-step wizard', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await expect( page ).toHaveURL( /\/advertiser\/?$/ );
	await expect( page.locator( 'body' ) ).toHaveClass(
		/wp-theme-twentytwentyfive/
	);
	await expect( page.locator( 'body' ) ).toHaveClass( /aggr-portal/ );
	await expect( page.locator( '.wp-site-blocks' ) ).toHaveCount( 0 );
	await expect(
		page.getByRole( 'heading', { level: 1, name: /Welcome back/ } )
	).toBeVisible();

	const portalCssLoaded = await page.evaluate( () =>
		Array.from( document.styleSheets ).some(
			( sheet ) =>
				sheet.href?.includes(
					'/plugins/aggressive-ads/dist/styles/portal.css'
				)
		)
	);
	expect( portalCssLoaded ).toBe( true );

	await page.evaluate(
		() => ( document.activeElement as HTMLElement | null )?.blur()
	);
	await page.keyboard.press( 'Tab' );
	await expect(
		page.getByRole( 'link', { name: 'Skip to main content' } )
	).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#aggr-main' ) ).toBeFocused();
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Create campaign' } ).click();
	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Name your campaign and choose a package',
		} )
	).toBeFocused();

	/*
	 * The panel is asserted present after submission further down. Here it
	 * must be absent: the line item exists by now — the repository writes a
	 * default one at creation — so an advertiser on step 1 would otherwise
	 * read five rows of delivery defaults nobody chose before reaching the
	 * first field. Absence is the half worth pinning; presence already is.
	 */
	await expect(
		page.getByRole( 'region', { name: 'Delivery strategy' } )
	).toHaveCount( 0 );

	/*
	 * Step 1 collects what the campaign *is*. Everything that describes a
	 * campaign that already exists belongs to a later step or to the
	 * post-submission screen, so none of it may appear here.
	 */
	await expect( page.getByLabel( 'Notes for the review team' ) ).toHaveCount(
		0
	);
	await expect( page.getByRole( 'region', { name: 'Summary' } ) ).toHaveCount(
		0
	);
	await expect(
		page.getByRole( 'heading', { name: 'Creatives', exact: true } )
	).toHaveCount( 0 );

	// Five steps, and no link to the one that was folded away.
	const progress = page.getByRole( 'list', {
		name: 'Campaign creation progress',
	} );
	await expect( progress.getByRole( 'listitem' ) ).toHaveCount( 5 );
	await expect(
		progress.getByRole( 'link', { name: 'Package' } )
	).toHaveCount( 0 );

	const title = `E2E browser campaign ${ Date.now() }`;
	const name = page.getByLabel( 'Campaign name' );

	/*
	 * Emptied first. A new draft is created titled "Untitled campaign", so
	 * typing into the field appends to that rather than replacing it — which
	 * is why the caret assertion below is worth making at all. Typed rather
	 * than filled because `fill()` sets the value in one shot and never
	 * produces the keystrokes autosave debounces on.
	 */
	await name.fill( '' );
	await name.click();
	await page.keyboard.type( title );

	/*
	 * Autosave must not move the caret out from under someone who is still
	 * typing. It fires on a 600ms debounce, announces through a polite live
	 * region, and deliberately touches focus not at all — so the assertion is
	 * that the field the user was in is still focused, with the caret still
	 * where they left it, after a save has actually completed.
	 */
	/*
	 * The announcement text is asserted, not merely its presence. These strings
	 * are hydrated by the server and were being overwritten with empty defaults
	 * by the client store, so the region rendered, passed axe, and said nothing
	 * — for saves, and for the save *errors* that matter more.
	 */
	const status = page.locator( '[id^="aggr-autosave-status-"]' );
	await expect( status ).toHaveText( 'Draft saved.', { timeout: 15_000 } );

	await expect( name ).toBeFocused();
	expect(
		await name.evaluate(
			( el ) => ( el as HTMLInputElement ).selectionStart
		)
	).toBe( title.length );

	/*
	 * The package is chosen on this screen now, not the next one. The default
	 * arrives pre-selected from the server, and picking another has to be a
	 * plain radio in a named group — the whole point of merging the two steps
	 * was to lose a page load, not to lose the grouping a screen reader needs.
	 */
	const packages = page.getByRole( 'group', { name: 'Choose a package' } );
	await expect( packages ).toBeVisible();
	await expect(
		packages.getByRole( 'radio', { name: /Launch bundle/ } )
	).toBeChecked();

	/*
	 * The button says what it does, and says it from the first paint. Both
	 * labels are rendered by the server and CSS picks one on `scripting`, so
	 * the reader never sees the text change under them — which is exactly what
	 * rewriting it from the module used to cause on every reload.
	 */
	await expect(
		page.getByRole( 'button', { name: 'Continue', exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Save and continue' } )
	).toHaveCount( 0 );

	// Attached, which the label no longer tells us.
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	await expectPortalA11y( page );

	// No click on Continue: choosing a package finishes this step. The campaign
	// has a name by now, which is the condition for advancing at all.
	await packages.getByRole( 'radio', { name: /Focused sidebar/ } ).check();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Upload creative' } )
	).toBeFocused();
	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );

	/*
	 * The button is hidden only after the module attaches, so its absence is
	 * the evidence that the automatic path is the one being exercised below —
	 * and that a browser without the module would still have the form.
	 */
	await expect(
		upload.getByRole( 'button', { name: 'Upload creative' } )
	).toBeHidden();

	await upload.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'e2e-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );

	// A file on its own is not enough to send: the creative needs somewhere to
	// point, so nothing may be uploaded until the destination is filled in.
	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toHaveCount( 0 );

	/*
	 * No click. Leaving the field is the commit — `fill()` alone raises only
	 * `input`, and this deliberately does not send on `input`: a half-typed
	 * address like `https://exa` is a syntactically valid URL and would upload
	 * to it. Blurring is what a person does by tabbing on or clicking away.
	 */
	const destination = upload.getByLabel( 'Destination URL' );
	await destination.fill( 'https://www.example.com/exhibition' );
	await destination.blur();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();
	const preview = page.getByRole( 'img', {
		name: 'Advertisement linking to example.com',
	} );
	await expect( preview ).toBeVisible();
	await expect
		.poll( () =>
			preview.evaluate(
				( image: HTMLImageElement ) => image.naturalWidth
			)
		)
		.toBe( 300 );
	await expectPortalA11y( page );

	const previewTrigger = page.getByRole( 'link', {
		name: 'Advertisement linking to example.com',
	} );
	await expectDialogKeyboard(
		page,
		previewTrigger,
		'Preview Article sidebar'
	);

	const removeTrigger = page.getByRole( 'link', { name: 'Remove creative' } );
	await expectDialogKeyboard( page, removeTrigger, 'Remove this creative?' );

	await page.getByRole( 'link', { name: 'Continue to schedule' } ).click();

	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Confirm destinations and schedule',
		} )
	).toBeFocused();
	const destinations = page.locator( '#aggr-destinations' );
	await expect(
		destinations.getByText( 'https://www.example.com/exhibition', {
			exact: true,
		} )
	).toBeVisible();
	await expect(
		page.locator( 'a[href="https://www.example.com/exhibition"]' )
	).toHaveCount( 0 );
	await expectPortalA11y( page );

	const start = new Date( Date.now() + 10 * 24 * 60 * 60 * 1000 )
		.toISOString()
		.slice( 0, 10 );
	await page.getByLabel( 'Start date' ).fill( start );
	await page.getByRole( 'button', { name: 'Continue to review' } ).click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Review your campaign' } )
	).toBeFocused();
	await expect(
		page.getByRole( 'heading', { level: 3, name: 'Ready for submission' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', { level: 1, name: title, exact: true } )
	).toBeVisible();
	await expectPortalA11y( page );
	await page.getByRole( 'link', { name: 'Continue to submit' } ).click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Submit your campaign' } )
	).toBeFocused();
	await expect(
		page.getByRole( 'heading', {
			level: 3,
			name: 'Send this campaign to the review team?',
		} )
	).toBeVisible();
	/*
	 * The note is written here, not on step 1, and it is posted by the submit
	 * button rather than autosaved — so typing it and clicking straight through
	 * is exactly the sequence that has to keep it. No wait, no debounce.
	 */
	const note = 'Please run this in the evening slot if you can.';
	await page.getByLabel( 'Notes for the review team' ).fill( note );

	await expectPortalA11y( page );
	await page
		.getByRole( 'button', { name: 'Submit campaign for review' } )
		.click();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Campaign submitted' } )
	).toBeVisible();
	await expect(
		page.getByText( 'Submitted', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Submit campaign for review' } )
	).toHaveCount( 0 );
	await expect( page.getByLabel( 'Campaign name' ) ).toHaveCount( 0 );
	await expect(
		page
			.getByRole( 'region', { name: 'Your notes for the review team' } )
			.getByText( note )
	).toBeVisible();
	const deliveryStrategy = page.getByRole( 'region', {
		name: 'Delivery strategy',
	} );
	await expect( deliveryStrategy ).toBeVisible();
	await expect(
		deliveryStrategy.getByText( title, { exact: true } )
	).toBeVisible();
	await expect(
		deliveryStrategy
			.locator( '.aggr-fact' )
			.filter( { hasText: 'Pricing' } )
			.getByText( 'FLAT', { exact: true } )
	).toBeVisible();

	await page.reload();
	await expect(
		page.getByText( 'Submitted', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Submit campaign for review' } )
	).toHaveCount( 0 );

	// Put this isolated browser fixture into the post-approval state so the
	// advertiser's ad-update interaction is exercised without mocking markup.
	const campaignId = new URL( page.url() ).pathname.match(
		/campaigns\/(\d+)/
	)?.[ 1 ];
	expect( campaignId ).toBeTruthy();
	wp( 'post', 'update', campaignId!, '--post_status=aggr_live' );

	await page.reload();
	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Your ads' } )
	).toBeVisible();

	const livePreview = page.getByRole( 'link', {
		name: 'View larger preview of Article sidebar',
	} );
	await expectDialogKeyboard( page, livePreview, 'Preview Article sidebar' );

	const update = page.getByRole( 'link', { name: 'Update' } );
	await update.click();
	await expect(
		page.getByRole( 'dialog', { name: 'Update Article sidebar' } )
	).toBeVisible();
	await expect( page.getByLabel( 'Replacement ad creative' ) ).toBeVisible();
	await expect( page.getByLabel( 'Destination URL' ) ).toHaveValue(
		'https://www.example.com/exhibition'
	);
	await expect( page.getByLabel( 'Image description' ) ).toHaveCount( 0 );
	await expectOpenDialogA11y( page );
	await page.keyboard.press( 'Escape' );
	await expect(
		page.getByRole( 'dialog', { name: 'Update Article sidebar' } )
	).toBeHidden();
	await expect( update ).toBeFocused();
} );
