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

export default ( env = {}, argv = {} ) => {
	const base =
		typeof wpConfig === 'function' ? wpConfig( env, argv ) : wpConfig;
	const template = Array.isArray( base ) ? base[ 0 ] : base;

	return merge( template, {
		name: 'admin',
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
		],
		stats: 'minimal',
	} );
};
