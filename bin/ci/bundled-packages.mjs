/**
 * The `@wordpress/*` packages this plugin bundles instead of externalising.
 *
 * One list, imported by both the thing that acts on it and the thing that
 * checks it. `webpack.admin.config.mjs` reads it to decide what not to turn
 * into a script handle; `check-admin-bundle.mjs` reads it to know which
 * handles must therefore never appear in a built `.asset.php`.
 *
 * They were briefly two lists that had to agree by hand, which is the shape
 * `lanes.mjs` already argues against in this repository: a second
 * hand-maintained list is a second thing to forget, and the failure is silent
 * in both directions. Forgetting it here lets a broken handle ship; forgetting
 * it there makes the guard permit the thing it exists to catch.
 *
 * **Why anything is on this list.** The dependency-extraction plugin assumes
 * every `@wordpress/*` import corresponds to a script handle WordPress
 * registers. That assumption is usually right and occasionally very wrong:
 * WordPress 7.1 uses DataViews in the Site Editor but registers no
 * `wp-dataviews` script or style handle, and `wp-includes/js/dist/dataviews.js`
 * does not exist. Externalising such a package yields a build that succeeds and
 * a screen that throws, so it must be compiled into the bundle instead.
 */

/**
 * Package names to bundle. Subpaths of these are bundled too — a stylesheet
 * imported as `@wordpress/dataviews/build-style/style.css` would otherwise be
 * mapped to a "handle" that is really a file path.
 */
export const BUNDLED_PACKAGES = [ '@wordpress/dataviews' ];

/**
 * True for a bundled package and for anything imported out of one.
 *
 * @param {string} request Import request as webpack sees it.
 * @return {boolean} Whether webpack should compile it in rather than externalise it.
 */
export function isBundledPackage( request ) {
	return BUNDLED_PACKAGES.some(
		( name ) => request === name || request.startsWith( `${ name }/` )
	);
}

/**
 * The script handles those packages would have produced had they been
 * externalised — and which must therefore never appear in a built bundle.
 *
 * `@wordpress/dataviews` becomes `wp-dataviews`, the same transformation
 * `defaultRequestToExternal` applies.
 *
 * @return {string[]} Handle names.
 */
export function forbiddenHandles() {
	return BUNDLED_PACKAGES.map(
		( name ) => `wp-${ name.replace( /^@wordpress\//, '' ) }`
	);
}
