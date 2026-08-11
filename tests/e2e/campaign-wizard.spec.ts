import { expect, test } from '@playwright/test';
import { expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';

test( 'advertiser completes and submits the accessible six-step wizard', async ( { page } ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser', 'advertiser' );

	await expect( page ).toHaveURL( /\/advertiser\/?$/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /wp-theme-twentytwentyfive/ );
	await expect( page.locator( 'body' ) ).toHaveClass( /laao-ads-portal/ );
	await expect( page.locator( '.wp-site-blocks' ) ).toHaveCount( 0 );
	await expect( page.getByRole( 'heading', { level: 1, name: /Welcome back/ } ) ).toBeVisible();

	const portalCssLoaded = await page.evaluate( () =>
		Array.from( document.styleSheets ).some( ( sheet ) =>
			sheet.href?.includes( '/plugins/laao-advertiser-portal/assets/portal.css' )
		)
	);
	expect( portalCssLoaded ).toBe( true );

	await page.evaluate( () => ( document.activeElement as HTMLElement | null )?.blur() );
	await page.keyboard.press( 'Tab' );
	await expect( page.getByRole( 'link', { name: 'Skip to main content' } ) ).toBeFocused();
	await page.keyboard.press( 'Enter' );
	await expect( page.locator( '#laao-ads-main' ) ).toBeFocused();
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Create campaign' } ).click();
	await expect( page.getByRole( 'heading', { level: 2, name: 'Campaign details' } ) ).toBeVisible();

	const title = `E2E browser campaign ${ Date.now() }`;
	await page.getByLabel( 'Campaign name' ).fill( title );
	await page.getByLabel( 'Notes for the review team' ).fill( 'Browser-tested submission.' );
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Choose a package' } ) ).toBeVisible();
	await page.getByRole( 'radio', { name: /Focused sidebar/ } ).check();
	await page.getByRole( 'button', { name: 'Save package' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Upload creative' } ) ).toBeVisible();
	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );
	await upload.getByLabel( 'Image file' ).setInputFiles( {
		name: 'e2e-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );
	await upload.getByLabel( 'Destination URL' ).fill( 'https://www.example.com/exhibition' );
	await upload.getByLabel( 'Image description' ).fill( 'Visitors viewing a gallery exhibition' );
	await upload.getByRole( 'button', { name: 'Upload creative' } ).click();

	await expect( page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } ) ).toBeVisible();
	const preview = page.getByRole( 'img', { name: 'Visitors viewing a gallery exhibition' } );
	await expect( preview ).toBeVisible();
	await expect.poll( () => preview.evaluate( ( image: HTMLImageElement ) => image.naturalWidth ) ).toBe( 300 );
	await page.getByRole( 'link', { name: 'Continue to schedule' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Confirm destinations and schedule' } ) ).toBeVisible();
	const destinations = page.locator( '#laao-ads-destinations' );
	await expect( destinations.getByText( 'https://www.example.com/exhibition', { exact: true } ) ).toBeVisible();
	await expect( page.locator( 'a[href="https://www.example.com/exhibition"]' ) ).toHaveCount( 0 );

	const start = new Date( Date.now() + 10 * 24 * 60 * 60 * 1000 ).toISOString().slice( 0, 10 );
	await page.getByLabel( 'Start date' ).fill( start );
	await page.getByRole( 'button', { name: 'Save and continue to review' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Review your campaign' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { level: 3, name: 'Ready for submission' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { level: 1, name: title, exact: true } ) ).toBeVisible();
	await expectPortalA11y( page );
	await page.getByRole( 'link', { name: 'Continue to submit' } ).click();

	await expect( page.getByRole( 'heading', { level: 2, name: 'Submit your campaign' } ) ).toBeVisible();
	await expect( page.getByRole( 'heading', { level: 3, name: 'Send this campaign to the review team?' } ) ).toBeVisible();
	await expectPortalA11y( page );
	await page.getByRole( 'button', { name: 'Submit campaign for review' } ).click();

	await expect( page.getByRole( 'status' ).filter( { hasText: 'Campaign submitted' } ) ).toBeVisible();
	await expect( page.getByText( 'Submitted', { exact: true } ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Submit campaign for review' } ) ).toHaveCount( 0 );
	await expect( page.getByLabel( 'Campaign name' ) ).toHaveCount( 0 );

	await page.reload();
	await expect( page.getByText( 'Submitted', { exact: true } ) ).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Submit campaign for review' } ) ).toHaveCount( 0 );
} );
