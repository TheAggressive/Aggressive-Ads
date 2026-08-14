/**
 * Webpack config for portal styles (and future classic scripts).
 *
 * Compiles src/styles/*.css → dist/styles/*.css with PostCSS / cssnano via
 * @wordpress/scripts. Empty JS stubs from CSS-only entries are stripped.
 */

import path from 'path';
import wpConfig from '@wordpress/scripts/config/webpack.config.js';
import { merge } from 'webpack-merge';
import MiniCssExtractPlugin from 'mini-css-extract-plugin';
import RemoveEmptyScriptsPlugin from 'webpack-remove-empty-scripts';
import { getAssetWebpackEntries } from './bin/lib/build-manifest.mjs';

export default ( env = {}, argv = {} ) => {
	const base = typeof wpConfig === 'function' ? wpConfig( env, argv ) : wpConfig;
	const template = Array.isArray( base ) ? base[ 0 ] : base;

	return merge( template, {
		name: 'assets',
		entry: getAssetWebpackEntries(),
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
			new RemoveEmptyScriptsPlugin( {
				stage: RemoveEmptyScriptsPlugin.STAGE_AFTER_PROCESS_PLUGINS,
			} ),
		],
		stats: 'minimal',
	} );
};
