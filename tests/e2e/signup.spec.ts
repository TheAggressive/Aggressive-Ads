import { expect, test } from '@playwright/test';
import { expectSignInA11y } from './accessibility';
import { wp } from './wp-cli';

/**
 * The complete anonymous signup request through admin-post and back.
 */
test( 'a new advertiser requests an account without submitting a password', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/login/' );
	await page.getByRole( 'link', { name: 'Create an account' } ).click();

	await expect( page ).toHaveURL( /\/advertiser\/signup\/$/ );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Create an advertiser account' } )
	).toBeVisible();
	await expect( page.locator( 'input[type="password"]' ) ).toHaveCount( 0 );
	await expectSignInA11y( page );

	const organizationWidth = await page.getByLabel( 'Organization' ).evaluate(
		( input ) => input.getBoundingClientRect().width
	);
	const emailWidth = await page.getByLabel( 'Work email' ).evaluate(
		( input ) => input.getBoundingClientRect().width
	);

	expect( emailWidth ).toBe( organizationWidth );

	await page.getByLabel( 'First name' ).fill( 'E2E' );
	await page.getByLabel( 'Last name' ).fill( 'Advertiser' );
	await page.getByLabel( 'Organization' ).fill( 'E2E Signup Organization' );
	await expect( page.getByLabel( 'Organization' ) ).toHaveCSS( 'text-transform', 'uppercase' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-signup@example.test' );
	await page.getByRole( 'button', { name: 'Create account' } ).click();

	await expect( page ).toHaveURL( /\/advertiser\/signup\/\?laao_ads_signup=sent$/ );
	await expect(
		page.getByText( 'Check your email for a one-time link to set your password.' )
	).toBeVisible();
	await expect( page.getByRole( 'button', { name: 'Create account' } ) ).toHaveCount( 0 );

	const mail = JSON.parse(
		wp( 'option', 'get', 'laao_ads_dev_last_mail', '--format=json' ).trim()
	) as { message: string };
	const setupLink = mail.message.match(
		/http:\/\/localhost:9960\/advertiser\/set-password\/\?[^\s]+/
	)?.[ 0 ];

	expect( setupLink ).toBeTruthy();
	expect( setupLink ).not.toContain( 'wp-login.php' );

	await page.goto( setupLink! );
	await expect( page ).toHaveURL( /\/advertiser\/set-password\/\?key=/ );
	await expect( page ).not.toHaveURL( /wp-login\.php/ );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Choose your password' } )
	).toBeVisible();
	await expectSignInA11y( page );

	const password = 'A secure E2E passphrase!';
	await page.getByLabel( 'New password', { exact: true } ).fill( password );
	await page.getByLabel( 'Confirm new password' ).fill( password );
	await page.getByRole( 'button', { name: 'Set password' } ).click();

	await expect( page ).toHaveURL( /\/advertiser\/login\/\?laao_ads_login=password_set$/ );
	await expect( page.getByText( 'Your password is ready.' ) ).toBeVisible();
	await page.getByLabel( 'Work email' ).fill( 'e2e-signup@example.test' );
	await page.getByLabel( 'Password' ).fill( password );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/$/ );

	// A close organization spelling never creates or joins a second tenant.
	// It creates a powerless request that the existing owner must approve.
	await page.context().clearCookies();
	await page.goto( '/advertiser/signup/' );
	await page.getByLabel( 'First name' ).fill( 'Pending' );
	await page.getByLabel( 'Last name' ).fill( 'Member' );
	await page.getByLabel( 'Organization' ).fill( 'E2E Signup Organizaton' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-org-requester@example.test' );
	await page.getByRole( 'button', { name: 'Create account' } ).click();
	await expect( page ).toHaveURL( /laao_ads_signup=sent/ );

	const requesterId = wp(
		'user',
		'get',
		'e2e-org-requester@example.test',
		'--field=ID'
	).trim();
	wp( 'user', 'update', requesterId, '--user_pass=A secure pending passphrase!' );

	await page.goto( '/advertiser/login/' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-org-requester@example.test' );
	await page.getByLabel( 'Password' ).fill( 'A secure pending passphrase!' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await expect( page ).toHaveURL( /laao_ads_login=pending/ );
	await expect( page.getByText( 'still waiting for approval' ) ).toBeVisible();

	await page.context().clearCookies();
	await page.goto( '/advertiser/login/' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-signup@example.test' );
	await page.getByLabel( 'Password' ).fill( password );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await page.goto( '/advertiser/organization/' );
	await expect( page.getByText( 'e2e-org-requester@example.test' ) ).toBeVisible();
	await page.getByRole( 'button', { name: 'Approve' } ).click();
	await expect( page.getByText( 'Organization access approved.' ) ).toBeVisible();

	// An invitation is usable even when the browser currently has another
	// WordPress session, does not expose the organization field, and protects
	// its bearer query string with a no-referrer response policy.
	wp(
		'user',
		'create',
		'e2e-existing-invitee',
		'e2e-existing-invitee@example.test',
		'--role=subscriber',
		'--user_pass=ExistingInviteePassphrase!'
	);
	await page.getByLabel( 'Work email' ).fill( 'e2e-existing-invitee@example.test' );
	await page.getByRole( 'button', { name: 'Send invitation' } ).click();
	await expect( page.getByText( 'Invitation sent.' ) ).toBeVisible();

	const inviteMail = JSON.parse(
		wp( 'option', 'get', 'laao_ads_dev_last_mail', '--format=json' ).trim()
	) as { message: string };
	const inviteLink = inviteMail.message.match(
		/http:\/\/localhost:9960\/advertiser\/signup\/\?invite=[A-Za-z0-9_-]{43}/
	)?.[ 0 ];
	expect( inviteLink ).toBeTruthy();

	const inviteResponse = await page.goto( inviteLink! );
	expect( inviteResponse?.headers()[ 'referrer-policy' ] ).toBe( 'no-referrer' );
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Create an advertiser account' } )
	).toBeVisible();
	await expect( page.getByLabel( 'Organization' ) ).toHaveCount( 0 );
	await page.getByLabel( 'First name' ).fill( 'Existing' );
	await page.getByLabel( 'Last name' ).fill( 'Invitee' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-existing-invitee@example.test' );
	await page.getByRole( 'button', { name: 'Create account' } ).click();
	await expect( page ).toHaveURL( /laao_ads_signup=sent/ );

	await page.context().clearCookies();
	await page.goto( '/advertiser/login/' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-org-requester@example.test' );
	await page.getByLabel( 'Password' ).fill( 'A secure pending passphrase!' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/$/ );

	await page.context().clearCookies();
	await page.goto( '/advertiser/login/' );
	await page.getByLabel( 'Work email' ).fill( 'e2e-existing-invitee@example.test' );
	await page.getByLabel( 'Password' ).fill( 'ExistingInviteePassphrase!' );
	await page.getByRole( 'button', { name: 'Sign in' } ).click();
	await expect( page ).toHaveURL( /\/advertiser\/$/ );
} );
