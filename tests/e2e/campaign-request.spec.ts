import { expect, test } from '@playwright/test';
import { expectDialogKeyboard, expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { wpPluginFile } from './wp-cli';

/*
 * Pause or cancel, opened from the campaign page.
 *
 * The menu link and the dialog are easy to prove apart and still ship
 * broken together: the link hashed to `#aggr-request-…` while the ad cards
 * had already dropped the overlay, so the address changed and nothing
 * opened. Opening it is the check. Focus, the trap and the return are the
 * same contract as every other dialog.
 */

test( 'pause or cancel opens from More actions and keeps focus inside', async ( {
	page,
} ) => {
	const seed = JSON.parse(
		wpPluginFile( 'tests/e2e/seed-request-campaign.php' ).trim()
	) as { campaign: number };

	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await page.goto( `/advertiser/campaigns/${ seed.campaign }/` );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'E2E request flight' } )
	).toBeVisible();
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'More actions' } ).click();

	await expectDialogKeyboard(
		page,
		page.getByRole( 'link', { name: 'Pause or cancel' } ),
		'Pause or cancel'
	);
} );
