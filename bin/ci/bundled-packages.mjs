/**
 * The `@wordpress/*` packages this plugin ships itself, and how.
 *
 * One list, imported by everything that acts on it or checks it:
 * `webpack.dataviews.config.mjs` compiles them, `webpack.admin.config.mjs`
 * rewrites screen imports onto them, `check-admin-bundle.mjs` verifies the
 * result, and `Admin\Shared_Assets` registers the handles. They were briefly
 * two lists that had to agree by hand, which is the shape `lanes.mjs` already
 * argues against here: a second hand-maintained list is a second thing to
 * forget, and the failure is silent in both directions.
 *
 * **Why anything is on this list.** The dependency-extraction plugin assumes
 * every `@wordpress/*` import corresponds to a script handle WordPress
 * registers. That assumption is usually right and occasionally very wrong:
 * WordPress 7.1 uses DataViews in the Site Editor but registers no
 * `wp-dataviews` script or style handle, and `wp-includes/js/dist/dataviews.js`
 * does not exist. Externalising such a package yields a build that succeeds and
 * a screen that throws.
 *
 * **Why they are shared rather than bundled.** Compiling the package into each
 * screen works until the second screen wants it. Admin entries share nothing —
 * `splitChunks` is off and each `.asset.php` is its own `wp_enqueue_script` —
 * so every consumer would carry a full private copy. Each package here is
 * compiled once into its own entry, exposed as a global, and registered as a
 * plugin-owned handle that screens depend on.
 */

/**
 * @typedef {object} SharedPackage
 * @property {string} request The bare import specifier screens write.
 * @property {string} entry   Source file compiled into the shared bundle.
 * @property {string} name    Output entry name, without extension.
 * @property {string} global  Browser global the bundle assigns itself to.
 * @property {string} handle  WordPress script and style handle.
 */

/** @type {SharedPackage[]} */
export const SHARED_PACKAGES = [
	{
		request: '@wordpress/dataviews',
		entry: 'src/admin/vendor/dataviews.ts',
		name: 'admin/dataviews',
		global: 'aggrDataViews',
		handle: 'aggr-dataviews',
	},
];

/**
 * The shared package a request belongs to, or null.
 *
 * Subpaths count. An import of `@wordpress/dataviews/build-style/style.css`
 * that fell through to the default became a "handle" named
 * `wp-dataviews/build-style/style.css`, which is a file path rather than a
 * handle — a build that succeeds and a stylesheet WordPress cannot resolve.
 *
 * @param {string} request Import request as webpack sees it.
 * @return {SharedPackage|null} The owning package, or null.
 */
export function sharedPackageFor( request ) {
	return (
		SHARED_PACKAGES.find(
			( pkg ) =>
				request === pkg.request ||
				request.startsWith( `${ pkg.request }/` )
		) ?? null
	);
}

/**
 * The handles WordPress would have been asked for, and never registers.
 *
 * `@wordpress/dataviews` becomes `wp-dataviews`, the same transformation
 * `defaultRequestToExternal` applies. None of these may ever appear in a built
 * `.asset.php`: their presence means a screen was pointed at core for something
 * core does not have.
 *
 * @return {string[]} Handle names.
 */
export function forbiddenHandles() {
	return SHARED_PACKAGES.map(
		( pkg ) => `wp-${ pkg.request.replace( /^@wordpress\//, '' ) }`
	);
}

/**
 * The plugin-owned handles a screen may legitimately depend on instead.
 *
 * @return {string[]} Handle names.
 */
export function sharedHandles() {
	return SHARED_PACKAGES.map( ( pkg ) => pkg.handle );
}
