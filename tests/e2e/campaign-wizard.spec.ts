import { expect, test } from '@playwright/test';
import {
	expectDialogKeyboard,
	expectOpenDialogA11y,
	expectPortalA11y,
} from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';
import { wp } from './wp-cli';

test( 'advertiser completes and submits the accessible six-step wizard', async ( {
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
		page.getByRole( 'heading', { level: 2, name: 'Campaign details' } )
	).toBeFocused();

	const title = `E2E browser campaign ${ Date.now() }`;
	await page.getByLabel( 'Campaign name' ).fill( title );
	await page
		.getByLabel( 'Notes for the review team' )
		.fill( 'Browser-tested submission.' );
	await page.getByRole( 'button', { name: 'Save and continue' } ).click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Choose a package' } )
	).toBeFocused();
	await expect(
		page.getByRole( 'radio', { name: /Launch bundle/ } )
	).toBeChecked();
	await expectPortalA11y( page );
	await page.getByRole( 'radio', { name: /Focused sidebar/ } ).check();
	await page.getByRole( 'button', { name: 'Save package' } ).click();

	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Upload creative' } )
	).toBeFocused();
	const upload = page.getByRole( 'region', { name: 'Article sidebar' } );
	await upload.getByLabel( 'Image file' ).setInputFiles( {
		name: 'e2e-sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );
	await upload
		.getByLabel( 'Destination URL' )
		.fill( 'https://www.example.com/exhibition' );
	await upload.getByRole( 'button', { name: 'Upload creative' } ).click();

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
	await page
		.getByRole( 'button', { name: 'Save and continue to review' } )
		.click();

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
	await expect( page.getByLabel( 'Replacement image' ) ).toBeVisible();
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
