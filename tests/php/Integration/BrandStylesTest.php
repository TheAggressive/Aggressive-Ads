<?php
/**
 * Saved brand colours reaching the portal page.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Assets\Brand_Styles;
use Aggressive\Ads\Core\Settings;
use Aggressive\Ads\Plugin;
use WP_UnitTestCase;

/**
 * The brand section only matters if what it saves is what the page prints.
 *
 * Nothing tested this. A site whose saved colours never reached the page, or
 * reached it without the label ink the buttons are set in, would pass every
 * settings test — they all stop at the stored document.
 */
final class BrandStylesTest extends WP_UnitTestCase {

	/**
	 * Settings document.
	 *
	 * @var Settings
	 */
	private Settings $settings;

	/**
	 * Resolves services and queues the portal stylesheet the override attaches to.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->settings = Plugin::instance()->container()->get( Settings::class );

		delete_option( Settings::OPTION );
		wp_enqueue_style( Assets::HANDLE, 'https://example.test/portal.css', array(), '1' );
		wp_styles()->add_data( Assets::HANDLE, 'after', array() );
	}

	/**
	 * Clears the queued stylesheet.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		wp_dequeue_style( Assets::HANDLE );
		wp_deregister_style( Assets::HANDLE );

		parent::tear_down();
	}

	/**
	 * Saved colours print as tokens, with graphite labels on the orange.
	 *
	 * @return void
	 */
	public function test_saved_colours_reach_the_page(): void {
		$document                           = $this->settings->get();
		$document['brand']['accent']        = '#f05a28';
		$document['brand']['accent_strong'] = '#b5401a';
		$document['brand']['canvas']        = '#f7f7f5';

		$this->assertTrue( $this->settings->save( $document ) );

		$css = $this->printed();

		$this->assertStringContainsString( '--aggr-color-accent:#f05a28;', $css );
		$this->assertStringContainsString( '--aggr-color-accent-strong:#b5401a;', $css );
		$this->assertStringContainsString( '--aggr-color-canvas:#f7f7f5;', $css );
		$this->assertStringContainsString( '--aggr-color-on-accent:#111214;', $css, 'Button labels on the orange must be graphite; white is 3.4:1.' );
		$this->assertStringContainsString( '--aggr-color-primary:#111214;--aggr-color-on-primary:#ffffff;', $css, 'Primary buttons follow the saved text colour with a readable label.' );
	}

	/**
	 * A dark accent gets white labels without anyone setting them.
	 *
	 * @return void
	 */
	public function test_a_dark_accent_prints_white_labels(): void {
		$document                    = $this->settings->get();
		$document['brand']['accent'] = '#8e1f1f';

		$this->assertTrue( $this->settings->save( $document ) );
		$this->assertStringContainsString( '--aggr-color-on-accent:#ffffff;', $this->printed() );
	}

	/**
	 * **A refused save changes nothing on the page.** The negative half: a
	 * contrast failure must not reach the stored document or the tokens.
	 *
	 * @return void
	 */
	public function test_a_refused_palette_never_prints(): void {
		$document                    = $this->settings->get();
		$document['brand']['accent'] = '#f05a28';
		$this->assertTrue( $this->settings->save( $document ) );

		$refused                    = $document;
		$refused['brand']['accent'] = '#7a7a7a';

		$result = $this->settings->save( $refused );

		$this->assertWPError( $result );
		$this->assertStringContainsString( 'accent is not readable behind button text', $result->get_error_message(), 'A refused palette has to say why, not only that it failed.' );
		$this->assertStringContainsString( '--aggr-color-accent:#f05a28;', $this->printed() );
		$this->assertStringNotContainsString( '#7a7a7a', $this->printed() );
	}

	/**
	 * With nothing saved, the stylesheet's own defaults stand.
	 *
	 * @return void
	 */
	public function test_nothing_prints_without_a_saved_document(): void {
		$this->assertSame( '', $this->printed() );
	}

	/**
	 * The inline CSS Brand_Styles attaches to the portal stylesheet.
	 *
	 * @return string
	 */
	private function printed(): string {
		wp_styles()->add_data( Assets::HANDLE, 'after', array() );

		Plugin::instance()->container()->get( Brand_Styles::class )->print();

		$after = wp_styles()->get_data( Assets::HANDLE, 'after' );

		return is_array( $after ) ? implode( '', $after ) : '';
	}
}
