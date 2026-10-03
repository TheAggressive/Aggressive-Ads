import { expect, test } from '@playwright/test';
import { expectAdminA11y } from '../accessibility';
import { signInToAdmin } from '../admin-login';
import {
	EVERY_SCREEN_TIMEOUT,
	openScreen,
	sidewaysScroll,
	staffScreens,
} from '../staff-screens';

/**
 * Every staff screen at 320 CSS pixels (WCAG 1.4.10), axe-clean there too.
 *
 * This covered Reports alone, as the screen most likely to force sideways
 * scrolling with its filter row and table. Measured across all of them, the
 * one that failed was Settings: a brand colour's button would not wrap and ran
 * 83px past the screen. So every screen, read from the sidebar.
 *
 * Tables may scroll inside their own surface; the page may not.
 */
test( 'every staff screen reflows at 320 CSS pixels', async ( { page } ) => {
	test.setTimeout( EVERY_SCREEN_TIMEOUT );
	await signInToAdmin( page );

	for ( const screen of await staffScreens( page ) ) {
		await test.step( screen.name, async () => {
			await openScreen( page, screen );

			const scroll = await sidewaysScroll( page );

			expect(
				scroll.by,
				`${ screen.name } scrolls sideways at 320px: ${ scroll.culprit }`
			).toBeLessThanOrEqual( 1 );

			await expectAdminA11y( page );
		} );
	}
} );
