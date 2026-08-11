import AxeBuilder from '@axe-core/playwright';
import { expect, type Page } from '@playwright/test';

const wcagTags = [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa' ];

export async function expectPortalA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.laao-ads-shell' );
}

export async function expectAdminA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.laao-ads-admin' );
}

/**
 * The sign-in document, which has no rail and therefore no .laao-ads-shell.
 */
export async function expectSignInA11y( page: Page ): Promise<void> {
	await expectScopedA11y( page, '.laao-ads-bare' );
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
