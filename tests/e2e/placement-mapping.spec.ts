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
	await expect(
		page.getByRole( 'heading', { level: 2, name: 'New placement' } )
	).toBeVisible();
	await expectAdminA11y( page );

	const create = page.getByRole( 'region', { name: 'New placement' } );
	await create.getByLabel( 'Name' ).fill( 'E2E custom slot' );
	await create.getByLabel( 'Slot slug' ).fill( 'e2e-custom-slot' );
	await create.getByLabel( 'Size' ).selectOption( 'custom' );
	await create.getByLabel( 'Custom width (px)' ).fill( '123' );
	await create.getByLabel( 'Custom height (px)' ).fill( '45' );
	await create.getByRole( 'button', { name: 'Create placement' } ).click();

	await expect(
		page.getByRole( 'status' ).filter( { hasText: 'Placement created.' } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: /E2E custom slot/ } )
	).toBeVisible();
	await expect(
		page.getByRole( 'heading', { name: /E2E browser placement/ } )
	).toBeVisible();
	await expectAdminA11y( page );
} );
