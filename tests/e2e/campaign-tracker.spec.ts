import { expect, test } from '@playwright/test';
import { expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';

test( 'a click tracker saves beside an autosaved destination and a refusal says what to fix', async ( {
	page,
} ) => {
	/*
	 * **Two saves on one page, and neither may undo or refuse the other.** The
	 * destination saves itself as it is typed and the tracker has its own
	 * button; both carry the campaign's revision. The page's script keeps every
	 * copy of it current, so the order below — destination first, tracker
	 * straight after — is the one that would come back as "this campaign
	 * changed in another window" if the tracker's form held a stale one.
	 */
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await page
		.getByRole( 'main' )
		.getByRole( 'button', { name: 'New campaign' } )
		.click();
	await expect(
		page.locator( 'form[data-aggr-autosave][data-aggr-autosave-ready]' )
	).toBeAttached();

	const patched = () =>
		page.waitForResponse(
			( response ) =>
				'PATCH' === response.request().method() &&
				response.url().includes( '/aggr/v1/campaigns/' )
		);

	let saved = patched();
	await page
		.getByLabel( 'Start date' )
		.fill(
			new Date( Date.now() + 10 * 864e5 ).toISOString().slice( 0, 10 )
		);
	expect( ( await saved ).ok() ).toBe( true );

	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();
	await expect( page ).toHaveURL( /step=creative/, { timeout: 15_000 } );

	saved = patched();
	await page
		.getByLabel( 'Destination link for every ad' )
		.fill( 'https://example.com/offer' );
	expect( ( await saved ).ok() ).toBe( true );

	// Folded: most campaigns have no tracker, and the field is not in the way.
	const fold = page.locator( 'details.aggr-tracker' );

	await expect( fold ).not.toHaveAttribute( 'open', '' );
	await fold.getByText( 'Use an ad-server click tracker (optional)' ).click();

	const tracker = page.getByLabel( 'Tracking link' );

	await tracker.fill( 'https://track.example.com/c?no-landing-page' );
	await page.getByRole( 'button', { name: 'Save tracker' } ).click();

	// Refused: the page says what to fix, the fold is open, the field is marked.
	await expect( page ).toHaveURL( /step=creative/ );
	await expect( page.getByRole( 'alert' ) ).toContainText( '{lpurl}' );
	await expect( page.getByLabel( 'Tracking link' ) ).toHaveAttribute(
		'aria-invalid',
		'true'
	);
	await expect( page.getByLabel( 'Tracking link' ) ).toHaveValue( '' );
	await expectPortalA11y( page );

	await page
		.getByLabel( 'Tracking link' )
		.fill( 'https://track.example.com/c?u={lpurl}' );
	await page.getByRole( 'button', { name: 'Save tracker' } ).click();
	await expect( page.getByRole( 'status' ).first() ).toBeVisible();

	// Read back after a reload, not from the page that was just submitted.
	await page.reload();
	await expect( page.getByLabel( 'Tracking link' ) ).toHaveValue(
		'https://track.example.com/c?u={lpurl}'
	);
	await expect(
		page.getByLabel( 'Destination link for every ad' )
	).toHaveValue( 'https://example.com/offer' );
	await expect( page.locator( 'details.aggr-tracker' ) ).toHaveAttribute(
		'open',
		''
	);
	await expectPortalA11y( page );
} );
