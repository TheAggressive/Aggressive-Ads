import { test } from '@playwright/test';
import { expectAdminA11y, expectNoHorizontalOverflow } from '../accessibility';
import { signInToAdmin } from '../admin-login';

/**
 * The staff reporting screen at 320 CSS pixels.
 *
 * WCAG 1.4.10, and the reason it needs its own assertion rather than a glance:
 * this screen is a filter row plus a data table, and a table is the control
 * most likely to force two-dimensional scrolling on a narrow viewport. It is
 * also the first admin screen here with reflow coverage at all.
 */
test( 'the fill report reflows at 320 CSS pixels', async ( { page } ) => {
	await signInToAdmin( page );
	await page.goto( '/wp-admin/admin.php?page=aggr-reports' );

	await expectAdminA11y( page );
	await expectNoHorizontalOverflow( page );
} );
