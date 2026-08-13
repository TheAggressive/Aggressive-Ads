import { expect, test } from '@playwright/test';
import { expectSignInA11y } from './accessibility';

/**
 * The portal's own sign-in form.
 *
 * The handler exits, so PHP can only assert what happens around it. This is the
 * one place the actual round trip is exercised: form, credentials, cookie, and
 * landing on the page the visitor originally asked for.
 */
test( 'a signed-out visitor signs in on the portal, not on wp-login', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/campaigns/' );

	// Never wp-login.php. An advertiser sent there has, as far as they can
	// tell, been thrown off the site they were using.
	await expect( page ).toHaveURL( /\/advertiser\/login\/\?redirect_to=/ );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Sign in' } ) ).toBeVisible();

	// The tab icon is ours, not the WordPress logo.
	await expect( page.locator( 'link[rel="icon"]' ) ).toHaveAttribute(
		'href',
		/aggressive-ads\/assets\/icon\.svg$/
	);

	await expectSignInA11y( page );

	await page.getByRole( 'link', { name: 'Forgotten your password?' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/forgot-password\/\?redirect_to=/ );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
	await expect( page.getByRole( 'heading', { level: 1, name: 'Reset your password' } ) ).toBeVisible();
	await page.getByRole( 'link', { name: 'Back to sign in' } ).click();

	const usernameWidth = await page.getByLabel( 'Work email' ).evaluate(
		( input ) => input.getBoundingClientRect().width
	);
	const passwordWidth = await page.getByLabel( 'Password' ).evaluate(
		( input ) => input.getBoundingClientRect().width
	);

	expect( passwordWidth ).toBe( usernameWidth );

	await page.getByLabel( 'Work email' ).fill( 'advertiser@example.test' );
	await page.getByLabel( 'Password' ).fill( 'nope-wrong-password' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();

	await expect(
		page.getByText( 'That email and password did not match. Please try again.' )
	).toBeVisible();

	// A wrong password must not reveal that the account exists, so the same
	// sentence has to answer a username nobody has.
	await page.getByLabel( 'Work email' ).fill( 'nobody@example.test' );
	await page.getByLabel( 'Password' ).fill( 'nope-wrong-password' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();

	await expect(
		page.getByText( 'That email and password did not match. Please try again.' )
	).toBeVisible();

	await page.getByLabel( 'Work email' ).fill( 'advertiser@example.test' );
	await page.getByLabel( 'Password' ).fill( 'advertiser' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();

	// Landed where they were originally going, not on a generic dashboard.
	await expect( page ).toHaveURL( /\/advertiser\/campaigns\/$/ );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Campaigns' } )
	).toBeVisible();

	// And the sign-in screen is no longer somewhere they can sit.
	await page.goto( '/advertiser/login/' );
	await expect( page ).toHaveURL( /\/advertiser\/$/ );
} );
