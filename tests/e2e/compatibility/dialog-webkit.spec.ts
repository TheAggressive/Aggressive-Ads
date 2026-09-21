import { expect, test } from '@playwright/test';
import {
	expectDialogKeyboard,
	expectPortalA11y,
	sandboxRefusedInjection,
} from '../accessibility';
import { signIn } from '../sign-in-helper';
import { solidPng } from '../png';

test( 'the shared creative dialog works in WebKit', async ( { page } ) => {
	/*
	 * **WebKit's console, asserted rather than glanced at.**
	 *
	 * This spec ran green for weeks over `Fetch API cannot load … due to
	 * access control checks`, thrown on every run by an autosave the wizard
	 * re-armed after cancelling it, fired into a page that was already
	 * navigating. It read as CORS and was not: the origins match and the same
	 * request from the same page returns a real status. Nothing but a browser
	 * showed it, and nothing here was looking.
	 */
	const pageErrors: string[] = [];

	page.on( 'pageerror', ( error ) => pageErrors.push( error.message ) );
	page.on( 'console', ( message ) => {
		if (
			'error' === message.type() &&
			! sandboxRefusedInjection.test( message.text() )
		) {
			pageErrors.push( message.text() );
		}
	} );
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await expectPortalA11y( page );

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();

	/*
	 * Wait for the step before touching it. Creating a draft redirects, and a
	 * date entered into the form that is on its way out is discarded with it.
	 *
	 * The module marks the form when it attaches, and that is the wait worth
	 * making: the button's label cannot serve, because CSS picks it before
	 * paint and it is the same whether this code has run or not.
	 */
	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Choose a package and dates',
		} )
	).toBeVisible();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	// Package and dates are one step, and Continue is the only way on.
	await page.getByRole( 'radio', { name: /Focused sidebar/ } ).check();
	await page
		.getByLabel( 'Start date' )
		.fill(
			new Date( Date.now() + 10 * 24 * 60 * 60 * 1000 )
				.toISOString()
				.slice( 0, 10 )
		);
	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();

	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );

	/*
	 * Only the upload module hides this button, so its absence is the proof
	 * that the module is attached. Choosing a file before then raises `change`
	 * with nobody listening: the file sits in the input, nothing validates it,
	 * and the automatic upload never has its first half.
	 */
	await expect(
		upload.getByRole( 'button', { name: 'Upload creative' } )
	).toBeHidden();

	await upload.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'webkit-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );
	/*
	 * Wait for the file to be accepted before completing the destination.
	 *
	 * Checking a creative reads its pixel dimensions, which means decoding the
	 * image — asynchronous, and on a slower run not finished by the time the
	 * next line types. Committing the destination before then asks to send a
	 * file the page has not accepted yet, and nothing goes. The announcement is
	 * the signal that it has.
	 */
	await expect(
		upload.getByRole( 'status' ).filter( { hasText: 'File selected' } )
	).toBeAttached();

	// Completing the destination and leaving it sends the upload itself.
	const destination = upload.getByLabel( 'Destination URL' );
	await destination.fill( 'https://www.example.com/webkit' );
	await destination.blur();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();
	/*
	 * The preview trigger is a named control in the row beneath the artwork
	 * now, not the image itself wrapped in a link. Text over a creative is a
	 * bet on what the advertiser uploaded, and a light banner loses it.
	 */
	await expectDialogKeyboard(
		page,
		page.getByRole( 'link', { name: 'Preview' } ),
		'Preview Article sidebar'
	);

	// Polled, because the save that used to throw was on a timer: asserting
	// once at the end of a fast run would have passed over it.
	await expect.poll( () => pageErrors, { timeout: 5000 } ).toEqual( [] );
} );
