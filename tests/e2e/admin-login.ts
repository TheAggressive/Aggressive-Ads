import { expect, type Page } from '@playwright/test';

/**
 * Signing in to wp-admin, in the one place that knows how to do it safely.
 *
 * The advertiser portal's own form has its own helper in `sign-in-helper.ts`
 * and shares nothing with this: different markup, different failure modes, and
 * a person who is not staff cannot reach wp-admin at all.
 *
 * Six copies of this lived in four spec files, and all six could lose their
 * password to the same race. It cost three separate red release lanes before
 * the trace explained it, so the sequence below is deliberate throughout.
 *
 * **What went wrong.** `wp-login.php` schedules `wp_attempt_focus()` 200ms
 * after load: it focuses a field and calls `select()` on it. A `fill()` still
 * in flight when that lands can lose its value — on the CI container the
 * password fill took 79ms against about 2ms on a warm machine, which is what
 * made this a cold-start flake rather than a constant one.
 *
 * The field then submits empty, and `#user_pass` carries `required`. The
 * browser refuses to submit an empty required field, so **no request is made at
 * all**: `validity.valueMissing` is true, the page never navigates, and the
 * test waits out its timeout with an empty network log and a login form on
 * screen. Three occurrences were attributed to a slow admin bundle before a
 * trace showed the test had never reached an admin page.
 *
 * Two things prevent it here, and the second matters as much as the first.
 *
 * 1. **Let the steal happen before filling.** The timer is one-shot, so once
 *    it has moved focus there is nothing left to interrupt anything.
 * 2. **Assert what the browser holds before submitting.** Prevention that
 *    silently stops working is how this cost three lanes; with the assertion,
 *    any future variant fails in seconds saying the password is empty, rather
 *    than as a navigation timeout that names nothing.
 *
 * `placement-mapping.spec.ts` already asserted a value before clicking — on
 * `#user_login`, the field that is focused *into* and cannot lose its text.
 * The right instinct on the wrong field, which is why the check below is on the
 * password.
 *
 * @param page     Playwright page.
 * @param password Administrator password. The packaged-artifact lane installs a
 *                 WordPress with its own, so this cannot be a constant.
 */
export async function signInToAdmin(
	page: Page,
	password = 'admin'
): Promise< void > {
	await page.goto( '/wp-login.php' );

	/*
	 * Core's autofocus, waited for rather than raced. It is filtered off by
	 * `enable_login_autofocus` and skipped entirely when the page renders an
	 * error, so a timeout here is a legitimate state and not a failure — the
	 * assertion below is what actually holds the line.
	 */
	await page
		.waitForFunction(
			() => 'user_login' === document.activeElement?.id,
			undefined,
			{ timeout: 3000 }
		)
		.catch( () => {} );

	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( password );

	// The password, because it is the one that goes missing. An empty value
	// here is not a slow screen later: it is a submit the browser will refuse
	// to make, with nothing in the network log to say so.
	await expect( page.locator( '#user_pass' ) ).toHaveValue( password );

	await page.locator( '#wp-submit' ).click();

	/*
	 * Wait for the login to land before navigating away.
	 *
	 * Clicking submit and calling goto() straight after is a race, and it lost
	 * in CI: the failure screenshot showed wp-login.php, not a slow-mounting
	 * screen. Playwright serialises navigations on a page, which makes this
	 * look safe, but serialising them does not guarantee the auth cookie is set
	 * before the next request leaves.
	 */
	await page.waitForURL( /\/wp-admin\// );
}
