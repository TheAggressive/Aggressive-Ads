import { expect, test } from '@playwright/test';
import { expectAdminA11y } from './accessibility';

test( 'administrator creates a placement with a common size and a custom size', async ( {
	page,
} ) => {
	/*
	 * wp-login.php, deliberately. The portal has its own sign-in form, but
	 * staff work in wp-admin and reach it the way WordPress intends. Only the
	 * portal's own gate was redirected away from wp-login; this path is
	 * unchanged and should stay that way.
	 */
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'admin' );
	await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin' );
	await page.locator( '#wp-submit' ).click();

	await page.goto( '/wp-admin/admin.php?page=aggr-placement-mapping' );

	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Inventory' } )
	).toBeVisible();

	// The screen is React now, so the first assertion has to be that it
	// mounted. Without this, every locator below would just time out and the
	// failure would say "no such button" rather than "the bundle never ran".
	const card = ( name: string | RegExp ) =>
		page
			.locator( '.components-card' )
			.filter( { has: page.getByRole( 'heading', { name } ) } );

	const create = card( 'New placement' );
	await expect( create ).toBeVisible();
	await expectAdminA11y( page );

	await create.getByLabel( 'Name' ).fill( 'E2E custom slot' );
	/*
	 * The fixed slug is deliberate. tests/e2e/reset.php deletes this fixture by
	 * slug between runs, so a unique one would escape teardown and leave a row
	 * behind on every run — which then breaks the heading assertions below with
	 * a strict-mode violation the next time.
	 */
	await create.getByLabel( 'Slot slug' ).fill( 'e2e-custom-slot' );
	await create.getByLabel( 'Size' ).selectOption( 'custom' );
	await create.getByLabel( 'Custom width (px)' ).fill( '123' );
	await create.getByLabel( 'Custom height (px)' ).fill( '45' );
	await create.getByRole( 'button', { name: 'Create placement' } ).click();

	const notice = page.locator( '.components-notice.is-success' );

	await expect( notice ).toHaveText( /Placement created\./ );

	// The custom pixel pair round-tripped through the server rather than being
	// echoed back from the form: the heading is rendered from the refreshed
	// catalogue the write returned.
	await expect(
		page.getByRole( 'heading', { name: 'E2E custom slot (123x45)' } )
	).toBeVisible();

	// The seeded placement is still listed, so the create did not replace the
	// catalogue with only its own result.
	await expect(
		page.getByRole( 'heading', { name: /E2E browser placement/ } )
	).toBeVisible();
	await expectAdminA11y( page );

	// A common size, on the same screen, saved through the update route.
	const row = card( 'E2E custom slot (123x45)' );

	await row.getByLabel( 'Size' ).selectOption( '728x90' );
	await row.getByRole( 'button', { name: 'Save placement' } ).click();

	await expect( notice ).toHaveText( /Placement saved\./ );
	await expect(
		page.getByRole( 'heading', { name: 'E2E custom slot (728x90)' } )
	).toBeVisible();
	await expectAdminA11y( page );

	/*
	 * The saved catalogue is a grid, and the number of tracks is the assertion
	 * rather than any pixel width: computed `grid-template-columns` reports one
	 * length per column, so counting them says how many columns a real browser
	 * resolved the breakpoints to.
	 */
	const columns = async (): Promise< number > =>
		page
			.locator( '.aggr-card-grid' )
			.evaluate(
				( grid ) =>
					window
						.getComputedStyle( grid )
						.gridTemplateColumns.split( ' ' ).length
			);

	await page.setViewportSize( { width: 1440, height: 900 } );
	expect( await columns() ).toBe( 3 );

	// Stacked on a phone. WCAG 1.4.10 is about content reflowing at 320px, and
	// a three-column grid that never collapses is the usual way to fail it.
	await page.setViewportSize( { width: 360, height: 800 } );
	expect( await columns() ).toBe( 1 );
	await expectAdminA11y( page );
} );
