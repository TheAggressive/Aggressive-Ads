import { expect, test } from '@playwright/test';
import { signInToAdmin } from './admin-login';
import { solidPng } from './png';
import { wp, wpPluginFile } from './wp-cli';
import { expectNoHorizontalOverflow, expectPortalA11y } from './accessibility';

export function staticHtml5Cases(): void {
	for ( const javaScriptEnabled of [ true, false ] ) {
		test( `static HTML5 upload and review with JavaScript ${
			javaScriptEnabled ? 'enabled' : 'disabled'
		}`, async ( { browser } ) => {
			const fixture = JSON.parse(
				wpPluginFile( 'tests/e2e/seed-static-html5.php' )
			);
			const context = await browser.newContext( { javaScriptEnabled } );
			const page = await context.newPage();
			await signInToAdmin( page );
			await page.goto(
				`/advertiser/campaigns/${ fixture.campaign }/?step=creative`
			);
			const form = page.locator(
				`form[data-aggr-upload="${ fixture.placement }"]`
			);
			await expect( form ).toBeVisible();
			await expect(
				form.getByLabel( 'HTML5 raster fallback', { exact: true } )
			).toBeVisible();
			const refused = await context.request.post(
				'/wp-admin/admin-post.php',
				{
					multipart: {
						action: await form
							.locator( '[name="action"]' )
							.inputValue(),
						campaign_id: String( fixture.campaign ),
						placement_id: String( fixture.placement ),
						_wpnonce: 'invalid-static-html5-nonce',
						click_url: 'https://example.com/ad',
						file: {
							name: 'creative.zip',
							mimeType: 'application/zip',
							buffer: Buffer.from( fixture.archive, 'base64' ),
						},
						fallback: {
							name: 'fallback.png',
							mimeType: 'image/png',
							buffer: solidPng( 728, 90 ),
						},
					},
				}
			);
			expect( refused.status() ).toBe( 403 );
			await context.request.post( '/wp-admin/admin-post.php', {
				multipart: {
					action: await form
						.locator( '[name="action"]' )
						.inputValue(),
					campaign_id: String( fixture.campaign ),
					placement_id: String( fixture.placement ),
					_wpnonce: await form
						.locator( '[name="_wpnonce"]' )
						.inputValue(),
					click_url: 'https://example.com/ad',
					file: {
						name: 'blocked.zip',
						mimeType: 'application/zip',
						buffer: Buffer.from( fixture.refused, 'base64' ),
					},
					fallback: {
						name: 'fallback.png',
						mimeType: 'image/png',
						buffer: solidPng( 728, 90 ),
					},
				},
			} );
			expect(
				JSON.parse(
					wp(
						'eval',
						`echo wp_json_encode((new \\Aggressive\\Ads\\Repository\\Creative_Repository())->ids_for_campaign(${ Number(
							fixture.campaign
						) }));`
					)
				)
			).toEqual( [] );

			await form
				.getByLabel( 'Destination URL', { exact: true } )
				.fill( 'https://example.com/static-ad' );
			await form
				.getByLabel( 'Ad creative file', { exact: true } )
				.setInputFiles( {
					name: 'creative.zip',
					mimeType: 'application/zip',
					buffer: Buffer.from( fixture.archive, 'base64' ),
				} );
			await form
				.getByLabel( 'HTML5 raster fallback', { exact: true } )
				.setInputFiles( {
					name: 'fallback.png',
					mimeType: 'image/png',
					buffer: solidPng( 728, 90 ),
				} );
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
			const reviewer = await browser.newContext();
			const staff = await reviewer.newPage();
			await signInToAdmin( staff );
			await staff.goto(
				`/wp-admin/admin.php?page=aggr-review&campaign=${ fixture.campaign }`
			);
			const preview = staff.locator( 'iframe.aggr-device__frame' );
			await expect( preview ).toHaveAttribute( 'sandbox', '' );
			const wrapper = staff.frameLocator( 'iframe.aggr-device__frame' );
			await expect( wrapper.locator( 'figcaption' ) ).toHaveText( [
				'Static HTML5 creative',
				'Raster fallback',
			] );
			await expect( wrapper.locator( 'iframe' ) ).toHaveAttribute(
				'sandbox',
				''
			);
			await expect(
				wrapper.frameLocator( 'iframe' ).locator( '.banner' )
			).toHaveText( 'Reviewed static HTML5' );
			const previewURL = await preview.getAttribute( 'src' );
			const direct = await reviewer.request.get( previewURL! );
			expect( direct.headers()[ 'cache-control' ] ).toContain(
				'no-store'
			);
			expect( direct.headers()[ 'content-security-policy' ] ).toContain(
				'sandbox;'
			);
			expect( await direct.text() ).not.toContain( '<script' );
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
			const visitor = await browser.newContext( {
				viewport: { width: 1024, height: 800 },
			} );
			const publicPage = await visitor.newPage();
			let impressions = 0;
			publicPage.on( 'request', ( request ) => {
				if ( /\/aggr\/v1\/i(?:[?]|$)/.test( request.url() ) ) {
					impressions++;
				}
			} );
			await publicPage.goto( fixture.page );
			const rendered = publicPage.locator( '.aggr-slot__canvas iframe' );
			await expect( rendered ).toBeVisible();
			await expect( rendered ).toHaveAttribute( 'sandbox', '' );
			await expect(
				publicPage
					.frameLocator( '.aggr-slot__canvas iframe' )
					.locator( '.banner' )
			).toHaveText( 'Reviewed static HTML5' );
			expect(
				await rendered.evaluate( ( element ) => {
					try {
						return ( element as HTMLIFrameElement ).contentWindow!
							.document.body.textContent;
					} catch {
						return 'opaque';
					}
				} )
			).toBe( 'opaque' );
			await expect.poll( () => impressions ).toBe( 1 );
			await expect(
				publicPage.locator( '.aggr-slot__canvas img' )
			).toHaveCount( 0 );
			await expectNoHorizontalOverflow( publicPage );
			expect(
				await publicPage.evaluate( () => 'p18Escaped' in window )
			).toBe( false );
			const assets = await publicPage.evaluate( () =>
				performance
					.getEntriesByType( 'resource' )
					.map( ( entry ) => entry.name )
					.filter( ( name ) => /\.json$/.test( name ) )
			);
			expect( assets ).toHaveLength( 1 );
			const raw = await visitor.request.get( assets[ 0 ]! );
			expect( await raw.text() ).not.toContain( '<' );
			await publicPage.route( '**/*.json', async ( route ) => {
				const response = await route.fetch();
				const envelope = await response.json();
				envelope.document = Buffer.from(
					'<script>fetch("https://unreviewed.example/escape")</script>'
				).toString( 'base64' );
				await route.fulfill( { response, json: envelope } );
			} );
			const escapes: string[] = [];
			publicPage.on( 'request', ( request ) => {
				if ( request.url().includes( 'unreviewed.example' ) ) {
					escapes.push( request.url() );
				}
			} );
			await publicPage.goto( fixture.page );
			await expect(
				publicPage.locator( '.aggr-slot__canvas img' )
			).toBeVisible();
			await expect(
				publicPage.locator( '.aggr-slot__canvas iframe' )
			).toHaveCount( 0 );
			await expect.poll( () => impressions ).toBe( 2 );
			expect( escapes ).toEqual( [] );
			await publicPage.unroute( '**/*.json' );
			await publicPage.emulateMedia( { reducedMotion: 'reduce' } );
			let documentFetches = 0;
			publicPage.on( 'request', ( request ) => {
				if ( /\.json$/.test( new URL( request.url() ).pathname ) ) {
					documentFetches++;
				}
			} );
			await publicPage.goto( fixture.page );
			await expect(
				publicPage.locator( '.aggr-slot__canvas img' )
			).toBeVisible();
			await expect.poll( () => impressions ).toBe( 3 );
			expect( documentFetches ).toBe( 0 );

			await visitor.close();
			await reviewer.close();
			await context.close();
		} );
	}
}
