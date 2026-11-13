import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { wp, wpPluginFile } from './wp-cli';

type Fixture = {
	package: number;
	placement: number;
	image: number;
	from: string;
	to: string;
	timezone: string;
};

for ( const javaScriptEnabled of [ true, false ] ) {
	test.describe(
		javaScriptEnabled ? 'inventory' : 'inventory without JavaScript',
		() => {
			test.use( { javaScriptEnabled } );
			test( 'checks dates privately and carries the selected package into a campaign request', async ( {
				page,
			}, testInfo ) => {
				const fixture: Fixture = JSON.parse(
					wpPluginFile( 'tests/e2e/seed-inventory.php' ).trim()
				);
				let campaign = '';
				try {
					await page.goto( '/advertiser/' );
					await signIn(
						page,
						'advertiser@example.test',
						'advertiser'
					);
					await page
						.getByRole( 'navigation', {
							name: 'Portal',
							exact: true,
						} )
						.getByRole( 'link', { name: 'Inventory', exact: true } )
						.click();
					await expect(
						page.getByRole( 'heading', {
							name: 'Browse advertising',
						} )
					).toBeVisible();
					await page
						.getByLabel( 'Package', { exact: true } )
						.selectOption( String( fixture.package ) );
					await page
						.getByLabel( 'Start date', { exact: true } )
						.fill( fixture.from );
					await page
						.getByLabel( 'End date', { exact: true } )
						.fill( '2099-12-31' );
					await page
						.getByRole( 'button', {
							name: 'Check availability',
							exact: true,
						} )
						.click();
					await expect(
						page
							.getByRole( 'status' )
							.filter( { hasText: 'Available to request' } )
					).toBeVisible();
					await expect(
						page.getByLabel( 'End date', { exact: true } )
					).toHaveValue( fixture.to );
					await expect(
						page.getByText( '728x90 px', { exact: false } ).first()
					).toBeVisible();
					for ( const privateValue of [
						'123456789',
						'234567890',
						'forecast_version',
						'shortfall',
					] ) {
						expect( await page.content() ).not.toContain(
							privateValue
						);
					}
					const example = page.getByRole( 'img', {
						name: 'Publisher example',
						exact: true,
					} );
					await expect( example ).toBeVisible();
					if ( javaScriptEnabled ) {
						await expect
							.poll( () =>
								example.evaluate(
									( image: HTMLImageElement ) =>
										image.naturalWidth
								)
							)
							.toBeGreaterThan( 0 );
						await expectPortalA11y( page );
						await page.setViewportSize( {
							width: 320,
							height: 740,
						} );
						await expectNoHorizontalOverflow( page );
						await page.screenshot( {
							path: testInfo.outputPath( 'inventory-mobile.png' ),
							fullPage: true,
						} );
					}
					await page
						.getByRole( 'button', {
							name: 'Prepare campaign request',
							exact: true,
						} )
						.click();
					await expect( page ).toHaveURL(
						/\/advertiser\/campaigns\/\d+\//
					);
					campaign =
						/\/campaigns\/(\d+)\//.exec( page.url() )?.[ 1 ] ?? '';
					expect( campaign ).not.toBe( '' );
					expect(
						wp(
							'post',
							'meta',
							'get',
							campaign,
							'_aggr_package_id'
						).trim()
					).toBe( String( fixture.package ) );
					await expect(
						page.getByLabel( 'Start date', { exact: true } )
					).toHaveValue( fixture.from );
				} finally {
					if ( campaign ) {
						wp( 'post', 'delete', campaign, '--force' );
					}
					wp(
						'post',
						'delete',
						String( fixture.package ),
						String( fixture.placement ),
						String( fixture.image ),
						'--force'
					);
					wp(
						'option',
						'update',
						'timezone_string',
						fixture.timezone
					);
				}
			} );
		}
	);
}
