/**
 * Webpack entry discovery for portal assets and Interactivity modules.
 *
 * Mirrors the Aggressive Apparel theme's build-manifest helpers, scoped to
 * what this plugin ships today: styles under src/styles/ and top-level
 * script modules under src/interactivity/.
 */

import fg from 'fast-glob';
import fs from 'fs';
import path from 'path';
import process from 'node:process';

/**
 * @param {string} filePath
 * @return {string}
 */
function toPosix( filePath ) {
	return filePath.split( '\\' ).join( '/' );
}

/**
 * CSS files imported by portal.css are bundled there and must not be
 * standalone entries.
 *
 * @param {string} cwd
 * @return {Set<string>}
 */
function getPortalCssImportPartials( cwd ) {
	const portalCssPath = path.join( cwd, 'src/styles/portal.css' );
	if ( ! fs.existsSync( portalCssPath ) ) {
		return new Set();
	}

	const portalCss = fs.readFileSync( portalCssPath, 'utf8' );
	const partials = new Set();
	const importPattern = /@import\s+['"]\.\/([^'"]+)['"]/g;

	let match = importPattern.exec( portalCss );
	while ( match ) {
		partials.add( match[ 1 ] );
		match = importPattern.exec( portalCss );
	}

	return partials;
}

/**
 * @param {string} [cwd]
 * @return {Record<string, string>}
 */
export function getAssetWebpackEntries( cwd = process.cwd() ) {
	const entries = {};
	const portalPartials = getPortalCssImportPartials( cwd );

	const jsFiles = fg.sync( 'src/scripts/**/*.{js,ts,tsx}', {
		cwd,
		ignore: [
			'src/scripts/**/_*.{js,ts,tsx}',
			'src/scripts/**/__tests__/**',
			'src/scripts/**/*.test.{js,ts,tsx}',
		],
	} );

	jsFiles.forEach( ( file ) => {
		const rel = toPosix(
			path.relative( path.join( cwd, 'src/scripts' ), path.join( cwd, file ) )
		);
		const name = rel.replace( /\.(js|ts|tsx)$/i, '' );
		entries[ `scripts/${ name }` ] = path.resolve( cwd, file );
	} );

	const styleFiles = fg.sync( 'src/styles/**/*.{css,scss}', {
		cwd,
		ignore: [ 'src/styles/**/_*.{css,scss}' ],
	} );

	styleFiles.forEach( ( file ) => {
		const rel = toPosix(
			path.relative( path.join( cwd, 'src/styles' ), path.join( cwd, file ) )
		);

		if ( portalPartials.has( rel ) ) {
			return;
		}

		const name = rel.replace( /\.(css|scss)$/i, '' );
		entries[ `styles/${ name }` ] = path.resolve( cwd, file );
	} );

	return entries;
}

/**
 * @param {string} [cwd]
 * @return {Record<string, string>}
 */
export function getInteractivityModuleEntries( cwd = process.cwd() ) {
	const entries = {};

	fg.sync( 'src/interactivity/*.{js,ts}', {
		cwd,
		ignore: [
			'src/interactivity/**/*.test.{js,ts}',
			'src/interactivity/**/__tests__/**',
		],
	} ).forEach( ( file ) => {
		const name = toPosix(
			path.relative(
				path.join( cwd, 'src/interactivity' ),
				path.join( cwd, file )
			)
		).replace( /\.(js|ts)$/i, '' );

		entries[ name ] = path.resolve( cwd, file );
	} );

	return entries;
}
