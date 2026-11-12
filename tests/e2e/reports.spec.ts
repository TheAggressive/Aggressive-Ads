import { expect, test } from '@playwright/test';
import { signInToAdmin } from './admin-login';
import {
	expectAdminA11y,
	expectNoHorizontalOverflow,
	expectPortalA11y,
} from './accessibility';
import { signIn } from './sign-in-helper';
import { wp, wpPluginFile } from './wp-cli';

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

test( 'advertiser local days and UTC exports keep the same selected dates', async ( {
	page,
} ) => {
	const originalZone = wp( 'option', 'get', 'timezone_string' ).trim();

	try {
		wp( 'option', 'update', 'timezone_string', 'Asia/Kolkata' );
		await page.goto( '/advertiser/' );
		await signIn( page, 'advertiser@example.test', 'advertiser' );
		await page.goto( '/advertiser/?from=2026-08-01&to=2026-08-07' );
		await expect( page.locator( '#aggr-delivery-window' ) ).toContainText(
			'Asia/Kolkata'
		);
		await page.getByRole( 'link', { name: 'View UTC days' } ).click();
		await expect( page ).toHaveURL( /from=2026-08-01/ );
		await expect( page ).toHaveURL( /to=2026-08-07/ );
		await expect( page.locator( '#aggr-delivery-window' ) ).toContainText(
			'(UTC)'
		);
		const exportForm = page.locator(
			'form:has(input[value="aggr_export_report"])'
		);
		await expect( exportForm.locator( 'input[name="tz"]' ) ).toHaveValue(
			'utc'
		);
		await expect( exportForm.locator( 'input[name="from"]' ) ).toHaveValue(
			'2026-08-01'
		);
		await page.getByRole( 'link', { name: 'View site days' } ).click();
		await expect( page.locator( '#aggr-delivery-window' ) ).toContainText(
			'Asia/Kolkata'
		);
		await expect( exportForm.locator( 'input[name="tz"]' ) ).toHaveCount(
			0
		);
		await expectPortalA11y( page );
	} finally {
		wp( 'option', 'update', 'timezone_string', originalZone );
	}
} );

test( 'oversized advertiser CSV refuses before attachment headers and recovers', async ( {
	page,
} ) => {
	const campaign = Number(
		wpPluginFile( 'tests/e2e/seed-report-export.php' ).trim()
	);
	expect( campaign ).toBeGreaterThan( 0 );
	try {
		await page.goto( '/advertiser/' );
		await signIn( page, 'advertiser@example.test', 'advertiser' );
		await page.goto( '/advertiser/?from=2026-08-01&to=2026-08-31&tz=utc' );
		const nonce = await page
			.locator(
				'form:has(input[value="aggr_export_report"]) input[name="_wpnonce"]'
			)
			.inputValue();
		const form = {
			action: 'aggr_export_report',
			_wpnonce: nonce,
			campaign_id: String( campaign ),
			from: '2026-08-01',
			to: '2026-08-31',
			tz: 'utc',
		};
		const refusal = await page.request.post( '/wp-admin/admin-post.php', {
			form,
		} );
		expect( refusal.status() ).toBe( 422 );
		expect( refusal.headers()[ 'content-disposition' ] ).toBeUndefined();
		expect( refusal.headers()[ 'content-type' ] ).not.toContain(
			'text/csv'
		);
		expect( await refusal.text() ).toContain(
			'A campaign name is too long to export'
		);
		wp(
			'post',
			'update',
			String( campaign ),
			'--post_title=E2E export recovery'
		);
		const download = await page.request.post( '/wp-admin/admin-post.php', {
			form,
		} );
		expect( download.status() ).toBe( 200 );
		expect( download.headers()[ 'content-type' ] ).toContain( 'text/csv' );
		expect( download.headers()[ 'content-disposition' ] ).toContain(
			'attachment;'
		);
		const csv = await download.text();
		expect( csv ).toContain( 'E2E export recovery' );
		expect( csv ).toContain( '2026-08-12' );
	} finally {
		wp(
			'eval',
			`global $wpdb; foreach (array('aggr_rollups', 'aggr_report_buckets') as $table) { $wpdb->delete($wpdb->prefix . $table, array('campaign_id' => ${ campaign })); } wp_delete_post(${ campaign }, true);`
		);
	}
} );
