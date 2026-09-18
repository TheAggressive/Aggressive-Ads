import { expect, test, type Page } from '@playwright/test';
import {
	expectDialogKeyboard,
	expectOpenDialogA11y,
	expectPortalA11y,
} from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';
import { wp } from './wp-cli';

/**
 * A date some days ahead, as a date input holds it.
 *
 * @param days Days from now.
 * @return `YYYY-MM-DD`.
 */
function futureDate( days: number ): string {
	return new Date( Date.now() + days * 24 * 60 * 60 * 1000 )
		.toISOString()
		.slice( 0, 10 );
}

/**
 * Holds the autosave's response back after the server has applied it.
 *
 * **Let the server apply it, then hold the answer back.** Delaying the
 * request instead — the obvious way to widen a race — inverts this one: the
 * POST reaches a server that has not moved yet, succeeds, and the test passes
 * over the unfixed client. It did, until this was written the other way round.
 *
 * Fetching first means the revision is already bumped when the click happens,
 * and only the browser is still behind. That is the actual condition, and it is
 * what the pause before choosing a package produces on a real machine.
 *
 * @param page The page whose autosave to hold.
 * @return Whether the server has applied the save yet, for `expect.poll`.
 */
async function holdAutosaveResponse( page: Page ): Promise< () => boolean > {
	let applied = false;

	await page.route( '**/wp-json/aggr/v1/campaigns/*', async ( route ) => {
		if ( 'PATCH' !== route.request().method() ) {
			await route.continue();
			return;
		}

		const response = await route.fetch();

		applied = true;

		await new Promise( ( resolve ) => setTimeout( resolve, 2000 ) );
		await route.fulfill( { response } );
	} );

	return () => applied;
}

test( 'pressing Continue waits for the save already on the wire', async ( {
	page,
} ) => {
	/*
	 * **The wizard's own autosave was making step one impossible to leave.**
	 *
	 * Changing a field arms a six-hundred-millisecond debounce. Pause about
	 * that long and the PATCH is on the wire when Continue is pressed. The
	 * form still holds the old `autosave_rev`, the PATCH bumps the stored one
	 * first, and the POST arrives one revision behind — refused, correctly,
	 * with a message about another window the advertiser never opened.
	 *
	 * The first fix waited only on the package radio, which used to
	 * auto-advance, and its test drove the radio: both were green while
	 * pressing Continue still sent a stale revision. Continue is now the only
	 * way on, so this is the path.
	 *
	 * Holding the response open makes the overlap a fact of the test rather
	 * than a race it hopes to win: the server applies the autosave, and only
	 * the browser is told late.
	 */
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	const saveApplied = await holdAutosaveResponse( page );

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	// The precondition that rules out auto-advance: a package is already chosen.
	await expect(
		page.locator( 'input[name="package_id"]:checked' )
	).toHaveCount( 1 );

	await page.getByLabel( 'Start date' ).fill( futureDate( 10 ) );

	await expect.poll( saveApplied, { timeout: 5000 } ).toBe( true );

	// By name: on a wide panel the step's own button is hidden and the order
	// summary's submits the step's form, which is the path this has to cover.
	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();

	/*
	 * Landing on the ads step is the assertion. A refused save returns to
	 * `step=details`, so the URL tells "advanced" from "bounced" without
	 * depending on which message was rendered.
	 */
	await expect( page ).toHaveURL( /step=creative/, { timeout: 15_000 } );

	/*
	 * And exactly one notice, because the second half of this defect was two:
	 * the campaign and creative notices read the same `aggr_notice` parameter,
	 * so one refused save also rendered as a creative error. A count catches
	 * that where matching on text would not.
	 */
	const toasts = page.locator( '.aggr-toast' );

	await expect( toasts ).toHaveCount( 1 );
	await expect( toasts ).not.toContainText( 'changed in another window' );
	await expect( toasts ).not.toContainText( 'creative could not be saved' );
} );

test( 'the page heading renames the campaign, markup characters and all', async ( {
	page,
} ) => {
	/*
	 * **What the advertiser typed is what they get back.** A name containing
	 * `&`, `'`, `"` or a backslash once could not be saved at all: the stored
	 * title never matched the typed one, the save rolled back, and the page
	 * reported a conflict. The heading is the only place a name is typed now,
	 * so it carries the whole trip — the field, the PATCH, and the page
	 * drawing the name again after a reload.
	 *
	 * Encoded entities are checked as well as the text, since the display half
	 * of that bug showed "&#038;" after a save that had succeeded.
	 */
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();

	const heading = page.locator( 'h1.aggr-title' );
	const rename = heading.getByRole( 'button' );

	// The button exists only once the module has attached.
	await expect( rename ).toBeVisible();
	await expect(
		page.getByRole( 'heading', { level: 1 } )
	).toHaveAccessibleName( 'Untitled campaign' );

	await rename.click();

	const field = page.getByRole( 'textbox', { name: 'Campaign name' } );
	const name = `E2E browser campaign Arts & Culture's "Big" Show \\ ${ Date.now() }`;

	await expect( field ).toBeFocused();
	await field.fill( name );

	const saved = page.waitForResponse(
		( response ) =>
			'PATCH' === response.request().method() &&
			response.url().includes( '/aggr/v1/campaigns/' )
	);

	await field.press( 'Enter' );

	expect( ( await saved ).ok() ).toBe( true );
	await expect( heading ).toHaveText( name );

	// Enter hands focus back to the heading's control, not to the document.
	await expect( rename ).toBeFocused();
	await expect( page.locator( '[id^="aggr-autosave-status-"]' ) ).toHaveText(
		'Campaign renamed.'
	);

	// Escape keeps the name that was there.
	await rename.click();
	await field.fill( 'Not this one' );
	await field.press( 'Escape' );
	await expect( heading ).toHaveText( name );

	await page.reload();

	await expect( heading ).toHaveText( name );
	await expect( heading ).not.toContainText( '&#038;' );
	await expect( heading ).not.toContainText( '&amp;' );
} );

test( 'the first link given is the link every other size starts from', async ( {
	page,
} ) => {
	/*
	 * **One address, typed once.** A package of two sizes used to ask for the
	 * destination on each card, and nearly every campaign sends both to the
	 * same page. After the first upload the second card arrives with that link
	 * filled in and folded behind it, so choosing a file is the whole upload.
	 *
	 * The negative half is the fold: it is a real posted field, so a different
	 * link is still one click away and is what gets sent when it is changed.
	 */
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	/*
	 * Started from the dashboard's package card, which is the other half of
	 * this change: the draft opens on the dates with the package applied.
	 */
	await page
		.getByRole( 'region', { name: 'Start a campaign' } )
		.getByRole( 'button', { name: /Launch bundle/ } )
		.click();

	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Choose a package and dates',
		} )
	).toBeFocused();
	await expect(
		page.getByRole( 'radio', { name: /Launch bundle/ } )
	).toBeChecked();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	await page.getByLabel( 'Start date' ).fill( futureDate( 12 ) );
	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();
	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Add your ads' } )
	).toBeFocused();

	const leaderboard = page.getByRole( 'region', {
		name: 'Homepage leaderboard',
	} );
	const sidebar = page.getByRole( 'region', { name: 'Article sidebar' } );

	// Nothing to start from yet: the first card asks for the address.
	await expect( leaderboard.getByText( 'Goes to' ) ).toHaveCount( 0 );
	await expect(
		leaderboard.getByRole( 'button', { name: 'Upload creative' } )
	).toBeHidden();

	await leaderboard.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'e2e-leaderboard.png',
		mimeType: 'image/png',
		buffer: solidPng( 728, 90 ),
	} );
	await expect(
		leaderboard.getByRole( 'status' ).filter( { hasText: 'File selected' } )
	).toBeAttached();

	const first = leaderboard.getByLabel( 'Destination URL' );
	await first.fill( 'https://www.example.com/season' );
	await first.blur();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();

	// The second card starts from that address, folded.
	const fold = sidebar.locator( 'details.aggr-upload-destination' );

	await expect( fold ).toBeVisible();
	await expect( fold ).not.toHaveAttribute( 'open', '' );
	await expect( fold.locator( 'summary' ) ).toContainText(
		'https://www.example.com/season'
	);
	await expect( sidebar.getByLabel( 'Destination URL' ) ).toHaveValue(
		'https://www.example.com/season'
	);
	await expectPortalA11y( page );

	// A file alone is the upload now. No field is touched.
	await sidebar.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'e2e-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();
	await expect( page.locator( '.aggr-summary' ) ).toContainText(
		'2 of 2 ready'
	);
	await expect(
		page
			.getByRole( 'region', { name: 'Article sidebar' } )
			.getByText( 'https://www.example.com/season', { exact: true } )
	).toBeVisible();

	// Every size has an ad, so the step can be left.
	await page.getByRole( 'button', { name: 'Continue to review' } ).click();
	await expect(
		page.getByRole( 'heading', { level: 3, name: 'Ready check' } )
	).toBeVisible();
} );

test( 'the calendar picks a start by keyboard, months ahead', async ( {
	page,
} ) => {
	/*
	 * The calendar only ever writes into the date field, so the field's value
	 * is the assertion, and autosave saying it saved is the proof that the
	 * write raised the same events typing does. Keyboard only, past the months
	 * first shown, because reaching a later month is what the static picture
	 * could never do.
	 */
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await page
		.getByRole( 'region', { name: 'Start a campaign' } )
		.getByRole( 'button', { name: /Launch bundle/ } )
		.click();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	const calendar = page.locator(
		'[data-aggr-calendar][data-aggr-calendar-ready]'
	);
	await expect( calendar ).toBeAttached();

	// A fixed package sets the end, so only the start picks are offered.
	await expect(
		calendar.getByRole( 'button', { name: '2 weeks' } )
	).toBeHidden();

	await calendar.getByRole( 'button', { name: 'Next month' } ).click();

	const tabStop = calendar.locator( '[data-aggr-day][tabindex="0"]' );
	await expect( tabStop ).toHaveCount( 1 );
	await tabStop.focus();
	await page.keyboard.press( 'PageDown' );
	await page.keyboard.press( 'ArrowRight' );

	const chosen = await page.evaluate(
		() => ( document.activeElement as HTMLElement | null )?.dataset.aggrDay
	);
	expect( chosen ).toMatch( /^\d{4}-\d{2}-\d{2}$/ );

	await page.keyboard.press( 'Enter' );

	await expect(
		page.getByLabel( 'Start date', { exact: true } )
	).toHaveValue( chosen as string );
	await expect(
		calendar.locator( `[data-aggr-day="${ chosen }"]` )
	).toBeFocused();
	await expect( page.locator( '#aggr-run-through' ) ).toContainText(
		'Runs through'
	);
	await expect( calendar.getByRole( 'status' ) ).toContainText(
		'runs through'
	);
	await expect( page.locator( '[id^="aggr-autosave-status-"]' ) ).toHaveText(
		'Draft saved.',
		{ timeout: 15_000 }
	);

	await expectPortalA11y( page );
} );

test( 'advertiser completes and submits the accessible three-step wizard', async ( {
	page,
} ) => {
	/*
	 * Anything the page throws, kept for the assertions below. A module that
	 * fails inside an event handler leaves no mark in the DOM and no request
	 * on the wire — which is indistinguishable from a listener that never
	 * ran, and cost several runs to tell apart.
	 */
	const pageErrors: string[] = [];

	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );

	/*
	 * `void somethingAsync()` discards the rejection, so a module that throws
	 * inside a handler produces no page error and no request — silence that
	 * looks exactly like a listener which never ran.
	 */
	await page.addInitScript( () => {
		(
			window as unknown as { __aggrRejections: string[] }
		 ).__aggrRejections = [];
		window.addEventListener( 'unhandledrejection', ( event ) => {
			(
				window as unknown as { __aggrRejections: string[] }
			 ).__aggrRejections.push( String( event.reason ) );
		} );
	} );
	page.on( 'console', ( message ) => {
		if ( 'error' === message.type() ) {
			pageErrors.push( message.text() );
		}
	} );

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

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();
	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Choose a package and dates',
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

	// Three steps, and no link to the ones that were folded away.
	const progress = page.getByRole( 'list', {
		name: 'Campaign creation progress',
	} );
	await expect( progress.getByRole( 'listitem' ) ).toHaveCount( 3 );

	for ( const retired of [
		'Package',
		'Creative',
		'Destination and schedule',
		'Submit',
	] ) {
		await expect(
			progress.getByRole( 'link', { name: retired, exact: true } )
		).toHaveCount( 0 );
	}

	// Nobody is asked for a name before choosing what they are buying.
	await expect( page.getByLabel( 'Campaign name' ) ).toHaveCount( 0 );

	/*
	 * The schedule autosaves like any field on this step, and the save must
	 * not take focus from the field somebody is still in. The announcement
	 * text is asserted, not merely its presence: these strings are hydrated
	 * by the server and were once overwritten with empty defaults by the
	 * client store, so the region rendered, passed axe, and said nothing.
	 */
	const startField = page.getByLabel( 'Start date' );

	await startField.fill( futureDate( 10 ) );

	const status = page.locator( '[id^="aggr-autosave-status-"]' );
	await expect( status ).toHaveText( 'Draft saved.', { timeout: 15_000 } );
	await expect( startField ).toBeFocused();

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
	 * A fixed package states its last day instead of asking for one. The end
	 * field is still in the form — disabled and hidden, so it is not posted
	 * and the server derives the end — and comes back for a custom package.
	 */
	await expect( page.locator( '#aggr-run-through' ) ).toContainText(
		'Runs through'
	);
	await expect( page.getByLabel( 'End date' ) ).toBeHidden();
	await expect( page.getByLabel( 'End date' ) ).toBeDisabled();

	/*
	 * The button says what it does, and says it from the first paint. Both
	 * labels are rendered by the server and CSS picks one on `scripting`, so
	 * the reader never sees the text change under them — which is exactly what
	 * rewriting it from the module used to cause on every reload.
	 */
	await expect(
		page.getByRole( 'button', { name: 'Continue to ads', exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Save and continue' } )
	).toHaveCount( 0 );

	// Attached, which the label no longer tells us.
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	await expectPortalA11y( page );

	/*
	 * Choosing a package no longer leaves the step: there is a date beside it
	 * now, and a radio that navigated would skip it. The stated last day
	 * follows the package that was chosen.
	 */
	await packages.getByRole( 'radio', { name: /Focused sidebar/ } ).check();
	await expect( page ).toHaveURL(
		/step=details|advertiser\/campaigns\/\d+\/?$/
	);
	await expect( page.locator( '#aggr-run-through' ) ).toContainText(
		'Runs through'
	);

	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Add your ads' } )
	).toBeFocused();

	// The plan is restated above the uploads, with how much is left to do.
	await expect( page.locator( '.aggr-summary' ) ).toContainText(
		'0 of 1 ready'
	);
	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );

	/*
	 * Hidden from the markup, not by the module, so it never paints: it used
	 * to ship visible and be withdrawn on init, which flashed a button on
	 * every load. That makes this a check that the server rendered it right,
	 * not evidence the module attached — the automatic upload below is what
	 * proves that.
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

	/*
	 * And the notice is no longer part of the address.
	 *
	 * The upload is a full page post, so its message has to travel in the
	 * query string — but once the page has rendered it, leaving it there
	 * means a reload claims the upload happened again, and so does a link
	 * sent to somebody else.
	 */
	await expect
		.poll( () => new URL( page.url() ).searchParams.has( 'aggr_notice' ) )
		.toBe( false );
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

	/*
	 * The actions are a row beneath the artwork now, not an overlay on it.
	 * White text over a gradient is a bet on what the advertiser uploaded,
	 * and a light banner loses it.
	 */
	const previewTrigger = page.getByRole( 'link', { name: 'Preview' } );
	await expectDialogKeyboard(
		page,
		previewTrigger,
		'Preview Article sidebar'
	);

	const removeTrigger = page.getByRole( 'link', { name: 'Remove' } );
	await expectDialogKeyboard( page, removeTrigger, 'Remove this creative?' );

	/*
	 * **Saving the destination must not take the page away.**
	 *
	 * This is the one thing no other test in this repository can see. The PHP
	 * proves the handler answers JSON, the module source proves it binds the
	 * form, the markup proves the attributes are rendered — and every one of
	 * those is equally true of a build that posts the whole page anyway,
	 * which is exactly what shipped once.
	 */
	/*
	 * Every form that asked to save asynchronously has to have been bound,
	 * not just one of them. `.first()` matched a card form and said nothing
	 * about the one inside the dialog, which is the form that then posted the
	 * whole page.
	 */
	const wired = page.locator( 'form[data-aggr-save]' );
	const ready = page.locator( 'form[data-aggr-save][data-aggr-save-ready]' );

	expect( await ready.count() ).toBe( await wired.count() );

	await page.getByRole( 'link', { name: 'Edit destination' } ).click();

	const destinationDialog = page.getByRole( 'dialog', {
		name: 'Edit destination',
	} );
	await expect( destinationDialog ).toBeVisible();
	await expectOpenDialogA11y( page );

	// And this one in particular, since it is the one that posted the page.
	await expect(
		destinationDialog.locator( 'form[data-aggr-save-ready]' )
	).toBeAttached();

	// A navigation would replace this, so its survival is the assertion.
	await page.evaluate( () => {
		( window as unknown as { __aggrStayed: boolean } ).__aggrStayed = true;
	} );

	await destinationDialog
		.getByLabel( 'Destination URL' )
		.fill( 'https://www.example.com/rewritten' );
	/*
	 * **The write must leave as a fetch, not as a navigation.**
	 *
	 * This is the whole claim, asked of the request rather than the response:
	 * a navigation request means the browser is posting the form itself, and
	 * everything after that — the reload, the banner, the missing toast — is
	 * a consequence rather than a separate fault. Reading the response body
	 * cannot answer it, because a redirect has no body to read.
	 */
	/*
	 * Nothing leaves an invalid form: the browser cancels the submit before
	 * any listener sees it, so no request, no error and no navigation — the
	 * same silence as a module that never ran.
	 */
	expect(
		await destinationDialog
			.locator( 'form[data-aggr-save]' )
			.evaluate( ( form: HTMLFormElement ) =>
				Array.from( form.elements )
					.filter(
						( field ): field is HTMLInputElement =>
							field instanceof HTMLInputElement &&
							! field.checkValidity()
					)
					.map( ( field ) => `${ field.name }=${ field.value }` )
			),
		'The browser will refuse to submit this form.'
	).toEqual( [] );

	const posted = page.waitForRequest( ( request ) =>
		request.url().includes( 'admin-post.php' )
	);

	await destinationDialog
		.getByRole( 'button', { name: 'Save destination' } )
		.click();

	await expect.poll( () => pageErrors, { timeout: 5000 } ).toEqual( [] );

	expect(
		await page.evaluate(
			() =>
				( window as unknown as { __aggrRejections?: string[] } )
					.__aggrRejections ?? []
		)
	).toEqual( [] );

	expect(
		( await posted ).isNavigationRequest(),
		'The form posted the whole page instead of saving in the background.'
	).toBe( false );

	/*
	 * The toast, not the banner. Both say "Destination saved." — the banner
	 * is what the server renders after a full page post, so a text match
	 * passes on exactly the failure this is here to catch.
	 */
	await expect(
		page.locator( '.aggr-toast', { hasText: 'Destination saved.' } )
	).toBeVisible();
	await expect( destinationDialog ).toBeHidden();

	// The card shows the new address without anybody reloading anything.
	await expect(
		page.getByText( 'https://www.example.com/rewritten', { exact: true } )
	).toBeVisible();

	expect(
		await page.evaluate(
			() =>
				( window as unknown as { __aggrStayed?: boolean } )
					.__aggrStayed === true
		)
	).toBe( true );

	// And it clears itself rather than sitting there for the session.
	await expect(
		page.locator( '.aggr-toast', { hasText: 'Destination saved.' } )
	).toHaveCount( 0, { timeout: 10000 } );

	await page.getByRole( 'button', { name: 'Continue to review' } ).click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Review and submit' } )
	).toBeFocused();
	await expect(
		page.getByRole( 'heading', { level: 3, name: 'Ready check' } )
	).toBeVisible();

	/*
	 * Nobody typed a name, so the wizard named the campaign after its package.
	 * Read back here and asserted against the delivery strategy after
	 * submission, which is where the line item carries it.
	 */
	const title = ( await page.locator( 'h1.aggr-title' ).innerText() ).trim();

	expect( title.startsWith( 'Focused sidebar' ) ).toBe( true );

	// Destinations are text on review, never a link out of an unfinished campaign.
	await expect(
		page
			.locator( '.aggr-review-creatives' )
			.getByText( 'https://www.example.com/rewritten', { exact: true } )
	).toBeVisible();
	await expect(
		page.locator( 'a[href="https://www.example.com/rewritten"]' )
	).toHaveCount( 0 );

	// Submitting is the end of review, not a page of its own.
	await expect(
		page.getByRole( 'heading', {
			level: 3,
			name: 'Notes for the review team',
		} )
	).toBeVisible();
	await expectPortalA11y( page );

	/*
	 * The note is written on review, not on step 1, and it is posted by the submit
	 * button rather than autosaved — so typing it and clicking straight through
	 * is exactly the sequence that has to keep it. No wait, no debounce.
	 */
	const note = 'Please run this in the evening slot if you can.';
	// A textbox, not a label lookup: the notes card is also named by that heading.
	await page
		.getByRole( 'textbox', { name: 'Notes for the review team' } )
		.fill( note );

	await expectPortalA11y( page );
	await page.getByRole( 'button', { name: 'Submit for review' } ).click();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Campaign submitted' } )
	).toBeVisible();
	await expect(
		page
			.locator( '.aggr-pagehead' )
			.getByText( 'Submitted', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Submit for review' } )
	).toHaveCount( 0 );
	await expect( page.getByLabel( 'Campaign name' ) ).toHaveCount( 0 );
	await expect(
		page
			.getByRole( 'region', { name: 'Your notes for the review team' } )
			.getByText( note )
	).toBeVisible();
	/*
	 * How the campaign is delivered is in the side card, in the advertiser's
	 * words, rather than a panel of its own leading with "Line item" and
	 * "FLAT". Absence of the old panel is asserted so a second copy of the
	 * same facts cannot come back unnoticed.
	 */
	const campaignCard = page.getByRole( 'region', {
		name: 'Campaign',
		exact: true,
	} );
	await expect( campaignCard ).toBeVisible();
	await expect(
		campaignCard.getByText( 'Flat fee', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'region', { name: 'Delivery strategy' } )
	).toHaveCount( 0 );

	await page.reload();
	await expect(
		page
			.locator( '.aggr-pagehead' )
			.getByText( 'Submitted', { exact: true } )
	).toBeVisible();
	await expect(
		page.getByRole( 'button', { name: 'Submit for review' } )
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
		'https://www.example.com/rewritten'
	);
	await expect( page.getByLabel( 'Image description' ) ).toHaveCount( 0 );
	await expectOpenDialogA11y( page );
	await page.keyboard.press( 'Escape' );
	await expect(
		page.getByRole( 'dialog', { name: 'Update Article sidebar' } )
	).toBeHidden();
	await expect( update ).toBeFocused();
} );
