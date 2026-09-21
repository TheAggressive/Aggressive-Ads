import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page } from '@playwright/test';

const wcagTags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

/**
 * The one console error that is the product working rather than failing.
 *
 * The device preview frames a creative — somebody else's file — under an empty
 * `sandbox`, so nothing inside it may run script. A browser says so once per
 * frame, but only because the harness reaches in: Playwright and the axe scan
 * below both inject themselves into every frame they find. A real session
 * injects nothing and prints nothing.
 *
 * Matched by the frame's own route rather than by the browser's wording alone,
 * so a script blocked anywhere else still fails the specs that watch the
 * console — as does this one ceasing to be blocked.
 */
export const sandboxRefusedInjection =
	/^Blocked script execution in '[^']*\/aggr\/v1\/creatives\/\d+\/preview[^']*' because the document's frame is sandboxed/;

/**
 * WCAG 1.4.10: content reflows at 320 CSS pixels without two-dimensional scrolling.
 */
export async function expectNoHorizontalOverflow(
	page: Page
): Promise< void > {
	const dimensions = await page.evaluate( () => ( {
		clientWidth: document.documentElement.clientWidth,
		scrollWidth: document.documentElement.scrollWidth,
	} ) );

	expect( dimensions.scrollWidth ).toBeLessThanOrEqual(
		dimensions.clientWidth + 1
	);
}

export async function expectPortalA11y( page: Page ): Promise< void > {
	await expectScopedA11y( page, '.aggr-shell' );
}

export async function expectAdminA11y( page: Page ): Promise< void > {
	await expectScopedA11y( page, '.aggr-admin' );
}

/**
 * The sign-in document, which has no rail and therefore no .aggr-shell.
 */
export async function expectSignInA11y( page: Page ): Promise< void > {
	await expectScopedA11y( page, '.aggr-bare' );
}

/**
 * Open overlays render in wp_footer, outside .aggr-shell (so inert on the
 * page root does not inert the dialog). Axe the open overlay, not the shell.
 */
export async function expectOpenDialogA11y( page: Page ): Promise< void > {
	await expectScopedA11y( page, '.aggr-overlay.is-open' );
}

/**
 * Whether focus is currently inside the open overlay.
 */
function focusIsInsideOverlay( page: Page ): Promise< boolean > {
	return page.evaluate( () => {
		const overlay = document.querySelector( '.aggr-overlay.is-open' );
		return overlay?.contains( document.activeElement ) ?? false;
	} );
}

/**
 * Open from the trigger, prove the panel is focused, Tab cycles *past the end*
 * without leaving the overlay, Escape closes, and focus returns to the trigger.
 *
 * The tab count matters and is why this counts the stops rather than pressing
 * once. A single Tab lands on the first control inside the panel whether or not
 * anything is trapping focus, so it passes with the trap deleted outright —
 * which it did, in both browser projects. Only a press from the *last* stop
 * distinguishes a trap from an ordinary tab order, so this walks one full cycle
 * plus one and checks containment at every step.
 */
export async function expectDialogKeyboard(
	page: Page,
	trigger: Locator,
	dialogName: string | RegExp
): Promise< void > {
	await trigger.click();
	const dialog = page.getByRole( 'dialog', { name: dialogName } );
	await expect( dialog ).toBeVisible();
	await expect( dialog ).toBeFocused();
	await expectOpenDialogA11y( page );

	const stops = await page.evaluate( () => {
		const overlay = document.querySelector( '.aggr-overlay.is-open' );

		if ( ! overlay ) {
			return 0;
		}

		return Array.from(
			overlay.querySelectorAll(
				'a[href], button:not([disabled]), input:not([disabled]), ' +
					'select:not([disabled]), textarea:not([disabled]), ' +
					'[tabindex]:not([tabindex="-1"])'
			)
		).filter(
			( el ) => ! el.closest( '[hidden]' ) && ! el.closest( '[inert]' )
		).length;
	} );

	expect(
		stops,
		'The open dialog exposes no tab stops, so this proves nothing about a trap.'
	).toBeGreaterThan( 0 );

	for ( let press = 0; press <= stops; press++ ) {
		await page.keyboard.press( 'Tab' );

		expect(
			await focusIsInsideOverlay( page ),
			`Focus left the dialog after ${ press + 1 } Tab press(es).`
		).toBe( true );
	}

	// Backwards over the boundary too: Shift+Tab from the first stop must wrap
	// to the last rather than reaching the page behind.
	for ( let press = 0; press <= stops; press++ ) {
		await page.keyboard.press( 'Shift+Tab' );

		expect(
			await focusIsInsideOverlay( page ),
			`Focus left the dialog after ${ press + 1 } Shift+Tab press(es).`
		).toBe( true );
	}

	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toBeHidden();
	await expect( trigger ).toBeFocused();
}

async function expectScopedA11y(
	page: Page,
	selector: string
): Promise< void > {
	/*
	 * The device preview is excluded because axe cannot enter it, not to spare
	 * it scrutiny. Axe audits a frame by injecting itself into it, and that
	 * frame forbids script (see above), so each attempt is refused — sixty-odd
	 * times per scan as it walked the frame's contexts, and in WebKit it hung
	 * until the test timed out rather than failing quickly.
	 *
	 * Nothing is lost. The `iframe` element itself is still audited here — its
	 * accessible name and its place in the tab order live in this document —
	 * and what it frames is one `img` with an empty `alt`, served by a route
	 * of ours, not markup a scan of this page could ever reach.
	 */
	const result = await new AxeBuilder( { page } )
		.include( selector )
		.exclude( '.aggr-device__frame' )
		.withTags( wcagTags )
		.analyze();

	const summary = result.violations
		.map( ( violation ) => `${ violation.id }: ${ violation.help }` )
		.join( '\n' );

	expect( result.violations, summary ).toEqual( [] );
}
