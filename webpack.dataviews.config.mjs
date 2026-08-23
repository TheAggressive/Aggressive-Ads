/**
 * Webpack config for the shared vendor bundles.
 *
 * Separate from the admin config for one reason that cannot be worked around
 * inside it: `output.library` is per-*config*, not per-entry. These entries
 * must assign themselves to a browser global so the screen bundles can treat
 * them as externals; the screen entries must not, because they mount React and
 * export nothing.
 *
 * Everything else here is deliberately the same as the admin config — the same
 * node_modules CSS side-effect rule, the same extraction plugin — because the
 * two produce assets that load side by side on one page.
 *
 * See `bin/ci/bundled-packages.mjs` for what is shared and why.
 */

import path from 'path';
import wpConfig from '@wordpress/scripts/config/webpack.config.js';
import { merge } from 'webpack-merge';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import DependencyExtractionWebpackPlugin from '@wordpress/dependency-extraction-webpack-plugin';
import {
	SHARED_PACKAGES,
	sharedPackageFor,
} from './bin/ci/bundled-packages.mjs';

export default ( env = {}, argv = {} ) => {
	const base =
		typeof wpConfig === 'function' ? wpConfig( env, argv ) : wpConfig;
	const template = Array.isArray( base ) ? base[ 0 ] : base;

	/*
	 * The inherited extraction plugin would externalise the very package this
	 * bundle exists to contain — including its stylesheet subpath, which maps
	 * to a "handle" named `wp-dataviews/build-style/style.css`. Ours keeps the
	 * shared package in and lets everything else out.
	 */
	template.plugins = ( template.plugins || [] ).filter(
		( plugin ) =>
			plugin.constructor.name !== 'DependencyExtractionWebpackPlugin'
	);

	/*
	 * One entry per shared package, so a second one is a list edit rather than
	 * a config edit. `output.library` names the global from the same record the
	 * admin config reads when it rewrites imports onto it, which is what keeps
	 * the two ends from drifting apart.
	 */
	const entry = Object.fromEntries(
		SHARED_PACKAGES.map( ( pkg ) => [
			pkg.name,
			path.resolve( process.cwd(), pkg.entry ),
		] )
	);

	const globals = SHARED_PACKAGES.map( ( pkg ) => pkg.global );

	if ( 1 !== new Set( globals ).size || 1 !== SHARED_PACKAGES.length ) {
		/*
		 * `output.library` applies to every entry in a config, so two shared
		 * packages would both be assigned to the first one's global and the
		 * second would silently overwrite the first. Splitting this into one
		 * config per package is the fix; failing loudly is what makes sure
		 * somebody does it rather than discovering it in a browser.
		 */
		throw new Error(
			'webpack.dataviews.config.mjs assigns one global for the whole ' +
				'config. Give each shared package its own config before adding ' +
				'a second to SHARED_PACKAGES.'
		);
	}

	return merge( template, {
		name: 'dataviews',
		module: {
			rules: [
				{
					/*
					 * A stylesheet is a side effect, whatever its package says.
					 * `@wordpress/dataviews` declares `"sideEffects": false`,
					 * which is true of its modules and false of its 102 KB
					 * stylesheet, so webpack drops the import as dead code and
					 * emits no CSS without warning about anything.
					 */
					test: /\.css$/,
					include: /node_modules/,
					sideEffects: true,
				},
			],
		},
		entry,
		output: {
			path: path.resolve( process.cwd(), 'dist' ),
			filename: '[name].js',
			chunkFilename: '[name].js',
			publicPath: 'auto',
			clean: false,
			library: {
				name: SHARED_PACKAGES[ 0 ].global,
				type: 'window',
			},
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
					// Defined-but-falsy, not undefined: the plugin only
					// cascades to its `@wordpress/*` default when the return is
					// literally `undefined`, and `null` means "decided: not
					// external", so webpack compiles it in.
					return sharedPackageFor( request ) ? null : undefined;
				},
			} ),
		],
		stats: 'minimal',
	} );
};
