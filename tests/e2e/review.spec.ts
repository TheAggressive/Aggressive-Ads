import { expect, test } from '@playwright/test';
import { expectAdminA11y } from './accessibility';

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
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'admin' );
	await page.locator( '#wp-submit' ).click();

	await page.goto( '/wp-admin/admin.php?page=aggr-review' );

	// Mounted, before anything else. Without this the locators below would each
	// time out and the failure would read "no such button" rather than "the
	// bundle never ran".
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Campaign review' } )
	).toBeVisible();
	await expect(
		page.locator( '.aggr-review-table tbody tr' )
	).not.toHaveCount( 0 );
	await expectAdminA11y( page );

	// Into one campaign, and the URL says which.
	const first = page.locator( '.aggr-review-table tbody tr' ).first();
	const title = ( await first.locator( 'td' ).first().innerText() ).trim();

	await first.getByRole( 'button', { name: title } ).click();

	await expect(
		page.getByRole( 'heading', { level: 1, name: title } )
	).toBeVisible();
	await expect( page ).toHaveURL( /campaign=\d+/ );
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
