<?php
/**
 * Colour contrast in the staff admin palette.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Assets;

use Aggressive\Ads\Domain\Contrast;
use PHPUnit\Framework\TestCase;

/**
 * Every colour pair the staff screens can render, measured against WCAG 2.2 AA.
 *
 * The staff surface resolves the shared --aggr-* tokens to WordPress's own
 * admin palette, and **core's palette is not automatically AA at small sizes**.
 * Its primary blue on white is 4.6:1, which passes with almost nothing spare,
 * and its notice green on its notice tint is around 2.8:1, which does not pass
 * at all — the ink there had to be darkened. Borrowing a palette is not the
 * same as inheriting its accessibility, so this file measures the borrowed one
 * exactly as PortalContrastTest measures the product's own.
 *
 * Every threshold is 4.5:1, the small-text ratio. Nothing on these screens is
 * large text as WCAG defines it (18.66px bold or 24px).
 */
final class AdminContrastTest extends TestCase {

	/**
	 * The token values, parsed from the admin token layer.
	 *
	 * @var array<string, string>
	 */
	private array $tokens = array();

	/**
	 * Reads the token partial once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$css = file_get_contents( AGGR_PLUGIN_DIR . 'src/styles/base/_admin-tokens.css' );

		$this->assertIsString( $css, 'src/styles/base/_admin-tokens.css must be readable.' );

		$matches = array();

		preg_match_all( '/(--aggr-(?:color-[\w-]+|border-color[\w-]*)):\s*(#[0-9a-fA-F]{6})\s*;/', $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$this->tokens[ $match[1] ] = strtolower( $match[2] );
		}

		$this->assertNotSame( array(), $this->tokens, 'No colour tokens found — has the partial moved?' );
	}

	/**
	 * The override is complete: every colour token the screens use is defined.
	 *
	 * A token left out does not fail visibly. It silently falls back to the
	 * portal's warm palette, and the screen renders one maroon control in a
	 * field of admin grey — which reads as a bug rather than as a theme.
	 *
	 * @return void
	 */
	public function test_every_colour_token_the_staff_screens_use_is_overridden(): void {
		$used = array();

		foreach ( array( 'admin.css', 'components/_surfaces.css', 'layout/_chrome.css' ) as $file ) {
			$css = file_get_contents( AGGR_PLUGIN_DIR . 'src/styles/' . $file );

			$this->assertIsString( $css );

			$found = array();
			preg_match_all( '/var\((--aggr-(?:color-[\w-]+|border-color[\w-]*))\)/', $css, $found );

			$used = array_merge( $used, $found[1] );
		}

		$missing = array_diff( array_unique( $used ), array_keys( $this->tokens ) );

		$this->assertSame(
			array(),
			array_values( $missing ),
			'These colour tokens are used by the staff screens but not overridden: ' . implode( ', ', $missing )
		);
	}

	/**
	 * Every ink reads on every light surface.
	 *
	 * @return void
	 */
	public function test_every_ink_reads_on_every_light_surface(): void {
		$inks     = array( 'text', 'text-body', 'text-muted', 'text-subtle' );
		$surfaces = array( 'canvas', 'surface', 'surface-raised', 'surface-sunken', 'surface-inset' );

		foreach ( $inks as $ink ) {
			foreach ( $surfaces as $surface ) {
				$this->assertContrast( $ink, $surface );
			}
		}
	}

	/**
	 * Each status pill's word reads on its own tint.
	 *
	 * @return void
	 */
	public function test_every_status_pill_reads_on_its_tint(): void {
		foreach ( array( 'live', 'pending', 'ended', 'danger', 'neutral' ) as $status ) {
			$this->assertContrast( $status, $status . '-tint' );
		}
	}

	/**
	 * Links and primary buttons read where they are drawn.
	 *
	 * @return void
	 */
	public function test_the_accent_reads_as_text_and_as_a_button(): void {
		// Approval is drawn on the live green rather than the accent blue, so
		// its label is measured against that colour and not assumed from the
		// pill it shares a token with — a pill puts dark ink on a tint, and a
		// button puts white ink on the ink.
		$this->assertContrast( 'accent-contrast', 'live' );
		$this->assertContrast( 'accent', 'surface' );
		$this->assertContrast( 'accent', 'canvas' );
		$this->assertContrast( 'accent-strong', 'surface' );
		$this->assertContrast( 'accent-contrast', 'accent' );
		$this->assertContrast( 'accent-contrast', 'accent-strong' );
	}

	/**
	 * Rail inks read on the rail, for any component that reaches for one.
	 *
	 * @return void
	 */
	public function test_rail_text_reads_on_the_rail(): void {
		foreach ( array( 'rail-text', 'rail-text-strong' ) as $ink ) {
			foreach ( array( 'rail', 'rail-active', 'rail-chip' ) as $surface ) {
				$this->assertContrast( $ink, $surface );
			}
		}
	}

	/**
	 * Rules are visible against what they separate.
	 *
	 * Non-text, so 3:1 rather than 4.5:1 — a border is a boundary, not a word.
	 *
	 * @return void
	 */
	public function test_borders_are_visible_against_their_surfaces(): void {
		$this->assertContrast( 'border-color-strong', 'surface', Contrast::AA_NON_TEXT, '--aggr-border-color-strong' );
	}

	/**
	 * Asserts one pair clears a threshold, naming both sides when it does not.
	 *
	 * @param string $foreground Token suffix, or a full token name.
	 * @param string $background Token suffix, or a full token name.
	 * @param float  $minimum    Required ratio.
	 * @param string $fg_name    Full foreground token name, when not a colour-.
	 * @return void
	 */
	private function assertContrast(
		string $foreground,
		string $background,
		float $minimum = Contrast::AA_NORMAL,
		string $fg_name = ''
	): void {
		$fg_key = '' === $fg_name ? '--aggr-color-' . $foreground : $fg_name;
		$bg_key = '--aggr-color-' . $background;

		$this->assertArrayHasKey( $fg_key, $this->tokens, $fg_key . ' is not defined.' );
		$this->assertArrayHasKey( $bg_key, $this->tokens, $bg_key . ' is not defined.' );

		$ratio = Contrast::ratio( $this->tokens[ $fg_key ], $this->tokens[ $bg_key ] );

		$this->assertGreaterThanOrEqual(
			$minimum,
			$ratio,
			sprintf(
				'%s (%s) on %s (%s) is %.2f:1, below %.1f:1.',
				$fg_key,
				$this->tokens[ $fg_key ],
				$bg_key,
				$this->tokens[ $bg_key ],
				$ratio,
				$minimum
			)
		);
	}
}
