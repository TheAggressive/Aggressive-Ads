<?php
/**
 * The preview frame's two drawings of one contract.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Two surfaces draw this frame — the advertiser's portal in PHP and the
 * reviewer's screen in React — and neither may decide the isolation for
 * itself.
 *
 * This is the lane `check-client-contract` exists for, applied by hand
 * because these halves are a template and a component rather than a slot's
 * context keys: it does not test behaviour, it tests that both sides still
 * take the sandbox and the widths from the server rather than writing their
 * own. A preview that quietly rendered with a permissive sandbox would look
 * exactly like one that did not.
 */
final class DevicePreviewContractTest extends TestCase {

	/**
	 * One source file, as text.
	 *
	 * @param string $relative Path under the plugin root.
	 * @return string
	 */
	private function source( string $relative ): string {
		$contents = file_get_contents( AGGR_PLUGIN_DIR . $relative );

		$this->assertIsString( $contents, $relative . ' could not be read, so these assertions cover nothing.' );

		return $contents;
	}

	/**
	 * The portal frames the stored revision, sandboxed, from the domain.
	 *
	 * @return void
	 */
	public function test_the_portals_preview_is_a_sandboxed_frame(): void {
		$partial = $this->source( 'templates/portal/partials/campaign-device-preview.php' );

		$this->assertStringContainsString( '<iframe', $partial, 'The preview stopped being a frame, so its sandbox is not asserted anywhere.' );
		$this->assertStringContainsString( 'sandbox="<?php echo esc_attr( Preview_Frame::SANDBOX ); ?>"', $partial );
		$this->assertStringContainsString( 'Preview_Frame::widths()', $partial, 'The widths were retyped instead of taken from the domain.' );

		/*
		 * The exact revision, from the route that authorizes it — never a copy
		 * made to look at, which P17 forbids, and never a stored path.
		 */
		$this->assertStringContainsString( "esc_url( (string) \$aggr_creative['preview_frame'] )", $partial );
		$this->assertStringNotContainsString( 'private_path', $partial );
	}

	/**
	 * Both frames load the document route, not the artwork.
	 *
	 * The route that answers with a document was added and nothing was pointed
	 * at it, so both frames went on loading the bytes directly — and a browser
	 * handed bare artwork writes its own viewer around it, script and all,
	 * which the policy on those bytes refuses once per frame. The preview
	 * rendered; the console filled up, and the wizard's "no page errors"
	 * assertion is what finally said so.
	 *
	 * @return void
	 */
	public function test_both_frames_load_the_preview_document_not_the_bytes(): void {
		foreach ( array( 'inc/Portal/class-creative-view-data.php', 'inc/Admin/class-review-data.php' ) as $relative ) {
			$this->assertStringContainsString(
				"'/creatives/' . \$creative_id . '/preview'",
				$this->source( $relative ),
				$relative . ' stopped offering a document for the frame to load.'
			);
		}

		$this->assertStringContainsString(
			'src={ creative.preview_frame }',
			$this->source( 'src/admin/review/preview.tsx' ) . $this->source( 'src/admin/review/campaign.tsx' ),
			"The reviewer's frame went back to loading the artwork directly."
		);
	}

	/**
	 * The reviewer's screen takes both from the server, and writes neither.
	 *
	 * @return void
	 */
	public function test_the_reviewers_preview_takes_its_isolation_from_the_server(): void {
		$component = $this->source( 'src/admin/review/preview.tsx' );

		$this->assertStringContainsString( 'sandbox={ sandbox }', $component, "The reviewer's frame stopped using the sandbox the server sends." );
		$this->assertStringContainsString( 'widths[ chosen ]', $component );

		// No numbers of its own: a phone is 390 pixels in one place only.
		$this->assertDoesNotMatchRegularExpression(
			'/\b(390|834|1280)\b/',
			$component,
			'A width was written into the reviewer\'s component; the server sends them.'
		);

		$screen = $this->source( 'inc/Admin/class-review-screen.php' );

		$this->assertStringContainsString( 'Preview_Frame::widths()', $screen );
		$this->assertStringContainsString( 'Preview_Frame::SANDBOX', $screen );
	}

	/**
	 * The bytes are served under the policy, wherever they are framed.
	 *
	 * @return void
	 */
	public function test_the_file_route_sends_the_preview_policy(): void {
		$controller = $this->source( 'inc/REST/class-creative-file-controller.php' );

		$this->assertStringContainsString( "'Content-Security-Policy', Preview_Frame::POLICY", $controller );
		$this->assertStringContainsString( "'X-Frame-Options', 'SAMEORIGIN'", $controller );
	}
}
