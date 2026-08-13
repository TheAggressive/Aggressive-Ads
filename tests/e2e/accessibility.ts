import AxeBuilder from '@axe-core/playwright';
import { expect, type Locator, type Page } from '@playwright/test';

const wcagTags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

export async function expectPortalA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.aggr-shell' );
}

export async function expectAdminA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.aggr-admin' );
}

/**
 * The sign-in document, which has no rail and therefore no .aggr-shell.
 */
export async function expectSignInA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.aggr-bare' );
}

/**
 * Open overlays render in wp_footer, outside .aggr-shell (so inert on the
 * page root does not inert the dialog). Axe the open overlay, not the shell.
 */
export async function expectOpenDialogA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.aggr-overlay.is-open' );
}

/**
 * Open from the trigger, prove the panel is focused, Tab stays inside the
 * overlay, Escape closes, and focus returns to the trigger.
 */
export async function expectDialogKeyboard(
	page: Page,
	trigger: Locator,
	dialogName: string | RegExp
): Promise<void> {
	await trigger.click();
	const dialog = page.getByRole( 'dialog', { name: dialogName } );
	await expect( dialog ).toBeVisible();
	await expect( dialog ).toBeFocused();
	await expectOpenDialogA11y( page );

	await page.keyboard.press( 'Tab' );
	expect(
		await page.evaluate( () => {
			const overlay = document.querySelector( '.aggr-overlay.is-open' );
			return overlay?.contains( document.activeElement ) ?? false;
		} )
	).toBe( true );

	await page.keyboard.press( 'Escape' );
	await expect( dialog ).toBeHidden();
	await expect( trigger ).toBeFocused();
}

async function expectScopedA11y( page: Page, selector: string ): Promise<void> {
	const result = await new AxeBuilder( { page } )
		.include( selector )
		.withTags( wcagTags )
		.analyze();

	const summary = result.violations
		.map( ( violation ) => `${ violation.id }: ${ violation.help }` )
		.join( '\n' );

	expect( result.violations, summary ).toEqual( [] );
}
