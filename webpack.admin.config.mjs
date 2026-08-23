/**
 * Webpack config for React admin screens.
 *
 * Separate from the assets config because these entries are TSX rather than
 * CSS, and because their WordPress dependencies must be externalised: the
 * dependency-extraction plugin writes an .asset.php naming wp-element and
 * wp-components so the browser uses the copies WordPress already loads rather
 * than a second one bundled here.
 *
 * The exceptions are in `bin/ci/bundled-packages.mjs`: packages WordPress does
 * not register, which this plugin compiles once into its own bundle and shares
 * under a handle of its own. Screens import them by their ordinary package
 * name; the rewrite below is the only thing that knows otherwise.
 */

import path from 'path';
import wpConfig from '@wordpress/scripts/config/webpack.config.js';
import { merge } from 'webpack-merge';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import DependencyExtractionWebpackPlugin from '@wordpress/dependency-extraction-webpack-plugin';
import { sharedPackageFor } from './bin/ci/bundled-packages.mjs';

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
				/*
				 * Shared packages resolve to a global this plugin ships, not to
				 * a `wp-*` handle WordPress does not have and not to a private
				 * copy compiled into every screen.
				 */
				requestToExternal( request ) {
					const shared = sharedPackageFor( request );

					// Undefined cascades to the default, which is what every
					// other @wordpress import should get.
					return shared ? shared.global : undefined;
				},
				requestToHandle( request ) {
					const shared = sharedPackageFor( request );

					return shared ? shared.handle : undefined;
				},
			} ),
		],
		stats: 'minimal',
	} );
};
