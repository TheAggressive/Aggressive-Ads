import { expect, test } from '@playwright/test';
import { expectDialogKeyboard, expectPortalA11y } from '../accessibility';
import { signIn } from '../sign-in-helper';
import { solidPng } from '../png';

test( 'the shared creative dialog works in WebKit', async ( { page } ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Create campaign' } ).click();

	/*
	 * Wait for the step before typing into it. Creating a draft redirects, and
	 * a name typed into the form that is on its way out is discarded with it —
	 * which then reads as "choosing a package did nothing", because an unnamed
	 * campaign deliberately does not advance.
	 *
	 * The module marks the form when it attaches, and that is the wait worth
	 * making: the button's label cannot serve, because CSS now picks it
	 * before paint and it is the same whether this code has run or not.
	 */
	await expect(
		page.getByRole( 'heading', {
			level: 2,
			name: 'Name your campaign and choose a package',
		} )
	).toBeVisible();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	await page
		.getByLabel( 'Campaign name' )
		.fill( `E2E browser campaign WebKit ${ Date.now() }` );

	// Name and package are one step now, and choosing the package finishes it.
	await page.getByRole( 'radio', { name: /Focused sidebar/ } ).check();

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
	await expectDialogKeyboard(
		page,
		page.getByRole( 'link', {
			name: 'Advertisement linking to example.com',
		} ),
		'Preview Article sidebar'
	);
} );
