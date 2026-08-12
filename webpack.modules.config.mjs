/**
 * Webpack config for Interactivity API script modules.
 *
 * Compiles src/interactivity/*.ts → dist/interactivity/*.js as ES modules,
 * compatible with wp_register_script_module() / WordPress import maps.
 *
 * External dependencies (@wordpress/interactivity, and our own shared modules)
 * stay as bare-specifier imports — WordPress resolves them at runtime via the
 * import map emitted in <head>.
 */

import path from 'path';
import wpConfig from '@wordpress/scripts/config/webpack.config.js';
import { getInteractivityModuleEntries } from './bin/lib/build-manifest.mjs';

const PLUGIN_MODULE_IDS = {
	'@laao-ads/helpers': '@laao-ads/helpers',
	'@laao-ads/scroll-lock': '@laao-ads/scroll-lock',
	'@laao-ads/dialog': '@laao-ads/dialog',
};

export default ( env = {}, argv = {} ) => {
	const base = typeof wpConfig === 'function' ? wpConfig( env, argv ) : wpConfig;
	const template = Array.isArray( base ) ? base[ 0 ] : base;

	return {
		...template,
		name: 'modules',
		entry: getInteractivityModuleEntries(),
		output: {
			path: path.resolve( process.cwd(), 'dist/interactivity' ),
			filename: '[name].js',
			chunkFilename: '[name].js',
			publicPath: '',
			clean: true,
			module: true,
			library: { type: 'module' },
			environment: {
				module: true,
				dynamicImport: true,
			},
		},
		experiments: {
			...( template.experiments || {} ),
			outputModule: true,
		},
		externalsType: 'module',
		externals: {
			'@wordpress/interactivity': '@wordpress/interactivity',
			...PLUGIN_MODULE_IDS,
		},
		optimization: {
			splitChunks: false,
			runtimeChunk: false,
			concatenateModules: true,
			chunkIds: 'named',
			moduleIds: 'named',
		},
		plugins: ( template.plugins || [] ).filter(
			( p ) =>
				p.constructor.name === 'MiniCssExtractPlugin' ||
				p.constructor.name === 'DependencyExtractionWebpackPlugin'
		),
		stats: 'minimal',
	};
};
