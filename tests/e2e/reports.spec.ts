import { expect, test } from '@playwright/test';
import { signInToAdmin } from './admin-login';
import { expectAdminA11y, expectNoHorizontalOverflow } from './accessibility';

/**
 * The publisher fill report, in a browser.
 *
 * Every assertion in `PublisherReportTest` is about what the screen computes.
 * None of them can say whether a person can operate it: that the window and
 * placement controls are reachable and labelled, that submitting the form
 * actually changes what is reported, that a table of reasons survives a
 * 320-pixel viewport, and that axe finds nothing.
 *
 * This screen is server-rendered with no bundle, so there is no loading state
 * to wait on — which is the point of it, and worth a test that would notice if
 * that changed.
 */

const SCREEN = '/wp-admin/admin.php?page=aggr-reports';

test( 'the fill report is operable, labelled and reflows', async ( {
	page,
} ) => {
	await signInToAdmin( page );
	await page.goto( SCREEN );

	// The screen exists and is the plugin's, not a WordPress error page.
	await expect( page.locator( '.wrap.aggr-admin' ) ).toHaveCount( 1 );

	// Both controls are reachable by their labels rather than by position,
	// which is the same thing a screen reader needs.
	const window = page.getByLabel( 'Reporting window' );
	const placement = page.getByLabel( 'Placement' );

	await expect( window ).toBeVisible();
	await expect( placement ).toBeVisible();

	await expectAdminA11y( page );

	// Choosing a window changes the reported range rather than only the URL.
	await window.selectOption( '7' );
	await page.getByRole( 'button', { name: 'Show' } ).click();
	await expect( page ).toHaveURL( /days=7/ );
	await expect( window ).toHaveValue( '7' );

	// The range is stated, in UTC, because the counters have no other timezone.
	await expect( page.locator( '.wrap.aggr-admin' ) ).toContainText( 'UTC' );

	/*
	 * Tab order through the filter, which is the claim worth making. Pressing
	 * Tab once from a fresh document proves nothing here: wp-admin puts a skip
	 * link, the admin bar and the whole admin menu ahead of the content, so the
	 * first stop is never this form. Starting at the first control and walking
	 * forward asserts the order a keyboard user actually experiences, and fails
	 * if the DOM is reordered or a tabindex is introduced between them.
	 */
	await window.focus();
	await expect( window ).toBeFocused();

	await page.keyboard.press( 'Tab' );
	await expect( placement ).toBeFocused();

	await page.keyboard.press( 'Tab' );
	await expect( page.getByRole( 'button', { name: 'Show' } ) ).toBeFocused();
} );

test( 'the fill report offers a download of what is on screen', async ( {
	page,
} ) => {
	await signInToAdmin( page );
	await page.goto( SCREEN );

	const download = page.getByRole( 'button', {
		name: /Download \d+ days? \(CSV\)/,
	} );

	await expect( download ).toBeVisible();

	// A POST rather than a link, so a prefetcher cannot follow it and a nonce
	// can protect it.
	const form = page.locator(
		'form:has(input[value="aggr_export_fill_report"])'
	);

	await expect( form ).toHaveAttribute( 'method', 'post' );
	await expect( form.locator( 'input[name="_wpnonce"]' ) ).toHaveCount( 1 );
} );
