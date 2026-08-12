<?php
/**
 * Colour contrast in the portal stylesheet.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

namespace LAAO_Advertiser_Portal\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * Every colour pair the portal can render, measured against WCAG 2.2 AA.
 *
 * Contrast is the one accessibility property that is fully decidable from the
 * source, and the one that silently regresses: somebody nudges a token to match
 * a mock and nine pairings move with it. The design's own palette arrived with
 * six failures — the status pills between 2.61:1 and 4.35:1, and pill text is
 * 12px bold, which WCAG does not count as large. They were darkened to pass;
 * this file is what stops them drifting back.
 *
 * Every threshold here is 4.5:1, the small-text ratio. Nothing in the portal is
 * large text as WCAG defines it (18.66px bold or 24px), so nothing qualifies
 * for the 3:1 allowance.
 */
final class PortalContrastTest extends TestCase {

	/**
	 * WCAG 2.2 AA, normal text.
	 */
	private const AA_NORMAL = 4.5;

	/**
	 * WCAG 2.2 AA, user interface components and graphical objects.
	 */
	private const AA_NON_TEXT = 3.0;

	/**
	 * The token values, parsed from the stylesheet.
	 *
	 * @var array<string, string>
	 */
	private array $tokens = array();

	/**
	 * Reads the stylesheet once per test.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$css = file_get_contents( LAAO_ADS_PLUGIN_DIR . 'assets/portal.css' );

		$this->assertIsString( $css, 'assets/portal.css must be readable.' );

		$matches = array();

		preg_match_all( '/(--laao-ads-color-[\w-]+):\s*(#[0-9a-fA-F]{6})\s*;/', $css, $matches, PREG_SET_ORDER );

		foreach ( $matches as $match ) {
			$this->tokens[ $match[1] ] = strtolower( $match[2] );
		}

		$this->assertNotSame( array(), $this->tokens, 'No colour tokens found — has the stylesheet moved?' );
	}

	/**
	 * The relative luminance of a hex colour, per WCAG.
	 *
	 * @param string $hex Six-digit hex colour with a leading hash.
	 * @return float
	 */
	private function luminance( string $hex ): float {
		$channels = array(
			hexdec( substr( $hex, 1, 2 ) ) / 255,
			hexdec( substr( $hex, 3, 2 ) ) / 255,
			hexdec( substr( $hex, 5, 2 ) ) / 255,
		);

		foreach ( $channels as $index => $value ) {
			$channels[ $index ] = $value <= 0.04045
				? $value / 12.92
				: ( ( $value + 0.055 ) / 1.055 ) ** 2.4;
		}

		return ( 0.2126 * $channels[0] ) + ( 0.7152 * $channels[1] ) + ( 0.0722 * $channels[2] );
	}

	/**
	 * The contrast ratio between two tokens.
	 *
	 * @param string $foreground Token name, without the --laao-ads-color- prefix.
	 * @param string $background Token name, without the --laao-ads-color- prefix.
	 * @return float
	 */
	private function ratio( string $foreground, string $background ): float {
		$fg = '--laao-ads-color-' . $foreground;
		$bg = '--laao-ads-color-' . $background;

		$this->assertArrayHasKey( $fg, $this->tokens, "No such token: {$fg}" );
		$this->assertArrayHasKey( $bg, $this->tokens, "No such token: {$bg}" );

		$light = $this->luminance( $this->tokens[ $fg ] );
		$dark  = $this->luminance( $this->tokens[ $bg ] );

		if ( $dark > $light ) {
			list( $light, $dark ) = array( $dark, $light );
		}

		return ( $light + 0.05 ) / ( $dark + 0.05 );
	}

	/**
	 * Asserts a pair clears a threshold, and says by how much when it does not.
	 *
	 * @param string $foreground Foreground token.
	 * @param string $background Background token.
	 * @param float  $minimum    Required ratio.
	 * @return void
	 */
	private function assertContrast( string $foreground, string $background, float $minimum ): void {
		$ratio = $this->ratio( $foreground, $background );

		$this->assertGreaterThanOrEqual(
			$minimum,
			$ratio,
			sprintf(
				'%s on %s is %.2f:1, below the required %.1f:1.',
				$foreground,
				$background,
				$ratio,
				$minimum
			)
		);
	}

	/**
	 * Any ink may land on any light surface, so all of them are checked.
	 *
	 * The cross product rather than the pairs currently used: a template that
	 * puts muted text on the inset surface for the first time should not be the
	 * thing that discovers the pairing fails.
	 *
	 * @return void
	 */
	public function test_every_ink_reads_on_every_light_surface(): void {
		$inks     = array( 'text', 'text-body', 'text-muted', 'text-subtle' );
		$surfaces = array( 'canvas', 'surface', 'surface-raised', 'surface-sunken', 'surface-inset' );

		foreach ( $inks as $ink ) {
			foreach ( $surfaces as $surface ) {
				$this->assertContrast( $ink, $surface, self::AA_NORMAL );
			}
		}
	}

	/**
	 * The rail is dark, and its own inks are checked against its own surfaces.
	 *
	 * @return void
	 */
	public function test_rail_text_reads_on_the_rail(): void {
		$inks     = array( 'rail-text', 'rail-text-strong' );
		$surfaces = array( 'rail', 'rail-active', 'rail-chip' );

		foreach ( $inks as $ink ) {
			foreach ( $surfaces as $surface ) {
				$this->assertContrast( $ink, $surface, self::AA_NORMAL );
			}
		}
	}

	/**
	 * Each status pill's word reads on its own tint.
	 *
	 * Status is carried by the word, never by the colour alone — but the word
	 * has to be legible for that to mean anything.
	 *
	 * @return void
	 */
	public function test_every_status_pill_reads_on_its_tint(): void {
		foreach ( array( 'live', 'pending', 'ended', 'danger', 'neutral' ) as $status ) {
			$this->assertContrast( $status, $status . '-tint', self::AA_NORMAL );
		}
	}

	/**
	 * Button text reads on the button.
	 *
	 * @return void
	 */
	public function test_button_text_reads_on_the_accent(): void {
		$this->assertContrast( 'accent-contrast', 'accent-strong', self::AA_NORMAL );
	}

	/**
	 * Account-entry links use the darker red that remains readable on white.
	 *
	 * @return void
	 */
	public function test_account_links_read_on_the_panel(): void {
		$this->assertContrast( 'accent-strong', 'surface', self::AA_NORMAL );
	}

	/**
	 * The accent is distinguishable where it marks state rather than carries text.
	 *
	 * @return void
	 */
	public function test_the_accent_is_visible_against_the_rail(): void {
		$this->assertContrast( 'accent', 'rail-active', self::AA_NON_TEXT );
		$this->assertContrast( 'accent', 'surface', self::AA_NON_TEXT );
	}
}
