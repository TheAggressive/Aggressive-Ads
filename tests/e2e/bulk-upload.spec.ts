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

test( 'every file dropped at once lands on the size it matches', async ( {
	page,
} ) => {
	/*
	 * **Two files, one drop, both sizes filled.** Each is sent through its
	 * own size's form, so what this proves beyond the matching (which Jest
	 * covers) is that the server accepts the dropped file exactly as it
	 * accepts one chosen on the card, and that the page moves on once, after
	 * both, rather than after the first.
	 *
	 * The negative half comes first: a file of a size the package does not
	 * have is named with its dimensions and the sizes still wanted, and
	 * sends nothing.
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
	await expect(
		page.getByRole( 'heading', { level: 2, name: 'Add your ads' } )
	).toBeFocused();

	// One link for every ad, which each size's form carries into its upload.
	await page
		.getByLabel( 'Destination link for every ad' )
		.fill( 'https://www.example.com/drop' );

	const zone = page.locator( '[data-aggr-bulk][data-aggr-upload-ready]' );
	const input = zone.locator( 'input[data-aggr-bulk-input]' );

	await expect( zone ).toBeVisible();
	await expect(
		zone.getByRole( 'button', { name: 'Browse files' } )
	).toBeEnabled();

	let posts = 0;
	page.on( 'request', ( request ) => {
		/*
		 * Matched on the request's shape, not its body: Playwright hands back
		 * no body for a multipart post carrying a file, so looking for the
		 * action name in it counted nothing and passed the negative below over
		 * a check that could never fire. Nothing else on this step posts a
		 * multipart form to admin-post.php once the link is in.
		 */
		if (
			'POST' === request.method() &&
			request.url().includes( 'admin-post.php' ) &&
			( request.headers()[ 'content-type' ] ?? '' ).startsWith(
				'multipart/form-data'
			)
		) {
			posts++;
		}
	} );

	await input.setInputFiles( {
		name: 'e2e-skyscraper.png',
		mimeType: 'image/png',
		buffer: solidPng( 160, 600 ),
	} );

	const wrong = zone
		.locator( '.aggr-dropzone__item' )
		.filter( { hasText: 'e2e-skyscraper.png' } );

	await expect( wrong ).toContainText( 'This file is 160 × 600' );
	await expect( wrong ).toContainText( '728 × 90' );
	await expect( wrong ).toContainText( '300 × 250' );
	expect( posts, 'A file that matched no size was sent.' ).toBe( 0 );
	await expectPortalA11y( page );

	await input.setInputFiles( [
		{
			name: 'e2e-drop-leaderboard.png',
			mimeType: 'image/png',
			buffer: solidPng( 728, 90 ),
		},
		{
			name: 'e2e-drop-sidebar.png',
			mimeType: 'image/png',
			buffer: solidPng( 300, 250 ),
		},
	] );

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Creative uploaded' } )
	).toBeVisible();
	await expect( page.locator( '.aggr-ads-progress' ) ).toContainText(
		'2 of 2 sizes ready'
	);
	expect( posts, 'Each file is one post through its own form.' ).toBe( 2 );

	for ( const [ region, file ] of [
		[ 'Homepage leaderboard', 'e2e-drop-leaderboard.png' ],
		[ 'Article sidebar', 'e2e-drop-sidebar.png' ],
	] ) {
		const card = page.getByRole( 'region', { name: region } );

		await expect( card ).toContainText( file );
		await expect(
			card.getByText( 'https://www.example.com/drop', { exact: true } )
		).toBeVisible();
	}

	// Nothing left to drop onto, so the zone is gone.
	await expect( page.locator( '[data-aggr-bulk]' ) ).toHaveCount( 0 );
} );
