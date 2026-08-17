import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page } from '@playwright/test';

const wcagTags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ];

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
	const result = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( wcagTags )
		.analyze();

	const summary = result.violations
		.map( ( violation ) => `${ violation.id }: ${ violation.help }` )
		.join( '\n' );

	expect( result.violations, summary ).toEqual( [] );
}
