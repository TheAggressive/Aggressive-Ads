import { expect, test } from '@playwright/test';
import { expectPortalA11y, expectNoHorizontalOverflow } from './accessibility';
import { signIn } from './sign-in-helper';
import { signInToAdmin } from './admin-login';
import { solidPng } from './png';
import { wp, wpPluginFile } from './wp-cli';

export function responsiveImageCases(): void {
	for ( const javaScriptEnabled of [ true, false ] ) {
		test( `responsive image upload with JavaScript ${
			javaScriptEnabled ? 'enabled' : 'disabled'
		}`, async ( { browser } ) => {
			const fixture = JSON.parse(
				wpPluginFile( 'tests/e2e/seed-responsive-images.php' )
			);
			const context = await browser.newContext( { javaScriptEnabled } );
			const page = await context.newPage();
			await page.goto( '/advertiser/' );
			await signIn( page, 'advertiser@example.test', 'advertiser' );
			await page.goto(
				`/advertiser/campaigns/${ fixture.campaign }/?step=creative`
			);
			const form = page.locator(
				`form[data-aggr-upload="${ fixture.placement }"]`
			);
			await expect( form ).toBeVisible();
			const refused = await context.request.post(
				'/wp-admin/admin-post.php',
				{
					multipart: {
						action: await form
							.locator( '[name="action"]' )
							.inputValue(),
						campaign_id: String( fixture.campaign ),
						placement_id: String( fixture.placement ),
						_wpnonce: 'invalid-responsive-upload-nonce',
						click_url: 'https://example.com/refused',
						file: {
							name: 'desktop.png',
							mimeType: 'image/png',
							buffer: solidPng( 728, 90 ),
						},
						'renditions[]': {
							name: 'mobile.png',
							mimeType: 'image/png',
							buffer: solidPng( 320, 50 ),
						},
					},
				}
			);
			expect( refused.status() ).toBe( 403 );
			await form
				.getByLabel( 'Destination URL', { exact: true } )
				.fill( 'https://example.com/responsive' );
			await form
				.getByLabel( 'Ad creative file', { exact: true } )
				.setInputFiles( {
					name: 'desktop.png',
					mimeType: 'image/png',
					buffer: solidPng( 728, 90 ),
				} );
			await form
				.getByLabel( 'Responsive renditions (optional)' )
				.setInputFiles( [
					{
						name: 'mobile.png',
						mimeType: 'image/png',
						buffer: solidPng( 320, 50 ),
					},
				] );
			// Explicit submission leaves time to choose the optional files with either enhancement mode.
			await expect(
				form.getByRole( 'button', {
					name: 'Upload creative',
					exact: true,
				} )
			).toBeVisible();
			await page.setViewportSize( { width: 320, height: 800 } );
			await expectNoHorizontalOverflow( page );
			if ( javaScriptEnabled ) {
				await expectPortalA11y( page );
			}
			await form
				.getByRole( 'button', { name: 'Upload creative', exact: true } )
				.click();
			await expect(
				page
					.getByRole( 'status' )
					.filter( { hasText: 'Creative uploaded' } )
			).toBeVisible();
			const ids = JSON.parse(
				wp(
					'eval',
					`echo wp_json_encode((new \\Aggressive\\Ads\\Repository\\Creative_Repository())->ids_for_campaign(${ Number(
						fixture.campaign
					) }));`
				)
			);
			expect( ids ).toHaveLength( 1 );
			wp(
				'eval',
				`(new \\Aggressive\\Ads\\Repository\\Campaign_Repository())->update_status(${ Number(
					fixture.campaign
				) }, \\Aggressive\\Ads\\Core\\Post_Statuses::LIVE);`
			);
			const staffContext = await browser.newContext();
			const staff = await staffContext.newPage();
			await signInToAdmin( staff );
			await staff.goto(
				`/wp-admin/admin.php?page=aggr-review&campaign=${ fixture.campaign }`
			);
			const frame = staff.frameLocator( 'iframe.aggr-device__frame' );
			await expect( frame.locator( 'figcaption' ) ).toHaveText( [
				'320x50',
				'728x90',
			] );
			await expect(
				staff.locator( 'iframe.aggr-device__frame' )
			).toHaveAttribute( 'sandbox', '' );
			await staff
				.getByRole( 'button', {
					name: 'Publish this advertisement',
					exact: true,
				} )
				.click();
			await expect(
				staff.getByRole( 'button', {
					name: 'Publish this advertisement',
					exact: true,
				} )
			).toHaveCount( 0 );
			if ( javaScriptEnabled ) {
				const visitor = await browser.newContext();
				const publicPage = await visitor.newPage();
				let impressions = 0;
				publicPage.on( 'request', ( request ) => {
					if ( /\/aggr\/v1\/i(?:[?]|$)/.test( request.url() ) ) {
						impressions++;
					}
				} );
				for ( const width of [ 320, 1024 ] ) {
					await publicPage.setViewportSize( { width, height: 800 } );
					await publicPage.goto( fixture.page );
					const image = publicPage.locator(
						'.aggr-slot__canvas img'
					);
					await expect( image ).toBeVisible();
					await expect
						.poll( () =>
							image.evaluate(
								( element ) =>
									( element as HTMLImageElement ).naturalWidth
							)
						)
						.toBe( width === 320 ? 320 : 728 );
					await expectNoHorizontalOverflow( publicPage );
					await expect
						.poll( () => impressions )
						.toBe( width === 320 ? 1 : 2 );
				}
				await visitor.close();
			}
			await staffContext.close();
			await context.close();
		} );
	}
}
