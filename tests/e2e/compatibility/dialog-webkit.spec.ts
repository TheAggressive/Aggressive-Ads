import { expect, test } from '@playwright/test';
import { expectDialogKeyboard, expectPortalA11y } from '../accessibility';
import { signIn } from '../sign-in-helper';
import { solidPng } from '../png';

test( 'the shared creative dialog works in WebKit', async ( { page } ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Create campaign' } ).click();
	await page
		.getByLabel( 'Campaign name' )
		.fill( `E2E browser campaign WebKit ${ Date.now() }` );
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await page.getByRole( 'radio', { name: /Focused sidebar/ } ).check();
	await page.getByRole( 'button', { name: 'Save package' } ).click();

	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );
	await upload.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'webkit-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );
	await upload
		.getByLabel( 'Destination URL' )
		.fill( 'https://www.example.com/webkit' );
	await upload.getByRole( 'button', { name: 'Upload creative' } ).click();

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
