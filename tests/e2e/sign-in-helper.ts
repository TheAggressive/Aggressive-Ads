import { expect, type Page } from '@playwright/test';
import { wpPluginFile } from './wp-cli';

/**
 * Signs a person in through the portal's own form.
 *
 * Every spec used to fill wp-login.php's #user_login and #user_pass directly,
 * which meant replacing the login screen broke four specs that were not about
 * logging in. The form now has one description, here.
 */
export async function signIn(
	page: Page,
	login: string,
	password: string
): Promise< void > {
	// Test isolation, not a raised limit: see tests/e2e/reset-rate-limits.php.
	wpPluginFile( 'tests/e2e/reset-rate-limits.php' );

	await page.getByLabel( 'Work email' ).fill( login );
	await page.getByLabel( 'Password' ).fill( password );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await expect( page ).not.toHaveURL( /\/advertiser\/login\// );
}
