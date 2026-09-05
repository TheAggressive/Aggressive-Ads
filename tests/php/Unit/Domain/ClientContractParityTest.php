<?php
/**
 * The server and the browser have to name the same things.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Refresh_Policy;
use Aggressive\Ads\Domain\Slot_Options;
use PHPUnit\Framework\TestCase;

/**
 * A write half and a read half that never meet in a test will keep shipping
 * as two complete, green, useless pieces. Frequency capping did it
 * (`get_count()` and no `increment()`). P15 did it twice in one slice:
 * `maxRefreshes` left the server and `view.js` never read it; `n` landed on
 * the fill route and `fillSlot` never sent it.
 *
 * This reads the keys `resolved_context()` actually emits — not a list kept
 * beside them — and the client files that have to honour them. A key with
 * no reader, or a sequence the server reads and the client never writes,
 * fails here rather than on a page that looks fine and counts wrong.
 */
final class ClientContractParityTest extends TestCase {

	/**
	 * Runtime client files that may read slot context.
	 *
	 * The editor is not one of them: it reads block attributes, not the
	 * resolved context the wrapper emits.
	 */
	private const CLIENT_FILES = array(
		'src/blocks-interactivity/ad-slot/view.js',
		'src/blocks-interactivity/ad-slot/fill.js',
		'src/blocks-interactivity/ad-slot/empty.js',
		'src/blocks-interactivity/ad-slot/rotation.js',
	);

	/**
	 * Every key the server puts on the wrapper has a client reader.
	 *
	 * @return void
	 */
	public function test_every_context_key_has_a_client_reader(): void {
		$keys   = array_keys(
			Slot_Options::defaults()->resolved_context(
				Refresh_Policy::from_stored( true, 30, 6 )
			)
		);
		$client = $this->client_source();
		$read   = 0;

		$this->assertGreaterThanOrEqual(
			4,
			count( $keys ),
			'resolved_context() shrank; confirm a key was removed on purpose.'
		);

		foreach ( $keys as $key ) {
			$this->assertMatchesRegularExpression(
				'/context(?:\?)?\.' . preg_quote( (string) $key, '/' ) . '\b/',
				$client,
				'The server sends ' . $key . ' and no runtime client file reads context.' . $key . '.'
			);
			++$read;
		}

		$this->assertSame( count( $keys ), $read );
	}

	/**
	 * The sequence the fill route reads is the sequence the client writes.
	 *
	 * Sending `n=0` on every tick would satisfy a weaker "the param is set"
	 * check and still file every rotation as a page opportunity. The view
	 * store has to pass the incrementing counter.
	 *
	 * @return void
	 */
	public function test_the_sequence_the_server_reads_is_sent_by_the_client(): void {
		$controller = $this->without_comments(
			(string) file_get_contents( AGGR_PLUGIN_DIR . 'inc/REST/class-fill-controller.php' )
		);
		$fill       = $this->without_comments(
			(string) file_get_contents( AGGR_PLUGIN_DIR . 'src/blocks-interactivity/ad-slot/fill.js' )
		);
		$view       = $this->without_comments(
			(string) file_get_contents( AGGR_PLUGIN_DIR . 'src/blocks-interactivity/ad-slot/view.js' )
		);

		$this->assertMatchesRegularExpression( '/get_param\(\s*\'n\'\s*\)/', $controller );
		$this->assertMatchesRegularExpression( '/searchParams\.set\(\s*[\'"]n[\'"]/', $fill );
		$this->assertMatchesRegularExpression( '/\bsequence\b/', $fill );
		$this->assertMatchesRegularExpression( '/fillSlot\s*\(\s*[^,)]+\s*,\s*rotations\s*\)/', $view );
		$this->assertMatchesRegularExpression(
			'/rotationCap\s*\(\s*context(?:\?)?\.maxRefreshes\b/',
			$view
		);
	}

	/**
	 * Concatenated runtime client source.
	 */
	private function client_source(): string {
		$parts = array();

		foreach ( self::CLIENT_FILES as $relative ) {
			$path = AGGR_PLUGIN_DIR . $relative;

			$this->assertFileExists( $path, $relative . ' moved; this test would otherwise pass over nothing.' );

			$parts[] = $this->without_comments( (string) file_get_contents( $path ) );
		}

		return implode( "\n", $parts );
	}

	/**
	 * Source with comments removed, so a docblock that quotes a key is not a reader.
	 *
	 * @param string $source File contents.
	 */
	private function without_comments( string $source ): string {
		$stripped = preg_replace( '#/\*.*?\*/#s', '', $source );
		$stripped = preg_replace( '#^\s*//.*$#m', '', is_string( $stripped ) ? $stripped : $source );

		return is_string( $stripped ) ? $stripped : $source;
	}
}
