import { expect, test, type Page } from '@playwright/test';
import { expectAdminA11y } from './accessibility';
import { signInToAdmin } from './admin-login';

const SCREEN = '/wp-admin/admin.php?page=aggr-placement-mapping';

/** Signs in as the administrator and lands on Placements. */
async function openScreen( page: Page ): Promise< void > {
	await signInToAdmin( page );
	await page.goto( SCREEN );

	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Placements' } )
	).toBeVisible();
	await expect( page.locator( '.dataviews-view-table' ) ).toBeVisible();
}

/** The table's data rows. */
function rows( page: Page ) {
	return page.locator( '.dataviews-view-table tbody tr' );
}

test( 'administrator creates a placement with a common size and a custom size', async ( {
	page,
} ) => {
	await openScreen( page );
	await expectAdminA11y( page );

	/*
	 * The shared bundle's stylesheet reached the page.
	 *
	 * DataViews is compiled once and registered as `aggr-dataviews`, and a
	 * script dependency does not carry a stylesheet. Losing it renders a
	 * table that is unstyled and still entirely functional, so nothing else
	 * here would notice.
	 */
	await expect(
		page.locator(
			'link[rel="stylesheet"][href*="dist/admin/dataviews.css"]'
		)
	).toHaveCount( 1 );

	await page.getByRole( 'button', { name: 'New placement' } ).click();

	const modal = page.getByRole( 'dialog' );

	await expect( modal ).toBeVisible();
	await expectAdminA11y( page );

	await modal.getByLabel( 'Name' ).fill( 'E2E custom slot' );
	/*
	 * The fixed slug is deliberate. tests/e2e/reset.php deletes this fixture by
	 * slug between runs, so a unique one would escape teardown and leave a row
	 * behind on every run.
	 */
	await modal.getByLabel( 'Slot slug' ).fill( 'e2e-custom-slot' );
	await modal.getByLabel( 'Size' ).selectOption( 'custom' );
	await modal.getByLabel( 'Custom width (px)' ).fill( '123' );
	await modal.getByLabel( 'Custom height (px)' ).fill( '45' );
	await modal.getByRole( 'button', { name: 'Create placement' } ).click();

	await expect( modal ).toBeHidden();
	await expect( page.locator( '.components-notice.is-success' ) ).toHaveText(
		/Placement created\./
	);

	// The custom pixel pair round-tripped through the server rather than being
	// echoed back from the form: the size cell is rendered from the refreshed
	// catalogue the write returned.
	await expect(
		rows( page ).filter( { hasText: 'E2E custom slot' } )
	).toContainText( '123x45' );

	/*
	 * The seeded placement is still listed, so the create did not replace the
	 * catalogue with only its own result.
	 *
	 * Filtered to the row it is about, rather than asserting text against every
	 * row at once. `toContainText` with a single expected value wants one
	 * element, so handing it the whole tbody is a strict-mode violation the
	 * moment the catalogue holds more than one placement — which it always
	 * does. The assertion could never pass, and said "resolved to N elements"
	 * rather than anything about the placement it names.
	 *
	 * It went unseen because this lane never finished: three npm outages in a
	 * row cancelled Browser E2E on every run after the DataViews change landed.
	 */
	await expect(
		rows( page ).filter( { hasText: 'E2E browser placement' } )
	).toHaveCount( 1 );
	await expectAdminA11y( page );

	const row = rows( page ).filter( { hasText: 'E2E custom slot' } ).first();

	/*
	 * Edit is a button on the row, not an item behind an "Actions" menu.
	 *
	 * DataViews collapses row actions into a menu once there are several and
	 * renders a single action inline. Placements has exactly one, so the
	 * two-step menu dance that `organizations.spec.ts` needs — where there are
	 * several — never applied here. Asserting the button exists first says
	 * which of the two shapes this screen is in, so a second action being added
	 * later fails with that sentence rather than with a timeout.
	 */
	await expect(
		row.getByRole( 'button', { name: 'Edit' } ),
		'Placements now has more than one row action, so Edit moved behind a menu.'
	).toBeVisible();

	await row.getByRole( 'button', { name: 'Edit' } ).click();

	await expect( modal ).toBeVisible();
	await modal.getByLabel( 'Size' ).selectOption( '728x90' );
	await modal.getByRole( 'button', { name: 'Save placement' } ).click();

	await expect( modal ).toBeHidden();
	await expect( page.locator( '.components-notice.is-success' ) ).toHaveText(
		/Placement saved\./
	);
	await expect(
		rows( page ).filter( { hasText: 'E2E custom slot' } )
	).toContainText( '728x90' );
	await expectAdminA11y( page );
} );
