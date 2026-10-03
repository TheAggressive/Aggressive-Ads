import { expect, test } from '@playwright/test';
import { expectAdminA11y } from './accessibility';
import { signInToAdmin } from './admin-login';
import {
	EVERY_SCREEN_TIMEOUT,
	openScreen,
	sidewaysScroll,
	staffScreens,
} from './staff-screens';

/**
 * The evidence #330 closes on: every staff screen at 200% zoom, in forced
 * colours and with reduced motion. 320px reflow is `reflow/admin-reflow.spec.ts`,
 * which runs at that width as its own project.
 *
 * Each check visits every screen read from the sidebar plus the campaign view
 * (`staffScreens()`), so a screen added later is measured without anybody
 * remembering to list it, and each failure names the screen.
 */

/**
 * 200% zoom is half the CSS viewport: a 1280×900 window zoomed to 200% lays
 * out at 640×450. Nothing may scroll sideways, and the screen stays axe-clean
 * at that size (WCAG 1.4.4, 1.4.10).
 */
test( 'every staff screen holds together at 200% zoom', async ( { page } ) => {
	test.setTimeout( EVERY_SCREEN_TIMEOUT );
	await signInToAdmin( page );
	await page.setViewportSize( { width: 640, height: 450 } );

	for ( const screen of await staffScreens( page ) ) {
		await test.step( screen.name, async () => {
			await openScreen( page, screen );

			const scroll = await sidewaysScroll( page );

			expect(
				scroll.by,
				`${ screen.name } scrolls sideways at 200% zoom: ${ scroll.culprit }`
			).toBeLessThanOrEqual( 1 );

			await expectAdminA11y( page );
		} );
	}
} );

/**
 * Forced colours replace every background and remove every box-shadow, which
 * is how status pills became bare words and core's fields lost their focus
 * ring. Measured on every screen:
 *
 * - the Signal mark is drawn in the system text colour, not erased;
 * - every status pill has a border, so it still reads as a status;
 * - every visible control in the content shows a focus outline when focused.
 */
test( 'every staff screen keeps its mark, statuses and focus in forced colours', async ( {
	page,
} ) => {
	test.setTimeout( EVERY_SCREEN_TIMEOUT );
	await signInToAdmin( page );
	await page.emulateMedia( { forcedColors: 'active' } );

	for ( const screen of await staffScreens( page ) ) {
		await test.step( screen.name, async () => {
			await openScreen( page, screen );

			const drawn = await page.evaluate( () => {
				// The system colour as this browser resolves it, rather than a
				// literal that is right on one platform.
				const probe = document.createElement( 'span' );
				probe.style.color = 'CanvasText';
				document.body.append( probe );
				const text = getComputedStyle( probe ).color;
				probe.remove();

				const mark = document.querySelector( '.aggr-admin-head__mark' );
				const pills = [
					...document.querySelectorAll( '.aggr-pill, .aggr-state' ),
				];

				return {
					mark: mark
						? getComputedStyle( mark ).backgroundColor === text
						: false,
					pills: pills.length,
					borderless: pills.filter( ( pill ) => {
						const style = getComputedStyle( pill );

						return (
							'none' === style.borderStyle ||
							0 === parseFloat( style.borderWidth )
						);
					} ).length,
				};
			} );

			expect(
				drawn.mark,
				`${ screen.name }: the mark is not drawn in CanvasText.`
			).toBe( true );
			expect(
				drawn.borderless,
				`${ screen.name }: ${ drawn.borderless } of ${ drawn.pills } status pills have no border.`
			).toBe( 0 );

			const controls = page.locator(
				'#wpbody-content :is(a[href], button:not([disabled]), input:not([type="hidden"]), select, textarea)'
			);
			let measured = 0;

			for ( const control of ( await controls.all() ).slice( 0, 15 ) ) {
				if ( ! ( await control.isVisible() ) ) {
					continue;
				}

				await control.focus();
				measured++;

				const outline = await control.evaluate( ( element ) => {
					const style = getComputedStyle( element );

					return 'none' !== style.outlineStyle &&
						parseFloat( style.outlineWidth ) > 0
						? ''
						: `${ element.tagName.toLowerCase() }.${ [
								...element.classList,
						  ]
								.slice( 0, 2 )
								.join( '.' ) }`;
				} );

				expect(
					outline,
					`${ screen.name }: a focused control shows no outline in forced colours.`
				).toBe( '' );
			}

			expect(
				measured,
				`${ screen.name }: no visible control to measure.`
			).toBeGreaterThan( 0 );
		} );
	}
} );

/**
 * With reduced motion asked for, nothing is still animating once a screen
 * has drawn (WCAG 2.3.3).
 */
test( 'nothing moves on a staff screen when reduced motion is asked for', async ( {
	page,
} ) => {
	test.setTimeout( EVERY_SCREEN_TIMEOUT );
	await signInToAdmin( page );
	await page.emulateMedia( { reducedMotion: 'reduce' } );

	for ( const screen of await staffScreens( page ) ) {
		await test.step( screen.name, async () => {
			await openScreen( page, screen );

			const running = await page.evaluate( () =>
				document
					.getAnimations()
					.filter(
						( animation ) => 'running' === animation.playState
					)
					.map( ( animation ) =>
						animation instanceof CSSAnimation
							? animation.animationName
							: 'transition'
					)
			);

			expect(
				running,
				`${ screen.name } is still animating with reduced motion on.`
			).toEqual( [] );
		} );
	}
} );
