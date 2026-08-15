import { test } from '@playwright/test';
import {
	expectNoHorizontalOverflow,
	expectPortalA11y,
	expectSignInA11y,
} from '../accessibility';
import { signIn } from '../sign-in-helper';

test( 'sign-in and portal content reflow at 320 CSS pixels', async ( {
	page,
} ) => {
	await page.goto( '/advertiser/login/' );
	await expectSignInA11y( page );
	await expectNoHorizontalOverflow( page );

	await signIn( page, 'advertiser@example.test', 'advertiser' );
	await page.goto( '/advertiser/help/' );
	await expectPortalA11y( page );
	await expectNoHorizontalOverflow( page );
} );
