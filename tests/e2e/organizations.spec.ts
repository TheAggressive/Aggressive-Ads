import { expect, test, type Page } from '@playwright/test';
import { expectAdminA11y } from './accessibility';
import { signInToAdmin } from './admin-login';
import { wpPluginFile } from './wp-cli';

/**
 * The staff Organizations screen, after the DataViews conversion.
 *
 * Everything asserted here was found by reading rather than by a test, which is
 * the reason this file exists. The screen pages, searches and filters on the
 * *server*, so the interesting failures are all of one shape: the browser holds
 * a view, the server holds the rows, and the two stop agreeing without anything
 * erroring. A table that silently answers the wrong query looks exactly like a
 * table that answers the right one.
 *
 * Four defects shipped in that gap and each has a test below:
 *
 * 1. The state filter read its value and ignored its operator, so "is not
 *    Active" asked the server for active organizations and returned precisely
 *    the rows the user had excluded.
 * 2. Sort direction never left the browser. The header drew a descending arrow
 *    and the server kept answering ascending.
 * 3. The members modal was frozen at the moment it opened — DataViews keeps the
 *    action object it was given — so the roster arrived and the modal spun for
 *    ever, unreachable by any later render.
 * 4. A refused write rendered its error in the page, behind the modal overlay
 *    that was still covering it.
 */

const SCREEN = '/wp-admin/admin.php?page=aggr-organizations';

/** Signs in as the administrator and lands on the Organizations screen. */
async function openScreen( page: Page ): Promise< void > {
	await signInToAdmin( page );

	await page.goto( SCREEN );

	// Mounted, before anything else. Without this every locator below would
	// time out and report "no such control" rather than "the bundle never ran",
	// which is a materially different bug.
	await expect(
		page.getByRole( 'heading', { level: 1, name: 'Organizations' } )
	).toBeVisible();
	await expect( rows( page ) ).not.toHaveCount( 0 );
}

/** The table's data rows. */
function rows( page: Page ) {
	return page.locator( '.dataviews-view-table tbody tr' );
}

/** The organization names currently rendered, in table order. */
async function names( page: Page ): Promise< string[] > {
	const cells = page.locator(
		'.dataviews-view-table tbody tr td:first-child'
	);

	return ( await cells.allInnerTexts() ).map( ( text ) => text.trim() );
}

/** Opens one row's action menu and clicks an item in it. */
async function rowAction(
	page: Page,
	name: string,
	action: string
): Promise< void > {
	const row = rows( page ).filter( { hasText: name } ).first();

	await row.getByRole( 'button', { name: 'Actions' } ).click();
	await page.getByRole( 'menuitem', { name: action } ).click();
}

test.beforeAll( () => {
	wpPluginFile( 'tests/e2e/seed-organizations.php' );
} );

test( 'the table mounts, sorts on the server and stays accessible', async ( {
	page,
} ) => {
	await openScreen( page );
	await expectAdminA11y( page );

	/*
	 * The shared bundle's stylesheet reached the page.
	 *
	 * DataViews is compiled once and registered as `aggr-dataviews`, and a
	 * script dependency does not carry a stylesheet — WordPress resolves script
	 * and style handles separately. Losing it renders a table that is unstyled
	 * and still entirely functional, so nothing else here would notice: every
	 * assertion below passes against naked markup.
	 */
	await expect(
		page.locator(
			'link[rel="stylesheet"][href*="dist/admin/dataviews.css"]'
		)
	).toHaveCount( 1 );

	const ascending = await names( page );

	// The fixture must actually contain something to order, or the assertion
	// below passes against a single row that is trivially both first and last.
	expect( ascending.length ).toBeGreaterThan( 1 );
	expect( ascending[ 0 ] ).toBe( 'APEX ANALYTICS GROUP' );

	// Sorting is the server's. Choosing a direction has to change the rows, not
	// just the arrow: the bug this guards against flipped the header and
	// re-rendered the identical ascending page.
	//
	// The column header opens a menu rather than toggling, so the direction is
	// picked explicitly. That also makes the assertion unambiguous — a toggle
	// would leave "which way is it now?" depending on where the test started.
	await page.getByRole( 'button', { name: 'Organization name' } ).click();
	await page
		.getByRole( 'menuitemradio', { name: 'Sort descending' } )
		.click();

	await expect
		.poll( async () => ( await names( page ) )[ 0 ] )
		.toBe( 'ZEPHYR OUTDOOR CO' );

	const descending = await names( page );

	expect( descending ).toEqual( [ ...ascending ].reverse() );
} );

test( 'filtering by state asks the server the question the operator means', async ( {
	page,
} ) => {
	await openScreen( page );

	const everything = await names( page );

	expect( everything ).toContain( 'APEX ANALYTICS GROUP' );
	expect( everything ).toContain( 'ZEPHYR OUTDOOR CO' );

	await page.getByRole( 'button', { name: 'Add filter' } ).first().click();
	await page.getByRole( 'menuitem', { name: 'State' } ).click();
	await page.getByRole( 'option', { name: 'Suspended' } ).click();
	await page.keyboard.press( 'Escape' );

	// Only the suspended one. The defect this replaces returned the active
	// rows instead, which looked like a working filter until you read them.
	await expect
		.poll( async () => await names( page ) )
		.toEqual( [ 'APEX ANALYTICS GROUP' ] );

	/*
	 * And "is" is the only operator on offer.
	 *
	 * This is the other half of that defect. The screen translates the filter
	 * into one REST parameter that can only express equality, so a negation it
	 * cannot answer must never reach it. Two mutually exclusive states lose
	 * nothing by it — "is not Active" says exactly what "is Suspended" says —
	 * and the field declares `operators: [ 'is' ]` to keep it that way.
	 */
	await expect(
		page.locator( '.dataviews-filters__container' ).getByText( /is not/i )
	).toHaveCount( 0 );
} );

test( 'the members modal loads its roster instead of spinning', async ( {
	page,
} ) => {
	await openScreen( page );

	await rowAction( page, 'ZEPHYR OUTDOOR CO', 'Manage members' );

	const modal = page.getByRole( 'dialog' );

	await expect( modal ).toBeVisible();

	// The roster arrives from a second request. Asserting a member is present
	// is the whole point: the modal used to render, fetch, receive the roster
	// and never see it, so it stayed on its spinner indefinitely.
	//
	// Its own owner, not the base seed's advertiser. Fixture organizations get
	// dedicated owners because a portal account belongs to exactly one
	// organization, and giving the advertiser three broke the campaign wizard.
	await expect(
		modal.getByText( 'zephyr-owner@example.test' )
	).toBeVisible();
	await expect( modal.getByText( '— owner' ) ).toBeVisible();
	await expect( modal.locator( '.components-spinner' ) ).toHaveCount( 0 );

	await expectAdminA11y( page );
} );

test( 'a refused rename explains itself inside the modal', async ( {
	page,
} ) => {
	await openScreen( page );

	await rowAction( page, 'ZEPHYR OUTDOOR CO', 'Rename' );

	const modal = page.getByRole( 'dialog' );

	await expect( modal ).toBeVisible();

	// A name another organization already holds. The server refuses it, and
	// the refusal has to be readable: the error used to render in the page
	// behind the overlay, so the modal sat there looking like it had done
	// nothing at all.
	await modal.getByLabel( 'Organization name' ).fill( 'Bright Angle Media' );
	await modal.getByRole( 'button', { name: 'Rename' } ).click();

	await expect( modal ).toBeVisible();
	await expect( modal.getByText( /already in use/i ) ).toBeVisible();

	// And the refusal must not have renamed anything.
	await modal.getByRole( 'button', { name: 'Cancel' } ).click();
	await expect( await names( page ) ).toContain( 'ZEPHYR OUTDOOR CO' );
} );

test( 'a successful rename closes the modal and updates the row', async ( {
	page,
} ) => {
	await openScreen( page );

	await rowAction( page, 'ZEPHYR OUTDOOR CO', 'Rename' );

	const modal = page.getByRole( 'dialog' );

	await modal
		.getByLabel( 'Organization name' )
		.fill( 'Zephyr Outdoor Group' );
	await modal.getByRole( 'button', { name: 'Rename' } ).click();

	// Closing only on success is the other half of the error fix; a modal that
	// closed regardless dismissed the dialog over its own error message.
	await expect( modal ).toBeHidden();
	await expect
		.poll( async () => await names( page ) )
		.toContain( 'ZEPHYR OUTDOOR GROUP' );

	// Put the fixture back, so this spec can run twice against one site.
	await rowAction( page, 'ZEPHYR OUTDOOR GROUP', 'Rename' );
	await page
		.getByRole( 'dialog' )
		.getByLabel( 'Organization name' )
		.fill( 'Zephyr Outdoor Co' );
	await page
		.getByRole( 'dialog' )
		.getByRole( 'button', { name: 'Rename' } )
		.click();
	await expect( page.getByRole( 'dialog' ) ).toBeHidden();
} );
