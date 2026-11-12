import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';

// Preferences never send immediately; every option is saved through the signed
// Account form, and the test returns the fixture account to Off.
test( 'a member controls personal report frequency and can stop delivery', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await page.goto( '/advertiser/account/' );
	const frequency = page.getByLabel( 'Report frequency' );
	const save = page.getByRole( 'button', {
		name: 'Save report preferences',
	} );
	await expect( frequency ).toHaveValue( 'off' );
	try {
		for ( const cadence of [ 'weekly', 'monthly' ] ) {
			await frequency.selectOption( cadence );
			await save.click();
			await expect( page ).toHaveURL( /report_notice=saved/ );
			await expect(
				page.getByText( 'Your report email preferences were saved.' )
			).toBeVisible();
			await expect( frequency ).toHaveValue( cadence );
			await expect(
				page.getByText( /^Next scheduled run:/ )
			).toBeVisible();
		}
		await expectPortalA11y( page );
		await page.setViewportSize( { width: 320, height: 740 } );
		await expectNoHorizontalOverflow( page );
	} finally {
		await frequency.selectOption( 'off' );
		await save.click();
		await expect( frequency ).toHaveValue( 'off' );
		await expect( page.getByText( /^Next scheduled run:/ ) ).toHaveCount(
			0
		);
	}
} );
