#!/usr/bin/env node

/**
 * The admin bundles must not name script handles WordPress does not register,
 * and must not lose a stylesheet they depend on.
 *
 * Both failures this guards against are silent. Neither produces a webpack
 * error, a warning, or a missing file — they produce a build that looks correct
 * and a screen that does not work, which is the most expensive kind of defect
 * this repository has shipped.
 *
 * **The bogus handle.** `@wordpress/dependency-extraction-webpack-plugin`
 * externalises every `@wordpress/*` import by mapping it to a `wp-*` script
 * handle. That is right for the packages WordPress actually loads, and wrong
 * for two cases. `@wordpress/dataviews` has no `wp-dataviews` handle in
 * WordPress 7.1 at all, despite DataViews being used by the Site Editor. And a
 * *subpath* import like `@wordpress/dataviews/build-style/style.css` maps to a
 * handle named `wp-dataviews/build-style/style.css`, which is not a handle at
 * all. WordPress silently fails to resolve either, the bundle loads without its
 * dependency, and the mount throws in the browser.
 *
 * **The tree-shaken stylesheet.** `@wordpress/dataviews` declares
 * `"sideEffects": false`. That is true of its modules and false of its 102 KB
 * stylesheet, so webpack deletes `import '.../style.css'` as dead code. No CSS
 * is emitted, nothing warns, and the table renders as unstyled markup that
 * still technically works.
 *
 * So: every dependency must look like a handle, no dependency may name a
 * package WordPress does not register, and any bundle carrying DataViews markup
 * must ship DataViews rules beside it.
 *
 * `AGGR_BUNDLE_DIR` points the scan at another directory; the tests use it to
 * run this file as a subprocess against a fixture, so what they assert is the
 * real command-line contract — exit code and message — rather than an internal
 * function that the lane does not actually call.
 */

import { readdirSync, readFileSync } from 'node:fs';
import path from 'node:path';
import process from 'node:process';

import { forbiddenHandles } from './bundled-packages.mjs';

const ROOT = path.resolve( import.meta.dirname, '../..' );

const SCAN_DIR = process.env.AGGR_BUNDLE_DIR
	? path.resolve( process.env.AGGR_BUNDLE_DIR )
	: path.join( ROOT, 'dist/admin' );

/**
 * A WordPress script handle: lowercase, digits and hyphens.
 *
 * Anything else — a slash, a dot, an @ — is a package request that escaped
 * externalisation with its path intact rather than a handle.
 */
const HANDLE = /^[a-z0-9][a-z0-9-]*$/;

/**
 * Entries the scan actually read.
 *
 * Reported on success so a passing run states its own scope. "ok" over zero
 * entries is the failure mode this guard is most likely to develop, and the
 * empty-directory check below is what prevents it, but printing the count
 * means a shrinking scan is visible in the log before it reaches zero.
 */
const ENTRY_COUNT = new Set();

/**
 * Handles that look well-formed and that WordPress still does not register.
 *
 * Derived from the same list the webpack config bundles by, rather than
 * restated here: a package the build compiles in must never reappear as an
 * externalised handle, and the two facts cannot drift apart if there is only
 * one of them.
 */
const UNREGISTERED = new Set( forbiddenHandles() );

/**
 * Markup/style pairs that must travel together.
 *
 * `marker` is a class name the compiled JS emits; `prefix` is the selector
 * family its stylesheet must define; `minimum` is how many distinct selectors
 * in that family a real stylesheet carries.
 *
 * The count is the whole point, and asserting presence instead is a mistake
 * this guard has already made. Checking that the CSS merely *contained*
 * `.dataviews-view-table` passed with the stylesheet fully tree-shaken away,
 * because the screen's own small theme layer names that class in order to pad
 * its cells. Six selectors came from us and none from DataViews, and the guard
 * called it fine. The real stylesheet defines 166.
 */
const STYLE_PAIRS = [
	{
		name: '@wordpress/dataviews',
		marker: 'dataviews-view-table',
		prefix: '.dataviews-',
		pattern: /\.dataviews[a-zA-Z0-9_-]*/g,
		minimum: 40,
	},
];

/**
 * Reads the `dependencies` array out of a generated .asset.php.
 *
 * The file is PHP, but the plugin generates it in one shape: a single
 * `array( ... )` of quoted handles. Parsing the quoted strings out of the
 * dependencies array is enough, and avoids needing PHP to run this guard.
 *
 * @param {string} source Contents of the .asset.php file.
 * @return {string[]} Handle names, in file order.
 */
function parseDependencies( source ) {
	const block = source.match( /'dependencies'\s*=>\s*array\(([^)]*)\)/s );

	if ( ! block ) {
		return [];
	}

	return [ ...block[ 1 ].matchAll( /'([^']*)'/g ) ].map( ( m ) => m[ 1 ] );
}

/**
 * Checks one built entry.
 *
 * @param {string} dir   Directory holding the bundle.
 * @param {string} entry Entry name, without extension.
 * @return {string[]} Problems found, empty when the entry is sound.
 */
function checkEntry( dir, entry ) {
	const problems = [];

	const deps = parseDependencies(
		readFileSync( path.join( dir, `${ entry }.asset.php` ), 'utf8' )
	);

	for ( const dep of deps ) {
		if ( ! HANDLE.test( dep ) ) {
			problems.push(
				`${ entry }: "${ dep }" is not a script handle. A subpath import ` +
					`escaped externalisation; add the package to ` +
					`BUNDLED_PACKAGES in bin/ci/bundled-packages.mjs so it is bundled.`
			);
			continue;
		}

		if ( UNREGISTERED.has( dep ) ) {
			problems.push(
				`${ entry }: "${ dep }" is not registered by WordPress. ` +
					`Bundle the package instead of externalising it.`
			);
		}
	}

	let js = '';

	try {
		js = readFileSync( path.join( dir, `${ entry }.js` ), 'utf8' );
	} catch {
		problems.push( `${ entry }: no ${ entry }.js was emitted.` );

		return problems;
	}

	for ( const pair of STYLE_PAIRS ) {
		if ( ! js.includes( pair.marker ) ) {
			continue;
		}

		let css = '';

		try {
			css = readFileSync( path.join( dir, `${ entry }.css` ), 'utf8' );
		} catch {
			css = '';
		}

		const found = new Set( css.match( pair.pattern ) ?? [] );

		if ( found.size < pair.minimum ) {
			problems.push(
				`${ entry }: bundles ${ pair.name } but ships only ` +
					`${ found.size } distinct "${ pair.prefix }" selectors, ` +
					`below the ${ pair.minimum } a real stylesheet carries. Its ` +
					`package.json says "sideEffects": false, so webpack drops the ` +
					`stylesheet import unless the css rule in ` +
					`webpack.admin.config.mjs marks node_modules CSS as having ` +
					`side effects.`
			);
		}
	}

	return problems;
}

/**
 * Scans a directory of built admin entries.
 *
 * @param {string} dir Directory to scan.
 * @return {string[]} Problems found across every entry.
 */
function checkDirectory( dir ) {
	let names = [];

	try {
		names = readdirSync( dir, { withFileTypes: true } )
			// Only real files. A symlink reports neither isFile nor isDirectory,
			// and readFileSync on a directory throws EISDIR — the crash that
			// writing a test for check-navigation.mjs found in that guard.
			.filter(
				( entry ) =>
					entry.isFile() && entry.name.endsWith( '.asset.php' )
			)
			.map( ( entry ) => entry.name.replace( /\.asset\.php$/, '' ) );
	} catch {
		return [ `check-admin-bundle: ${ dir } is not readable.` ];
	}

	if ( 0 === names.length ) {
		// A guard that stops matching does not fail; it reports success over
		// code it is no longer reading. An empty scan is that failure.
		return [
			`check-admin-bundle: no .asset.php files under ${ dir }. ` +
				`The bundles were not built, so nothing was checked.`,
		];
	}

	for ( const name of names ) {
		ENTRY_COUNT.add( name );
	}

	return names.flatMap( ( name ) => checkEntry( dir, name ) );
}

const problems = checkDirectory( SCAN_DIR );

if ( problems.length > 0 ) {
	console.error( problems.join( '\n' ) );
	process.exit( 1 );
}

console.log( `check-admin-bundle: ok (${ ENTRY_COUNT.size } entries)` );
