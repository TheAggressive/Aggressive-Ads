import { expect, test } from '@playwright/test';
import { expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';

test( "on a phone the step's action is pinned, and the review sketch marks gaps", async ( {
	page,
} ) => {
	/*
	 * **The action is where the thumb is, and it says what is left.** On a
	 * phone the step's button sat at the end of the form, past every card. It
	 * is pinned to the bottom of the screen now, and when a step cannot move
	 * on it carries the reason beside the button.
	 *
	 * Three things have to hold together, and each is a way this could be
	 * green while wrong. Pinned: the page is made taller than the screen first,
	 * or a bar that merely sat under a short page would pass. One action: the
	 * step's own button and the summary's are hidden rather than removed, so
	 * the count of buttons by name is what proves the bar did not add a second.
	 * Locked: the reason is read from the bar, not from the summary card that
	 * said it all along.
	 */
	await page.setViewportSize( { width: 390, height: 480 } );
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	const bar = page.getByRole( 'region', { name: 'Continue', exact: true } );

	await expect( bar ).toBeVisible();

	const tall = await page.evaluate(
		() => document.documentElement.scrollHeight > window.innerHeight + 200
	);

	expect( tall, 'The page must be longer than the screen.' ).toBe( true );

	/*
	 * To the top of the wizard, not the top of the page: a phone's rail and
	 * header are taller than this screen, and a sticky bar only travels inside
	 * its own content, so it is on screen from the moment the wizard is.
	 */
	await page
		.locator( '.aggr-wizard' )
		.evaluate( ( el ) => el.scrollIntoView() );

	const box = await bar.boundingBox();

	expect( Math.round( ( box?.y ?? 0 ) + ( box?.height ?? 0 ) ) ).toBe( 480 );

	await expect(
		page.getByRole( 'button', { name: 'Continue to ads', exact: true } )
	).toHaveCount( 1 );
	await expect(
		bar.getByRole( 'button', { name: 'Continue to ads', exact: true } )
	).toBeVisible();
	await expectPortalA11y( page );

	// A start date is the one thing a new draft lacks, and the server refuses
	// to leave this step without it. Wait for the autosave so the form's
	// revision is current when the bar's button submits it.
	const saved = page.waitForResponse(
		( response ) =>
			'PATCH' === response.request().method() &&
			response.url().includes( '/aggr/v1/campaigns/' )
	);

	await page
		.getByLabel( 'Start date' )
		.fill(
			new Date( Date.now() + 10 * 864e5 ).toISOString().slice( 0, 10 )
		);
	expect( ( await saved ).ok() ).toBe( true );

	await bar
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();
	await expect( page ).toHaveURL( /step=creative/, { timeout: 15_000 } );

	// A new campaign has no files yet, so this step is locked.
	const locked = bar.getByRole( 'button', {
		name: 'Continue to review',
		exact: true,
	} );

	await expect( locked ).toBeVisible();
	await expect( locked ).toBeDisabled();
	await expect( bar ).toContainText( /\d+ sizes? still needs? a file/ );
	await expect(
		page.getByRole( 'button', { name: 'Continue to review', exact: true } )
	).toHaveCount( 1 );
	await expectPortalA11y( page );

	/*
	 * The review step's sketch marks every size still without a file, and the
	 * width switch works on a phone too. Reached by its link: a draft with no
	 * files can be looked at, just not submitted.
	 */
	await page.goto( page.url().replace( 'step=creative', 'step=review' ) );

	const missing = page.locator( '.aggr-pagepreview__slot--empty' );

	expect( await missing.count() ).toBeGreaterThan( 0 );
	await expect( missing.first() ).toContainText( 'needs a file' );
	await page
		.getByRole( 'radio', { name: 'Mobile' } )
		.check( { force: true } );
	await expect( page.locator( '.aggr-pagepreview__body' ) ).toHaveCSS(
		'grid-template-columns',
		/^[\d.]+px$/
	);
} );
