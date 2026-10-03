import { expect, type Page } from '@playwright/test';
import { wpPluginFile } from './wp-cli';

/** One staff screen, as the evidence specs visit it. */
export type StaffScreen = { name: string; url: string };

/**
 * Every Advertising screen, read from the sidebar the user actually sees, plus
 * the campaign view, which has no menu entry of its own.
 *
 * Read rather than listed: a list kept here is a second copy of
 * `Menu::SCREENS`, and a screen added to the menu but not to the list would be
 * the one screen nobody measured. The count is asserted so that a sidebar that
 * failed to render cannot pass every check by visiting nothing.
 *
 * @param page Signed-in admin page.
 */
export async function staffScreens( page: Page ): Promise< StaffScreen[] > {
	await page.goto( '/wp-admin/admin.php?page=aggr-review' );

	const links = page.locator( '#toplevel_page_aggr .wp-submenu a' );
	const screens: StaffScreen[] = [];

	for ( const link of await links.all() ) {
		const href = ( await link.getAttribute( 'href' ) ) ?? '';
		const name = ( await link.innerText() ).replace( /\s*\d+\s*$/, '' );

		screens.push( {
			name: name.trim(),
			url:
				new URL( href, page.url() ).pathname +
				new URL( href, page.url() ).search,
		} );
	}

	expect( screens, 'The Advertising submenu did not render.' ).toHaveLength(
		8
	);

	const campaignId = wpPluginFile(
		'tests/e2e/seed-review-creative.php'
	).trim();

	screens.push( {
		name: 'Campaign view',
		url: `/wp-admin/admin.php?page=aggr-review&campaign=${ campaignId }`,
	} );

	return screens;
}

/**
 * Opens a screen and waits for its bundle to have drawn: the header is PHP's,
 * so its presence says nothing about whether the screen beneath it rendered.
 *
 * @param page   Page.
 * @param screen Screen to open.
 */
export async function openScreen(
	page: Page,
	screen: StaffScreen
): Promise< void > {
	await page.goto( screen.url );
	await expect( page.locator( '.aggr-admin-head h1' ) ).toBeVisible();
	await page.waitForLoadState( 'networkidle' );
}

/**
 * How far the page scrolls sideways, and the element that pushes it furthest,
 * so a failure names what to fix rather than only that something is wrong.
 *
 * @param page Page.
 */
export async function sidewaysScroll(
	page: Page
): Promise< { by: number; culprit: string } > {
	return page.evaluate( () => {
		const width = document.documentElement.clientWidth;
		const by = document.documentElement.scrollWidth - width;
		let culprit = '';
		let furthest = width + 1;

		for ( const element of document.querySelectorAll(
			'#wpbody-content *'
		) ) {
			const box = element.getBoundingClientRect();

			if ( box.width > 0 && box.right > furthest ) {
				furthest = box.right;
				culprit = `${ element.tagName.toLowerCase() }.${ [
					...element.classList,
				]
					.slice( 0, 2 )
					.join( '.' ) } reaching ${ Math.round( box.right ) }px`;
			}
		}

		return { by, culprit };
	} );
}
