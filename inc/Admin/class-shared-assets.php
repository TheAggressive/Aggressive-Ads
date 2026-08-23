<?php
/**
 * Admin bundles shared between screens.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

/**
 * Registers the vendor bundles more than one admin screen depends on.
 *
 * WordPress 7.1 uses DataViews internally but registers no `wp-dataviews`
 * script or style handle, so a screen cannot depend on core for it. The first
 * conversion compiled the package into that screen's own bundle, which is right
 * for one screen and wrong for the second: nothing is shared between admin
 * entries — `splitChunks` is off and each `.asset.php` is its own enqueue — so
 * every consumer would ship a full private copy. On the organizations screen
 * that copy was 490 KB of script and 90 KB of CSS.
 *
 * It is now compiled once by `webpack.dataviews.config.mjs` and registered here
 * as `aggr-dataviews`. Screens never name it: their `.asset.php` lists it as a
 * dependency because the build rewrote their `@wordpress/dataviews` import onto
 * it. A screen therefore only has to call `register()` before enqueueing its
 * own bundle, and WordPress resolves the rest.
 *
 * Registering, not enqueueing. A handle nothing depends on costs nothing; a
 * handle enqueued on a screen that does not use it is 540 KB of waste.
 */
final class Shared_Assets {

	/** Script and style handle for the shared DataViews bundle. */
	public const DATAVIEWS = 'aggr-dataviews';

	/**
	 * Registers every shared admin bundle that has been built.
	 *
	 * Safe to call more than once and from more than one screen:
	 * `wp_register_script()` ignores a handle it already holds, so the first
	 * caller wins and the rest are free.
	 *
	 * @return void
	 */
	public static function register(): void {
		$asset = AGGR_PLUGIN_DIR . 'dist/admin/dataviews.asset.php';

		/*
		 * Silence here is deliberate. Each screen already renders its own
		 * "run pnpm build" notice when its bundle is missing, and this file is
		 * missing under exactly the same circumstances. A second warning would
		 * describe the same unbuilt checkout twice.
		 */
		if ( ! is_file( $asset ) ) {
			return;
		}

		$meta    = require $asset;
		$version = is_string( $meta['version'] ?? null ) ? $meta['version'] : AGGR_VERSION;

		wp_register_script(
			self::DATAVIEWS,
			AGGR_PLUGIN_URL . 'dist/admin/dataviews.js',
			is_array( $meta['dependencies'] ?? null ) ? $meta['dependencies'] : array(),
			$version,
			true
		);

		/*
		 * DataViews' stylesheet is not optional, and a script dependency will
		 * not bring it: WordPress resolves script handles and style handles
		 * separately. Without it the table renders as unstyled markup that
		 * still technically works, which is the failure worth naming because
		 * nothing errors.
		 *
		 * `wp-components` first, so core's variables and resets load before
		 * DataViews overrides them rather than after.
		 */
		wp_register_style(
			self::DATAVIEWS,
			AGGR_PLUGIN_URL . 'dist/admin/dataviews.css',
			array( 'wp-components' ),
			$version
		);

		// The build emits dataviews-rtl.css beside it; core swaps the file
		// wholesale rather than appending overrides.
		wp_style_add_data( self::DATAVIEWS, 'rtl', 'replace' );
	}
}
