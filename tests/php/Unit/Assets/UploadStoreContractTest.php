<?php
/**
 * The upload store's two halves, read together.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Every value PHP puts on an upload slot, the browser must read.
 *
 * This is the client-contract lane's argument applied to the portal: a write
 * half and a read half that never meet in a test will drift, and the drift is
 * silent. `maxRefreshes` left the server and `view.js` never read it; the
 * shared `size` message named a two-megabyte ceiling on every placement while
 * each one enforced its own limit. Nothing failed either time.
 *
 * It asserts that the halves name each other, not that they behave.
 */
final class UploadStoreContractTest extends TestCase {

	private const SERVER = 'inc/Assets/class-assets.php';
	private const CLIENT = 'src/interactivity/upload.ts';

	/**
	 * Reads one project file, failing loudly rather than covering nothing.
	 *
	 * @param string $relative Path from the plugin root.
	 * @return string
	 */
	private function source( string $relative ): string {
		$contents = file_get_contents( AGGR_PLUGIN_DIR . $relative );

		$this->assertIsString( $contents, $relative . ' could not be read, so these assertions cover nothing.' );

		return $contents;
	}

	/**
	 * The keys the server writes into one upload slot's state.
	 *
	 * @return array<int, string>
	 */
	private function server_keys(): array {
		$php = $this->source( self::SERVER );

		$this->assertSame(
			1,
			preg_match( '/\$uploads\[.+?\]\s*=\s*array\((.*?)\n\t\t\t\);/s', $php, $block ),
			'The upload state block was not found, so this lane is reading nothing. Update the pattern rather than deleting the test.'
		);

		$this->assertGreaterThan(
			0,
			preg_match_all( "/'([A-Za-z]+)'\s*=>/", $block[1], $keys ),
			'No keys were found inside the upload state block.'
		);

		return $keys[1];
	}

	/**
	 * Nothing is put on a slot that the browser does not read.
	 *
	 * @return void
	 */
	public function test_every_served_upload_key_is_read_by_the_module(): void {
		$client = $this->source( self::CLIENT );
		$keys   = $this->server_keys();

		// A count, not just absence: a pattern that silently stopped matching
		// would otherwise assert nothing at all and report success.
		$this->assertGreaterThanOrEqual( 5, count( $keys ), 'Fewer keys than the slot is known to carry — the pattern has stopped matching.' );

		foreach ( $keys as $key ) {
			/*
			 * Whole word. A substring match passed over `sizeMessage` being
			 * renamed to `sizeMessageUnused` on the client, which is the exact
			 * drift this lane exists to catch — the guard reported success
			 * over a store key nothing read.
			 */
			$this->assertSame(
				1,
				preg_match( '/\b' . preg_quote( $key, '/' ) . '\b/', $client ) > 0 ? 1 : 0,
				sprintf( 'The server puts "%s" on every upload slot and %s never reads it.', $key, self::CLIENT )
			);
		}
	}

	/**
	 * The per-placement size message exists on both sides.
	 *
	 * Named on its own because it is the one that replaced a shared string:
	 * deleting either half leaves a working uploader that quotes the wrong
	 * number, which is the failure mode no behavioural test noticed.
	 *
	 * @return void
	 */
	public function test_the_size_message_is_per_placement_on_both_sides(): void {
		$php    = $this->source( self::SERVER );
		$client = $this->source( self::CLIENT );

		$this->assertSame( 1, preg_match_all( "/'sizeMessage'\s*=>/", $php ) );
		$this->assertSame( 1, preg_match( '/\bsizeMessage\b/', $client ) );

		/*
		 * The shared map must not carry a `size` message at all. Reintroducing
		 * one there would restore the original defect exactly: a single
		 * sentence naming one number, shown on placements enforcing others.
		 * Asserting on the map rather than on the sentence is what makes this
		 * survive a rewording.
		 */
		$this->assertSame(
			1,
			preg_match( "/self::UPLOAD_STORE,\n(.*?)\n\t\t\t\);/s", $php, $store ),
			'The upload store block was not found, so this assertion covers nothing.'
		);

		$this->assertSame(
			1,
			preg_match_all( "/'pixels'\s*=>/", $store[1] ),
			'The shared map has stopped containing the keys this lane expects to see beside the one it forbids.'
		);
		$this->assertSame(
			0,
			preg_match_all( "/'size'\s*=>/", $store[1] ),
			'A shared "size" message is back. It names one number on placements that enforce others.'
		);
	}
	/**
	 * The server decides what a saved page has to change, and says so.
	 *
	 * **The rule the first two versions broke, twice.** The first saved
	 * asynchronously and then navigated to the redirect anyway — a hard
	 * refresh, the old banner, and the toast gone before it could be read.
	 * The second patched from the submitted form, which is right only while a
	 * write changes nothing but its own field; a share is a percentage of the
	 * other creatives on the placement, so saving one restates all of them.
	 *
	 * Both halves are asserted. The module falling back when the map is empty
	 * is what keeps a toast from appearing over stale values; a server that
	 * sends no map would otherwise look identical to one that had nothing to
	 * change.
	 *
	 * @return void
	 */
	public function test_the_server_says_what_a_saved_page_must_change(): void {
		$module = $this->source( 'src/interactivity/save.ts' );

		// The response half moved out of `Creative_Actions` when that file was
		// split; it is the same code, and this reads it where it lives now.
		$actions = $this->source( 'inc/Portal/class-creative-feedback.php' );

		$this->assertSame(
			1,
			preg_match( "/'aggr_async'/", $module ),
			'The module stopped marking its posts, so every wired form falls back to a page load.'
		);
		$this->assertSame(
			1,
			preg_match( '/form\[data-aggr-save\]/', $module ),
			'The module stopped looking for the attribute the templates carry.'
		);
		$this->assertSame(
			1,
			preg_match( '/if \( ! applyPatch\( payload\.patch \) \) \{/', $module ),
			'A toast can now appear over values the page never updated.'
		);
		$this->assertStringContainsString(
			'(object) $patch',
			$actions,
			'The response stopped carrying the map the client applies.'
		);

		/*
		 * Text, never markup. A response that could insert HTML is one that
		 * can inject, and the dialogs on this screen are printed separately
		 * in the footer — swapping a card's markup would leave every trigger
		 * inside it pointing at nothing.
		 */

		/*
		 * Code, not prose. Reading the raw file made this fail on a comment
		 * that named the very thing it forbids — the same mistake
		 * `check-form-endpoints` already avoids by skipping comment lines.
		 */
		$aggr_code = implode(
			"\n",
			array_filter(
				explode( "\n", $module ),
				static fn ( string $line ): bool => 1 !== preg_match( '#^\s*(//|\*|/\*)#', $line )
			)
		);

		$this->assertSame(
			0,
			preg_match( '/innerHTML|insertAdjacentHTML/', $aggr_code ),
			'The patch path can write markup into the page.'
		);

		$wired = 0;

		foreach ( glob( AGGR_PLUGIN_DIR . 'templates/portal/partials/*.php' ) as $partial ) {
			$wired += substr_count( (string) file_get_contents( $partial ), 'data-aggr-save=' );
		}

		// A count, not just presence: a renamed attribute would otherwise
		// leave this lane looping over nothing and reporting success.
		$this->assertGreaterThanOrEqual( 3, $wired, 'Fewer forms save asynchronously than were wired when this was written.' );
	}
}
