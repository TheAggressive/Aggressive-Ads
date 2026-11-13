import { expect, test } from '@playwright/test';
import { expectNoHorizontalOverflow, expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { wp, wpPluginFile } from './wp-cli';

test( 'campaign goals keep period delivery separate, match email, and paginate accessibly', async ( {
	page,
}, testInfo ) => {
	const fixture: {
		campaigns: number[];
		from: string;
		to: string;
		body: string;
	} = JSON.parse(
		wpPluginFile( 'tests/e2e/seed-campaign-goals.php' ).trim()
	);
	try {
		await page.goto( '/advertiser/' );
		await signIn( page, 'advertiser@example.test', 'advertiser' );
		await page.goto(
			`/advertiser/?from=${ fixture.from }&to=${ fixture.to }&tz=utc`
		);
		const panel = page.getByRole( 'region', {
			name: 'Campaign goals and delivery',
		} );
		await expect( panel ).toBeVisible();
		await expect( panel.locator( 'details' ) ).toHaveCount( 10 );
		const first = panel.locator( 'details' ).first();
		await expect( first.locator( 'summary' ) ).toContainText(
			'E2E browser campaign goals 11'
		);
		await first.locator( 'summary' ).focus();
		await page.keyboard.press( 'Enter' );
		await expect( first ).toHaveAttribute( 'open', '' );
		for ( const [ label, value ] of [
			[ 'Current goal', '100 Impressions' ],
			[ 'Recorded goal units so far', '50' ],
			[ 'Goal progress', '50.0%' ],
			[ 'Impressions', '20' ],
			[ 'Conversions', 'Not measured' ],
		] ) {
			const field = first.locator( 'dl > div' ).filter( {
				has: page
					.locator( 'dt' )
					.filter( { hasText: new RegExp( `^${ label }$` ) } ),
			} );
			await expect( field.locator( 'dd' ) ).toHaveText( value );
			const block =
				fixture.body
					.split(
						'E2E browser campaign goals 11 — Default delivery plan'
					)[ 1 ]
					?.split( 'E2E browser campaign goals 10 —' )[ 0 ] ?? '';
			expect( block ).toContain( `${ label }: ${ value }` );
		}
		await expectPortalA11y( page );
		await page.setViewportSize( { width: 320, height: 740 } );
		await expectNoHorizontalOverflow( page );
		await panel.screenshot( {
			path: testInfo.outputPath( 'campaign-goals-mobile.png' ),
		} );
		await panel
			.getByRole( 'link', { name: 'Older delivery plans' } )
			.click();
		await expect( page ).toHaveURL( /plans_before=/ );
		await expect( panel ).not.toContainText(
			'E2E browser campaign goals 11'
		);
		await panel
			.getByRole( 'link', { name: 'Newest delivery plans' } )
			.click();
		await expect( panel.locator( 'details' ).first() ).toContainText(
			'E2E browser campaign goals 11'
		);
	} finally {
		wp( 'post', 'delete', ...fixture.campaigns.map( String ), '--force' );
	}
} );
