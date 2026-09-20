import { expect, test } from '@playwright/test';
import { expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';

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

test( 'two ads on one size rotate, and their shares move together', async ( {
	page,
} ) => {
	/*
	 * **The half that only a browser has.** The arithmetic is proven in
	 * milliseconds elsewhere; what is here is the control: releasing the
	 * slider saves without a page load, and the *other* ad's slider and
	 * sentence move with it, because the server patches every share on the
	 * placement. A page that updated only the one dragged would look right
	 * until the next reload, which is exactly what this asserts against.
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

	await page.getByLabel( 'Start date' ).fill( futureDate( 12 ) );
	await page
		.getByRole( 'button', { name: 'Continue to ads', exact: true } )
		.click();

	await page
		.getByLabel( 'Destination link for every ad' )
		.fill( 'https://www.example.com/rotate' );

	const leaderboard = page.getByRole( 'region', {
		name: 'Homepage leaderboard',
	} );

	await leaderboard.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'e2e-first.png',
		mimeType: 'image/png',
		buffer: solidPng( 728, 90 ),
	} );
	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();

	// A second ad on the same size is a rotation.
	await leaderboard
		.getByRole( 'link', { name: /Add a rotating creative/ } )
		.click();

	const dialog = page.getByRole( 'dialog' );

	await dialog.getByLabel( 'Ad creative file' ).setInputFiles( {
		name: 'e2e-second.png',
		mimeType: 'image/png',
		buffer: solidPng( 728, 90 ),
	} );
	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();

	// Said, not left to be inferred from two cards under one heading.
	await expect( leaderboard.getByText( '2 ads rotating' ) ).toBeVisible();
	await expect( leaderboard.locator( '.aggr-rotation__slice' ) ).toHaveCount(
		2
	);

	const shares = leaderboard.getByLabel( 'Share of this placement' );

	await expect( shares ).toHaveCount( 2 );
	await expect( shares.first() ).toHaveValue( '50' );
	await expect( shares.last() ).toHaveValue( '50' );
	await expectPortalA11y( page );

	// Released at 70, and the other ad is what is left of the placement.
	await shares.first().fill( '70' );

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Share saved' } )
	).toBeVisible();
	await expect( shares.last() ).toHaveValue( '30' );
	await expect(
		leaderboard.getByText( '70%', { exact: true } )
	).toBeVisible();
	await expect( leaderboard ).toContainText(
		'Set to 30% of this placement. The other ads share the remaining 70%.'
	);

	/*
	 * And what is set is not claimed as what is happening: neither ad is
	 * approved yet, so neither is delivering, and the card says the running
	 * ad would take the other's share rather than promising a split of
	 * traffic that is not flowing.
	 */
	await expect( leaderboard ).not.toContainText( 'of the time here' );

	// And it was the server that decided, so it survives the page.
	await page.reload();

	const saved = page
		.getByRole( 'region', { name: 'Homepage leaderboard' } )
		.getByLabel( 'Share of this placement' );

	await expect( saved.first() ).toHaveValue( '70' );
	await expect( saved.last() ).toHaveValue( '30' );
} );
