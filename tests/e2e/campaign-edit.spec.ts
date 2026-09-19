import { expect, test, type Page } from '@playwright/test';
import { expectNoHorizontalOverflow, expectPortalA11y } from './accessibility';
import { signIn } from './sign-in-helper';
import { solidPng } from './png';
import { wp, wpPluginFile } from './wp-cli';

/*
 * Editing a running campaign, in a real browser.
 *
 * The integration suite renders these screens and drives the workflow, and
 * Jest drives the scripts in jsdom. Neither is a browser: last time the two
 * agreed and a browser did not, the difference was an `<input name="action">`
 * shadowing `form.action`. This is the round trip they cannot make — real
 * forms, real module loading, the link staged and then checked, the heading
 * renamed into the proposal, an ad uploaded to an empty size.
 */

type Seed = {
	campaign: number;
	launch: number;
	premium: number;
	liveEdits: Record< string, boolean >;
};

let seed: Seed;
let original: Record< string, boolean > | null = null;

// Fresh for each test: the first submits its proposal, which closes the flow.
test.beforeEach( () => {
	seed = JSON.parse(
		wpPluginFile( 'tests/e2e/seed-edit-campaign.php' ).trim()
	) as Seed;

	// The first seed saw the site's own switches; later ones see the test's.
	original ??= seed.liveEdits;
} );

// The site's own switches go back as they were found.
test.afterAll( () => {
	if ( null === original ) {
		return;
	}

	wp(
		'eval',
		`$s = \\Aggressive\\Ads\\Plugin::instance()->container()->get( \\Aggressive\\Ads\\Core\\Settings::class );
		$d = $s->get();
		$d["live_edits"] = json_decode( base64_decode( "${ Buffer.from(
			JSON.stringify( original )
		).toString( 'base64' ) }" ), true );
		$s->save( $d );`
	);
} );

const editUrl = ( step = '' ) =>
	`/advertiser/campaigns/${ seed.campaign }/?edit=1${
		step ? `&step=${ step }` : ''
	}`;

function pending(): Record< string, unknown > {
	return JSON.parse(
		wp(
			'eval',
			`echo wp_json_encode( \\Aggressive\\Ads\\Plugin::instance()->container()->get( \\Aggressive\\Ads\\Repository\\Campaign_Request_Repository::class )->pending_edits( ${ seed.campaign } ) );`
		).trim() || '{}'
	) as Record< string, unknown >;
}

async function signInAsAdvertiser( page: Page ): Promise< void > {
	await page.goto( '/advertiser/' );
	await signIn( page, 'advertiser@example.test', 'advertiser' );
}

test( 'a running campaign is upgraded, relinked and renamed on creation’s screens', async ( {
	page,
} ) => {
	await signInAsAdvertiser( page );
	await page.goto( editUrl() );

	// Creation's package grid, with the package running now marked.
	const launch = page.getByRole( 'radio', { name: /E2E Launch/ } );
	const premium = page.getByRole( 'radio', { name: /E2E Premium/ } );

	await expect( launch ).toBeChecked();
	await expect(
		page.locator( 'label', { has: launch } ).getByText( 'Your package' )
	).toBeVisible();

	// Placements come with the package, as at creation: nothing to tick.
	await expect( page.locator( 'input[name="placement_ids[]"]' ) ).toHaveCount(
		0
	);

	// A fixed package states its end instead of asking for one.
	const through = page.locator( '#aggr-run-through' );
	await expect( page.locator( '#aggr-end-date' ) ).toBeHidden();
	await expect( through ).toBeVisible();
	const before = await through.textContent();

	await premium.check();
	await expect( through ).not.toHaveText( before ?? '' );
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await expect( page ).toHaveURL( /step=destination/ );
	expect( pending().package_id ).toBe( seed.premium );

	// The size the upgrade adds is shown, and not offered an upload yet.
	const skyscraper = page.getByRole( 'region', { name: 'E2E skyscraper' } );
	await expect(
		skyscraper.getByText( 'Added by your change' )
	).toBeVisible();
	await expect( skyscraper.locator( 'input[type="file"]' ) ).toHaveCount( 0 );

	/*
	 * The link check stages the link first — the form's own handler, asked
	 * for JSON — then asks about the staged link. The check itself is answered
	 * here: whether example.com answers from this machine is not the question.
	 */
	const staged = page.waitForRequest(
		( request ) =>
			request.method() === 'POST' &&
			request.url().includes( 'admin-post.php' ) &&
			( request.postData() ?? '' ).includes( 'aggr_async' )
	);

	await page.route( /link-check\?proposed=1/, ( route ) =>
		route.fulfill( {
			status: 200,
			contentType: 'application/json',
			body: JSON.stringify( { outcome: 'works', status: 200 } ),
		} )
	);

	await page
		.getByLabel( 'Destination link for every ad' )
		.fill( 'https://example.com/e2e-autumn' );
	await page.getByRole( 'button', { name: 'Check link' } ).click();
	await staged;
	await expect(
		page.getByText( 'Link works', { exact: true } )
	).toBeVisible();
	expect( pending().default_click_url ).toBe(
		'https://example.com/e2e-autumn'
	);

	// Renamed from the heading, into the proposal, as creation renames a draft.
	await page
		.getByRole( 'heading', { level: 1 } )
		.getByRole( 'button', { name: 'E2E edit flight' } )
		.click();
	await page
		.getByRole( 'textbox', { name: 'Campaign name' } )
		.fill( 'E2E renamed flight' );
	await page.keyboard.press( 'Enter' );

	const summary = page.getByRole( 'complementary', { name: 'Your changes' } );
	await expect( summary.getByText( 'E2E renamed flight' ) ).toBeVisible();
	expect( pending().title ).toBe( 'E2E renamed flight' );

	await expectNoHorizontalOverflow( page );

	await page.getByRole( 'button', { name: 'Save and continue' } ).click();
	await expect( page ).toHaveURL( /step=review/ );

	const changes = page.locator( '.aggr-changes__list' );
	await expect( changes.getByText( 'E2E Premium' ) ).toBeVisible();
	await expect( changes.getByText( 'E2E renamed flight' ) ).toBeVisible();
	await expectPortalA11y( page );

	await page.getByRole( 'button', { name: 'Submit for review' } ).click();
	await expect( page ).not.toHaveURL( /edit=1/ );

	// Waiting for review, the campaign still runs on what it bought.
	expect(
		wp(
			'eval',
			`echo get_post_meta( ${ seed.campaign }, "_aggr_package_id", true );`
		).trim()
	).toBe( String( seed.launch ) );
} );

test( 'a size a running campaign has no ad for takes one, held for review', async ( {
	page,
} ) => {
	await signInAsAdvertiser( page );
	await page.goto( editUrl( 'destination' ) );

	const sidebar = page.getByRole( 'region', { name: 'E2E sidebar' } );
	await expect( sidebar.getByText( 'Needs a file' ) ).toBeVisible();

	// The campaign has a link, so choosing the file is the whole upload.
	const uploaded = page.waitForURL( /creative_uploaded/, {
		// At commit: the notice is tidied out of the address once the page has it.
		waitUntil: 'commit',
	} );

	await sidebar.locator( 'input[type="file"]' ).setInputFiles( {
		name: 'sidebar.png',
		mimeType: 'image/png',
		buffer: solidPng( 300, 250 ),
	} );

	/*
	 * Back on the edit flow's Ads step, not in the wizard a running campaign
	 * cannot open. Waited for rather than read: the page was already on this
	 * address, so a URL check alone passed with no upload at all.
	 */
	await uploaded;
	await expect( page ).toHaveURL( /edit=1/ );
	await expect( page ).toHaveURL( /step=destination/ );

	const filled = page.getByRole( 'region', { name: 'E2E sidebar' } );
	await expect( filled.getByText( 'Needs a file' ) ).toHaveCount( 0 );
	await expect(
		filled.getByRole( 'link', { name: /Preview/ } )
	).toBeVisible();
	await expect( filled.locator( 'input[type="file"]' ) ).toHaveCount( 0 );
} );
