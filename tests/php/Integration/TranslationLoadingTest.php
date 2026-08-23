<?php
/**
 * Shipped translation catalogs are actually loaded.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Integration;

use Aggressive\Ads\Plugin;
use WP_UnitTestCase;

/**
 * The only reliable way to know a translation reached a user.
 *
 * Every other signal lies. `load_plugin_textdomain()` returns true whether or
 * not it found a catalog. The POT can be current, the .po valid, the .mo
 * compiled and the placeholders perfect while every string on the site renders
 * in English, and nothing anywhere reports it.
 *
 * The theme these scripts came from shipped exactly that: four locales
 * compiled, packaged, and never loaded, because the filename convention for a
 * theme's own directory differs from the one `make-mo` produces. This plugin
 * takes the *other* branch in core — it needs the domain prefix — and it had a
 * second version of the same bug, because it registered no path at all.
 *
 * So these assert on `__()` output and never on a return value.
 */
final class TranslationLoadingTest extends WP_UnitTestCase {

	/**
	 * The locale this test is pretending the site runs in.
	 *
	 * **One locale per test, never shared.** `WP_Translation_Controller` caches
	 * loaded catalogs by `realpath()` for the whole request, so two tests that
	 * write different contents to the same filename get whichever one ran
	 * first. That is not hypothetical: these three passed individually and
	 * failed together until each got its own locale, which is also the only
	 * arrangement that resembles a real site.
	 *
	 * @var string
	 */
	private string $locale = '';

	/**
	 * Absolute path to the fixture .mo, removed in tear_down.
	 *
	 * @var string
	 */
	private string $catalog = '';

	/**
	 * Points the site at the fixture locale.
	 *
	 * @return void
	 */
	public function set_up(): void {
		parent::set_up();

		$this->reset_registry();

		add_filter( 'locale', array( $this, 'filter_locale' ) );
	}

	/**
	 * Chooses this test's locale and the catalog path that goes with it.
	 *
	 * @param string $locale WordPress locale code.
	 * @return void
	 */
	private function use_locale( string $locale ): void {
		$this->locale  = $locale;
		$this->catalog = AGGR_PLUGIN_DIR . 'languages/aggressive-ads-' . $locale . '.mo';
	}

	/**
	 * Replaces the global text-domain registry.
	 *
	 * `WP_Textdomain_Registry` caches the resolved directory per domain and
	 * locale — **including the negative result**. One test that deliberately
	 * has no loadable catalog therefore leaves `false` cached for every test
	 * after it in the same process, and the suite passes or fails on ordering.
	 *
	 * There is no public reset, so the registry is replaced outright. Found by
	 * writing these three tests and watching the third fail only when the
	 * second ran first.
	 *
	 * @return void
	 */
	private function reset_registry(): void {
		unload_textdomain( 'aggressive-ads' );

		$GLOBALS['wp_textdomain_registry'] = new \WP_Textdomain_Registry();
	}

	/**
	 * Removes the fixture and every trace of it from the loaded domains.
	 *
	 * @return void
	 */
	public function tear_down(): void {
		remove_filter( 'locale', array( $this, 'filter_locale' ) );

		if ( '' !== $this->catalog && is_file( $this->catalog ) ) {
			unlink( $this->catalog );
		}

		$this->reset_registry();

		parent::tear_down();
	}

	/**
	 * Locale filter callback.
	 *
	 * @return string
	 */
	public function filter_locale(): string {
		return '' === $this->locale ? 'en_US' : $this->locale;
	}

	/**
	 * Writes a minimal binary MO catalog.
	 *
	 * Built here rather than shelled out to msgfmt on purpose: this suite runs
	 * with failOnSkipped, so a test that quietly skips where gettext is absent
	 * would be a test that never runs in exactly the environments nobody
	 * watches. The format is small enough to write correctly.
	 *
	 * @param array<string, string> $messages Source string to translation.
	 * @return void
	 */
	private function write_catalog( array $messages ): void {
		ksort( $messages );

		$originals    = array_keys( $messages );
		$translations = array_values( $messages );
		$count        = count( $messages );

		// Header, then two tables of (length, offset) pairs, then the strings.
		$table_size      = $count * 8;
		$originals_at    = 28;
		$translations_at = $originals_at + $table_size;
		$strings_at      = $translations_at + $table_size;

		$original_table    = '';
		$translation_table = '';
		$blob              = '';
		$offset            = $strings_at;

		foreach ( $originals as $original ) {
			$original_table .= pack( 'VV', strlen( $original ), $offset );
			$blob           .= $original . "\0";
			$offset         += strlen( $original ) + 1;
		}

		foreach ( $translations as $translation ) {
			$translation_table .= pack( 'VV', strlen( $translation ), $offset );
			$blob              .= $translation . "\0";
			$offset            += strlen( $translation ) + 1;
		}

		$mo = pack(
			'VVVVVVV',
			0x950412de, // Little-endian magic.
			0,          // Revision.
			$count,
			$originals_at,
			$translations_at,
			0,          // Hash table size; readers may ignore it.
			$strings_at
		) . $original_table . $translation_table . $blob;

		file_put_contents( $this->catalog, $mo );
	}

	/**
	 * **A catalog in the plugin's own languages/ directory is loaded.**
	 *
	 * Delete the `load_plugin_textdomain()` call in Plugin::load_translations()
	 * and this fails, which is the whole reason it exists: just-in-time loading
	 * never looks inside a plugin's own folder, so without that call every
	 * shipped catalog is dead weight.
	 *
	 * @return void
	 */
	public function test_a_shipped_catalog_reaches_the_translation_functions(): void {
		$this->use_locale( 'en_GB' );

		$this->write_catalog(
			array(
				'Advertising' => 'Advertising [en_GB]',
				'Campaigns'   => 'Campaigns [en_GB]',
			)
		);

		$this->reset_registry();

		Plugin::instance()->load_translations();

		$this->assertSame( 'Advertising [en_GB]', __( 'Advertising', 'aggressive-ads' ) );
		$this->assertSame( 'Campaigns [en_GB]', __( 'Campaigns', 'aggressive-ads' ) );

		// Asserted in the same load rather than in a test of its own, and the
		// reason is worth recording: WordPress caches loaded catalogs per
		// request in WP_Translation_Controller, which WP_UnitTestCase does not
		// reset, so a third load of this domain in one process does not take
		// however the locale is varied. Two properties, one load, is also
		// closer to what a real page does.
		//
		// It is here at all so the assertions above cannot pass by way of some
		// accident that appends a suffix to every lookup.
		$this->assertSame(
			'Not in the catalog',
			__( 'Not in the catalog', 'aggressive-ads' ),
			'A string the catalog does not carry must fall through to its source text.'
		);
	}

	/**
	 * The filename WordPress opens is the prefixed one.
	 *
	 * A plugin path takes the `{$domain}-{$locale}.mo` branch of
	 * `_load_textdomain_just_in_time()`; a theme path takes `{$locale}.mo`.
	 * The theme scripts this pipeline was adapted from rename to the second,
	 * and porting that rename here would disable every locale silently.
	 *
	 * @return void
	 */
	public function test_the_unprefixed_theme_filename_is_not_what_gets_loaded(): void {
		$this->use_locale( 'en_CA' );

		// The theme spelling: no domain prefix.
		$this->catalog = AGGR_PLUGIN_DIR . 'languages/en_CA.mo';
		$this->write_catalog( array( 'Advertising' => 'Wrong file [en_GB]' ) );

		$this->reset_registry();

		Plugin::instance()->load_translations();

		$this->assertSame(
			'Advertising',
			__( 'Advertising', 'aggressive-ads' ),
			'A catalog named the theme way must NOT load, or compile.sh could rename to it and nobody would notice.'
		);
	}
}
