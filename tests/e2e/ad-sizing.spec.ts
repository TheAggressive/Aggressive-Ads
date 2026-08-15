import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow } from './accessibility';

test( 'block padding does not shrink a native-size advertisement', async ( {
	page,
} ) => {
	await page.goto( '/e2e-ad-sizing/' );

	const shell = page.locator( '[data-aggr-slot="e2e-browser-placement"]' );
	const canvas = shell.locator( '.aggr-slot__canvas' );

	await expect( shell ).toBeVisible();
	await expect( canvas ).toBeVisible();
	await expect( canvas ).toHaveCSS( 'width', '728px' );
	await expect( canvas ).toHaveCSS( 'height', '90px' );

	const shellBox = await shell.boundingBox();
	expect( shellBox?.width ).toBe( 776 );
	expect( shellBox?.height ).toBe( 138 );

	await page.setViewportSize( { width: 320, height: 800 } );
	await expectNoHorizontalOverflow( page );

	const narrowCanvas = await canvas.boundingBox();
	expect( narrowCanvas ).not.toBeNull();
	expect(
		( narrowCanvas?.width ?? 0 ) / ( narrowCanvas?.height ?? 1 )
	).toBeCloseTo( 728 / 90, 1 );
} );
