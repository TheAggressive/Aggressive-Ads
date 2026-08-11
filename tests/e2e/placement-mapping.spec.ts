import { expect, test } from '@playwright/test';
import { expectAdminA11y } from './accessibility';

test( 'administrator maps a placement through the accessible staff design system', async ( { page } ) => {
	await page.goto( '/wp-login.php' );
	await page.locator( '#user_login' ).fill( 'admin' );
	await page.locator( '#user_pass' ).fill( 'admin' );
	await expect( page.locator( '#user_login' ) ).toHaveValue( 'admin' );
	await expect( page.locator( '#user_pass' ) ).toHaveValue( 'admin' );
	await page.locator( '#wp-submit' ).click();

	await page.goto( '/wp-admin/admin.php?page=laao-ads-placement-mapping' );

	await expect( page.getByRole( 'heading', { level: 1, name: 'Ad delivery mappings' } ) ).toBeVisible();
	await expect( page.getByRole( 'status' ).filter( { hasText: /AdSanity groups are available/ } ) ).toBeVisible();

	const row = page.getByRole( 'row', { name: /E2E browser placement/ } );
	const select = row.getByRole( 'combobox', { name: 'AdSanity group for E2E browser placement' } );

	await expect( row.getByText( 'Unmapped', { exact: true } ) ).toBeVisible();
	await expect( select ).toHaveValue( '0' );
	await expectAdminA11y( page );

	const groupValue = await select.locator( 'option' ).filter( { hasText: 'E2E browser group' } ).getAttribute( 'value' );
	expect( groupValue ).not.toBeNull();
	await select.selectOption( groupValue! );
	await row.getByRole( 'button', { name: 'Save mapping' } ).click();

	await expect( page.getByRole( 'status' ).filter( { hasText: 'Placement mapping saved.' } ) ).toBeVisible();
	const updated = page.getByRole( 'row', { name: /E2E browser placement/ } );
	await expect( updated.getByText( 'Mapped', { exact: true } ) ).toBeVisible();
	await expect( updated.getByRole( 'combobox', { name: 'AdSanity group for E2E browser placement' } ) ).not.toHaveValue( '0' );
	await expectAdminA11y( page );
} );
