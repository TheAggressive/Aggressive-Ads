/**
 * Webpack config for React admin screens.
 *
 * Separate from the assets config because these entries are TSX rather than
 * CSS, and because their WordPress dependencies must be externalised: the
 * dependency-extraction plugin writes an .asset.php naming wp-element and
 * wp-components so the browser uses the copies WordPress already loads rather
 * than a second one bundled here.
 */

import path from 'path';
import wpConfig from '@wordpress/scripts/config/webpack.config.js';
import { merge } from 'webpack-merge';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import DependencyExtractionWebpackPlugin from '@wordpress/dependency-extraction-webpack-plugin';

/**
 * Packages WordPress does not register as a script handle, despite the name.
 *
 * The dependency-extraction plugin externalises every `@wordpress/*` import,
 * which is right for wp-components and wp-element because WordPress already
 * loads them. `@wordpress/dataviews` is different: WordPress 7.1 uses DataViews
 * internally in the Site Editor but ships no `wp-dataviews` handle and no
 * `wp-includes/js/dist/dataviews.js`. Externalising it produces a build that
 * succeeds and a screen that breaks in the browser — the .asset.php names a
 * handle WordPress cannot resolve, so the bundle loads without its dependency
 * and the mount throws. Bundle it instead.
 */
const BUNDLE_NOT_EXTERNAL = new Set( [ '@wordpress/dataviews' ] );

/**
 * True for the package itself and for anything imported out of it.
 *
 * Exact matching is not enough, and the failure is quiet: importing
 * `@wordpress/dataviews/build-style/style.css` cascades to the default, which
 * turns it into a *script* handle named `wp-dataviews/build-style/style.css`.
 * The build succeeds, no stylesheet is emitted, and WordPress is asked to
 * enqueue a script that does not exist — an unstyled table, not a build error.
 */
function bundledLocally( request ) {
	for ( const name of BUNDLE_NOT_EXTERNAL ) {
		if ( request === name || request.startsWith( `${ name }/` ) ) {
			return true;
		}
	}

	return false;
}

export default ( env = {}, argv = {} ) => {
	const base =
		typeof wpConfig === 'function' ? wpConfig( env, argv ) : wpConfig;
	const template = Array.isArray( base ) ? base[ 0 ] : base;

	// Drop the inherited extraction plugin so ours, which knows about the
	// packages WordPress does not actually register, is the only one running.
	template.plugins = ( template.plugins || [] ).filter(
		( plugin ) =>
			plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
	);

	return merge( template, {
		name: 'admin',
		module: {
			rules: [
				{
					/*
					 * A stylesheet is a side effect, whatever its package says.
					 *
					 * `@wordpress/dataviews` declares `"sideEffects": false`,
					 * which is true of its modules and false of its 102 KB
					 * stylesheet. Webpack believes the package: it tree-shakes
					 * `import '@wordpress/dataviews/build-style/style.css'`
					 * away, emits no CSS, warns about nothing, and the table
					 * renders as unstyled markup. Nothing in the build says so.
					 */
					test: /\.css$/,
					include: /node_modules/,
					sideEffects: true,
				},
			],
		},
		entry: {
			'admin/settings': path.resolve(
				process.cwd(),
				'src/admin/settings/index.tsx'
			),
			'admin/packages': path.resolve(
				process.cwd(),
				'src/admin/packages/index.tsx'
			),
			'admin/organizations': path.resolve(
				process.cwd(),
				'src/admin/organizations/index.tsx'
			),
			'admin/inventory': path.resolve(
				process.cwd(),
				'src/admin/inventory/index.tsx'
			),
			'admin/review': path.resolve(
				process.cwd(),
				'src/admin/review/index.tsx'
			),
		},
		output: {
			path: path.resolve( process.cwd(), 'dist' ),
			filename: '[name].js',
			chunkFilename: '[name].js',
			// Relative URLs from dist/styles/*.css to dist/fonts/*.woff2.
			publicPath: 'auto',
			clean: false,
		},
		optimization: {
			splitChunks: false,
			runtimeChunk: false,
			concatenateModules: true,
			chunkIds: 'named',
			moduleIds: 'named',
		},
		plugins: [
			new MiniCssExtractPlugin( {
				filename: '[name].css',
				chunkFilename: '[name].css',
			} ),
			new DependencyExtractionWebpackPlugin( {
				requestToExternal( request ) {
					if ( bundledLocally( request ) ) {
						// Defined-but-falsy, not undefined. The plugin only
						// cascades to its `@wordpress/*` default when the
						// return is literally `undefined`; `null` means
						// "decided: not external", so webpack bundles it.
						return null;
					}

					// Undefined cascades to the default, which is what every
					// other @wordpress import should get.
					return undefined;
				},
			} ),
		],
		stats: 'minimal',
	} );
};
