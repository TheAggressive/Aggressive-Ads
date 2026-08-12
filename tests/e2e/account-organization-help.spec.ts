import { expect, test } from '@playwright/test';
import { expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';

/**
 * The three screens that finish the portal's navigation.
 *
 * Account carries the only user-record write an advertiser can reach at all:
 * Admin_Guard sends portal users away from wp-admin, so /wp-admin/profile.php
 * is closed to them. That makes this the one browser path where a real person
 * changes something about their own login, and it is worth driving as one.
 */
test( 'advertiser reads and edits their account, organization and help', async ( { page } ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await expect( page ).toHaveURL( /\/advertiser\/?$/ );

	// Reached by the rail, not by typing a URL: the navigation is part of what
	// is being asserted.
	await page.getByRole( 'link', { name: 'Organization' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/organization\/$/ );
	await expect( page.getByRole( 'heading', { level: 1 } ) ).toBeVisible();
	await expect( page.getByRole( 'columnheader', { name: 'Role' } ) ).toBeVisible();
	await expect( page.getByRole( 'cell', { name: 'Owner' } ) ).toBeVisible();
	await expect(
		page.getByRole( 'link', { name: 'Organization' } )
	).toHaveAttribute( 'aria-current', 'page' );
	await expectPortalA11y( page );

	await page.getByRole( 'link', { name: 'Help' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/help\/$/ );
	await expect(
		page.getByRole( 'heading', { level: 2, name: 'What each status means' } )
	).toBeVisible();

	// Derived from the upload rules rather than written into the page, so this
	// also pins that the derivation still produces something readable.
	await expect( page.getByText( /Images only: JPEG, PNG, GIF, WebP/ ) ).toBeVisible();
	await expectPortalA11y( page );

	await page.getByRole( 'link', { name: 'Account' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/account\/$/ );
	await expectPortalA11y( page );

	const displayName = page.getByLabel( 'Name to display' );
	await expect( displayName ).toBeVisible();
	await displayName.fill( 'Dana from Bright Angle' );
	await page.getByRole( 'button', { name: 'Save details' } ).click();

	await expect( page ).toHaveURL( /laao_ads_notice=saved/ );
	await expect( page.getByText( 'Your details were saved.' ) ).toBeVisible();
	await expect( page.getByLabel( 'Name to display' ) ).toHaveValue(
		'Dana from Bright Angle'
	);

	// The saved name reaches the rest of the chrome, not just this form.
	await page.getByRole( 'link', { name: 'Dashboard' } ).click();
	await expect(
		page.getByRole( 'heading', {
			level: 1,
			name: /Welcome back, Dana from Bright Angle/,
		} )
	).toBeVisible();
} );

/**
 * The username and email are shown but not editable.
 *
 * Changing an email address is an account-takeover primitive: core's flow mails
 * a signed confirmation to the *new* address and completes on profile.php,
 * which these users cannot reach. Until this plugin owns that token properly,
 * the field must not be posted at all — a disabled input that still submits is
 * the version of this that looks safe and is not.
 */
test( 'the account screen exposes no editable email or role field', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );

	await page.goto( '/advertiser/account/' );

	const names = await page
		.locator( 'form.laao-ads-form input[name], form.laao-ads-form select[name]' )
		.evaluateAll( ( nodes ) =>
			nodes.map( ( node ) => node.getAttribute( 'name' ) )
		);

	expect( names ).not.toContain( 'user_email' );
	expect( names ).not.toContain( 'email' );
	expect( names ).not.toContain( 'role' );
	expect( names ).not.toContain( 'user_pass' );
	expect( names ).toContain( 'display_name' );
} );
