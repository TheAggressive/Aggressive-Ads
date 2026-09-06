#!/usr/bin/env node

/**
 * Stylesheet gate: no class or token is used without being defined.
 *
 * `inc/` has boundary guards that make whole categories of mistake impossible.
 * The stylesheets had nothing equivalent, and it showed — six defects reached
 * a human reviewer by eye in one cycle while PHPCS, PHPStan, Stylelint, axe and
 * the whole test suite stayed green:
 *
 * - `aggr-linkbutton` was written into two components and defined nowhere, so
 *   the browser drew its default button: a grey box around a campaign name.
 * - `.aggr-creative__preview` was defined twice, and the copy that won pinned a
 *   height the other assumed it could grow past. A tall creative hung 204px
 *   over the text below it.
 *
 * Neither is visible to a CSS linter, which checks the stylesheet in isolation
 * and cannot know what the markup asks for. Both are obvious the moment you
 * compare the two sides, which is all this does.
 *
 * Two rules, both narrow enough to have no false positives worth an allowlist:
 *
 *   1. Every `aggr-*` class named in a `class=` or `className=` attribute
 *      resolves to a selector in an authored stylesheet under `src/`.
 *   2. Every `var(--aggr-*)` read anywhere resolves to a declaration.
 *
 * Dynamic names are handled as prefixes: `aggr-pill--${status}` requires only
 * that something matching `aggr-pill--` exists, because which modifier a status
 * maps to is the server's business and not knowable here.
 *
 * **`inc/` is scanned, and was not always.** The guard read 77 markup files
 * while the admin screens — which echo most of this plugin's markup from PHP —
 * were none of them. A class invented in `inc/Admin/` passed silently, which is
 * how a WCAG 1.4.10 fix shipped twice with its rule in a stylesheet the screen
 * does not load: the markup carried the class, nothing defined it where it
 * mattered, and this said ok. Adding the directory took the count to 325 and
 * found no existing violations, so the cost of having looked away was one
 * defect rather than a backlog.
 *
 * What this still does not check is *which* stylesheet a given screen enqueues.
 * A class defined in `admin.css` and used on a screen that only loads
 * `admin-native.css` satisfies rule 1 and renders unstyled. Catching that means
 * teaching this script the enqueue map, which is a bigger claim about the
 * plugin than a stylesheet gate should make on its own — recorded here so the
 * next person meets the limit rather than rediscovers it.
 */

import { readdir, readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

/*
 * Overridable so the guard can be pointed at a fixture. See
 * check-styles.test.mjs — a gate with no test is a gate nobody has seen work.
 */
const ROOT =
	process.env.AGGR_STYLES_SCAN_DIR ??
	path.resolve( import.meta.dirname, '../..' );
const STYLE_ROOT = path.join( ROOT, 'src' );

/** Where markup lives. Anything here may name a class. */
const MARKUP_DIRS = [
	'src/admin',
	'src/blocks',
	'src/blocks-interactivity',
	'src/interactivity',
	'templates',
	'inc',
];
const MARKUP_EXTENSIONS = [ '.tsx', '.ts', '.js', '.php' ];

/*
 * Tests build markup to assert against, so the classes in them describe what a
 * component produced rather than what the product ships. Reading them would
 * report a fixture as a missing rule.
 */
const SKIP = /(^|\/)__tests__(\/|$)/;

/**
 * Every file under one directory, recursively.
 *
 * @param {string} dir Absolute directory.
 * @param {string[]} extensions Extensions to keep.
 * @return {Promise<string[]>} Absolute file paths.
 */
async function filesIn( dir, extensions ) {
	let entries = [];

	try {
		entries = await readdir( dir, { withFileTypes: true } );
	} catch {
		return [];
	}

	const found = await Promise.all(
		entries.map( async ( entry ) => {
			const full = path.join( dir, entry.name );

			if ( entry.isDirectory() ) {
				return filesIn( full, extensions );
			}

			if ( SKIP.test( full ) ) {
				return [];
			}

			return extensions.includes( path.extname( entry.name ) )
				? [ full ]
				: [];
		} )
	);

	return found.flat();
}

/**
 * Class names and custom properties the stylesheets define.
 *
 * @return {Promise<{ classes: Set<string>, tokens: Set<string> }>} Declarations.
 */
async function declared() {
	const classes = new Set();
	const tokens = new Set();

	for ( const file of await filesIn( STYLE_ROOT, [ '.css' ] ) ) {
		const css = await readFile( file, 'utf8' );

		for ( const match of css.matchAll( /\.(aggr-[\w-]+)/g ) ) {
			classes.add( match[ 1 ] );
		}

		for ( const match of css.matchAll( /(--aggr-[\w-]+)\s*:/g ) ) {
			tokens.add( match[ 1 ] );
		}
	}

	/*
	 * A token can also be declared in an inline style — the sizing box sets
	 * --aggr-box-w from the placement's own dimensions, which no stylesheet
	 * could know. Those are declarations too.
	 */
	for ( const dir of MARKUP_DIRS ) {
		for ( const file of await filesIn(
			path.join( ROOT, dir ),
			MARKUP_EXTENSIONS
		) ) {
			const source = await readFile( file, 'utf8' );

			for ( const match of source.matchAll( /(--aggr-[\w-]+)\s*:/g ) ) {
				tokens.add( match[ 1 ] );
			}
		}
	}

	return { classes, tokens };
}

/**
 * The class names a markup file asks for, with dynamic segments trimmed.
 *
 * A template literal such as `aggr-pill aggr-pill--${ status }` yields
 * `aggr-pill` and the prefix `aggr-pill--`, because the suffix is decided at
 * runtime by data this script cannot see.
 *
 * @param {string} source File contents.
 * @return {string[]} Class names, some of them prefixes.
 */
function classesUsed( source ) {
	const names = [];
	const attributes = source.matchAll(
		/(?:className|class)\s*=\s*(?:"([^"]*)"|'([^']*)'|\{\s*`([^`]*)`)/g
	);

	for ( const attribute of attributes ) {
		const value = attribute[ 1 ] ?? attribute[ 2 ] ?? attribute[ 3 ] ?? '';

		// PHP interpolation and JSX expressions both become a space, so the
		// tokens either side of them stay separate words.
		for ( const word of value
			.replace( /\$\{[^}]*\}|<\?php[\s\S]*?\?>/g, ' ' )
			.split( /\s+/ ) ) {
			if ( word.startsWith( 'aggr-' ) ) {
				names.push( word );
			}
		}
	}

	return names;
}

/**
 * Whether a declared class satisfies a used name, allowing prefix matches.
 *
 * @param {string} used One name from markup.
 * @param {Set<string>} classes Declared class names.
 * @return {boolean} Whether something defines it.
 */
function isDefined( used, classes ) {
	if ( classes.has( used ) ) {
		return true;
	}

	// A trailing `--` or `__` is the visible half of a runtime-built name.
	if ( ! /(--|__|-)$/.test( used ) ) {
		return false;
	}

	for ( const declaredClass of classes ) {
		if ( declaredClass.startsWith( used ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Runs both checks and reports every violation at once.
 *
 * @return {Promise<void>} Resolves after reporting.
 */
async function main() {
	// Collected up front so the empty-scan assertions at the end have something
	// to count. Both are re-walked below rather than threaded through, which is
	// cheap and keeps the two rules readable.
	const stylesheets = await filesIn( STYLE_ROOT, [ '.css' ] );
	const markupFiles = (
		await Promise.all(
			MARKUP_DIRS.map( ( dir ) =>
				filesIn( path.join( ROOT, dir ), MARKUP_EXTENSIONS )
			)
		)
	).flat();

	const { classes, tokens } = await declared();
	const problems = [];

	for ( const dir of MARKUP_DIRS ) {
		for ( const file of await filesIn(
			path.join( ROOT, dir ),
			MARKUP_EXTENSIONS
		) ) {
			const source = await readFile( file, 'utf8' );
			const relative = path.relative( ROOT, file );

			for ( const used of new Set( classesUsed( source ) ) ) {
				if ( ! isDefined( used, classes ) ) {
					problems.push(
						`${ relative }: class "${ used }" has no rule under src/`
					);
				}
			}
		}
	}

	// Tokens are read from stylesheets and from inline styles in components.
	const readers = [
		...( await filesIn( STYLE_ROOT, [ '.css' ] ) ),
		...(
			await Promise.all(
				MARKUP_DIRS.map( ( dir ) =>
					filesIn( path.join( ROOT, dir ), MARKUP_EXTENSIONS )
				)
			)
		).flat(),
	];

	for ( const file of readers ) {
		const source = await readFile( file, 'utf8' );
		const relative = path.relative( ROOT, file );

		for ( const match of new Set(
			[ ...source.matchAll( /var\(\s*(--aggr-[\w-]+)\s*([,)])/g ) ]
				.filter( ( found ) => ',' !== found[ 2 ] )
				.map( ( found ) => found[ 1 ] )
		) ) {
			if ( ! tokens.has( match ) ) {
				problems.push(
					`${ relative }: token "${ match }" is read but never declared`
				);
			}
		}
	}

	/*
	 * `filesIn()` answers a missing directory with an empty list, which is the
	 * right shape for an optional subdirectory and the wrong one for the roots.
	 * With no stylesheets there are no definitions, with no markup there are no
	 * uses, and "nothing is undefined" is trivially true of both — so a renamed
	 * or moved src/ turned this gate off and printed "ok".
	 */
	if ( 0 === stylesheets.length ) {
		console.error(
			`check-styles: no stylesheets found under ${ STYLE_ROOT }`
		);
		console.error(
			'A gate that reads nothing reports success over nothing. See CLAUDE.md.'
		);
		process.exit( 1 );
	}

	if ( 0 === markupFiles.length ) {
		console.error(
			'check-styles: no markup found in ' + MARKUP_DIRS.join( ', ' )
		);
		console.error(
			'A gate that reads nothing reports success over nothing. See CLAUDE.md.'
		);
		process.exit( 1 );
	}

	if ( 0 !== problems.length ) {
		problems.sort().forEach( ( problem ) => console.error( problem ) );
		console.error(
			`\ncheck-styles: ${ problems.length } undefined class(es) or token(s).`
		);
		process.exit( 1 );
	}

	console.log(
		`check-styles: ok (${ stylesheets.length } stylesheets, ${ markupFiles.length } markup files)`
	);
}

await main();
