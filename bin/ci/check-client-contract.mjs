/**
 * Keeps the server and the browser from shipping opposite halves of one
 * contract.
 *
 * P15's refresh policy resolved on the server — `rotate`, `rotateSeconds`,
 * `maxRefreshes` — and the store never read the cap. The fill endpoint grew
 * an `n` parameter that partitions page from refresh, and `fillSlot` never
 * sent it. Every PHP test that named those behaviours passed a sequence in
 * by hand or asserted the JSON the server *would* emit. The live path kept
 * filing every rotation as a page opportunity and rotating to the client's
 * own hard stop of a hundred.
 *
 * That is the frequency-capping defect again: `get_count()` was correct and
 * nothing called `increment()`. A write half and a read half that never
 * meet in a test will keep happening until something reads both sources
 * and fails when one is missing.
 *
 * This lane is that something. It does not test behaviour. It tests that
 * the two halves still name each other.
 */

import { readdir, readFile, stat } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

const ROOT = path.resolve( import.meta.dirname, '../..' );

/*
 * Overridable only so this lane's own tests can point it at fixtures.
 * A guard nobody exercises rots into one that permits everything, silently.
 */
const SCAN_ROOT = process.env.AGGR_CLIENT_CONTRACT_SCAN_DIR ?? ROOT;

const PHP_CONTEXT = 'inc/Domain/class-slot-options.php';
const FILL_CONTROLLER = 'inc/REST/class-fill-controller.php';
const SLOT_RENDERER = 'inc/Workflow/class-placement-slot.php';
const DECISION_ENGINE = 'inc/Workflow/class-decision-engine.php';
const FILL_SERVICE = 'inc/Workflow/class-fill-service.php';
const DECISIONS_CONTROLLER = 'inc/REST/class-decisions-controller.php';
const BATCH_CLIENT = 'src/blocks-interactivity/ad-slot/batch.js';

/**
 * Fill parameters the server puts in the URL, and where it must do it.
 *
 * Every other declared parameter has to be written by `fill.js`. These are the
 * exception, and they get the *opposite* assertion rather than an exemption: a
 * server-supplied parameter that nothing supplies is the same defect as a
 * client-supplied one nothing sends, and letting it merely skip the check is
 * how an exception list turns into a blind spot.
 *
 * `slot` is the path segment, so its writer is the route itself.
 */
const SERVER_SUPPLIED = {
	slot: null,
	p: SLOT_RENDERER,
	t: SLOT_RENDERER,
};
const CLIENT_FILES = [
	'src/blocks-interactivity/ad-slot/view.js',
	'src/blocks-interactivity/ad-slot/fill.js',
	BATCH_CLIENT,
	'src/blocks-interactivity/ad-slot/viewport.js',
	'src/blocks-interactivity/ad-slot/empty.js',
	'src/blocks-interactivity/ad-slot/rotation.js',
];

/**
 * Absolute path under the scan root.
 *
 * @param {string} relative Repository-relative path.
 * @return {string} Absolute path.
 */
function resolve( relative ) {
	return path.join( SCAN_ROOT, relative );
}

/**
 * Line numbers that are prose rather than code.
 *
 * A docblock that quotes `context.maxRefreshes` to explain the rule is not
 * a reader. Reading it as one is the guard firing on the thing it protects.
 *
 * @param {string[]} lines File split on newlines.
 * @return {Set<number>} Zero-based indices to ignore.
 */
function commentLines( lines ) {
	const skip = new Set();
	let inBlock = false;

	lines.forEach( ( line, index ) => {
		const trimmed = line.trim();

		if ( inBlock ) {
			skip.add( index );

			if ( trimmed.includes( '*/' ) ) {
				inBlock = false;
			}

			return;
		}

		if ( trimmed.startsWith( '//' ) || trimmed.startsWith( '*' ) ) {
			skip.add( index );

			return;
		}

		if ( trimmed.startsWith( '/*' ) ) {
			skip.add( index );
			inBlock = ! trimmed.includes( '*/' );
		}
	} );

	return skip;
}

/**
 * File text with comment lines blanked, so a match cannot hide in prose.
 *
 * @param {string} source File contents.
 * @return {string} Same length, comments replaced with empty lines.
 */
function codeOnly( source ) {
	const lines = source.split( '\n' );
	const prose = commentLines( lines );

	return lines
		.map( ( line, index ) => ( prose.has( index ) ? '' : line ) )
		.join( '\n' );
}

/**
 * Keys `resolved_context()` actually emits.
 *
 * Read out of the return array, not a list kept beside it. A hardcoded
 * list is a second copy, and a second copy is how this lane would pass
 * after a key was added on one side only.
 *
 * @param {string} php Slot_Options source.
 * @return {string[]|null} Context keys, or null when the method is gone.
 */
function contextKeys( php ) {
	const start = php.indexOf( 'function resolved_context' );

	if ( start < 0 ) {
		return null;
	}

	const body = php.slice( start, start + 2500 );
	const arrayMatch = body.match( /return array\s*\(([\s\S]*?)\n\t\t\);/ );

	if ( ! arrayMatch ) {
		return null;
	}

	return [
		...arrayMatch[ 1 ].matchAll( /'([A-Za-z][A-Za-z0-9]*)'\s*=>/g ),
	].map( ( match ) => match[ 1 ] );
}

/**
 * Whether any client file reads `context.KEY` or `context?.KEY`.
 *
 * @param {string} client Concatenated runtime JS, comments stripped.
 * @param {string} key    Context key.
 * @return {boolean} Whether a reader exists.
 */
/**
 * Keys of the `return array( … )` inside one PHP method.
 *
 * @param {string} php    Source with comments already blanked.
 * @param {string} method Method name to read.
 * @return {string[]|null} Keys, or null when the method or its array is gone.
 */
function returnedKeys( php, method ) {
	const start = php.indexOf( `function ${ method }` );

	if ( start < 0 ) {
		return null;
	}

	const body = php.slice( start );
	const match = body.match( /return array\s*\(([\s\S]*?)\n\t\t\);/ );

	if ( ! match ) {
		return null;
	}

	return [ ...match[ 1 ].matchAll( /'([A-Za-z][A-Za-z0-9_]*)'\s*=>/g ) ].map(
		( found ) => found[ 1 ]
	);
}

/**
 * Keys assigned onto a variable inside one PHP method.
 *
 * A payload is not always finished by its `return array( … )`. `servable` is
 * set on the way out of `paid_creative()`, and the first version of this lane
 * read only the return arrays — so it reported seven keys checked and silently
 * skipped the newest one, which is the failure it exists to catch happening to
 * itself.
 *
 * @param {string} php      Source with comments already blanked.
 * @param {string} method   Method name to read.
 * @param {string} variable Variable the keys are set on, without the sigil.
 * @return {string[]|null} Keys, or null when the method is gone.
 */
function assignedKeys( php, method, variable ) {
	const start = php.indexOf( `function ${ method }` );

	if ( start < 0 ) {
		return null;
	}

	const body = php.slice( start, php.indexOf( '\n\t}', start ) );

	return [
		...body.matchAll(
			new RegExp(
				`\\$${ variable }\\['([A-Za-z][A-Za-z0-9_]*)'\\]\\s*=[^=]`,
				'g'
			)
		),
	].map( ( found ) => found[ 1 ] );
}

/**
 * What `with_tokens()` puts on a creative, and what it takes off again.
 *
 * Read out of the method rather than restated, for the reason the context
 * keys are: a list kept beside it is a second copy, and a second copy is how
 * this lane passes after only one side changed.
 *
 * @param {string} php Fill_Service source with comments blanked.
 * @return {{added: string[], stripped: string[]}|null}
 */
function tokenEffects( php ) {
	const start = php.indexOf( 'function with_tokens' );

	if ( start < 0 ) {
		return null;
	}

	const body = php.slice( start, start + 2000 );
	const added = [
		...body.matchAll( /\$row\['([A-Za-z][A-Za-z0-9_]*)'\]\s*=/g ),
	].map( ( found ) => found[ 1 ] );
	const unset = body.match( /unset\(([^)]*)\)/ );

	if ( 0 === added.length || ! unset ) {
		return null;
	}

	return {
		added,
		stripped: [
			...unset[ 1 ].matchAll( /\$row\['([A-Za-z][A-Za-z0-9_]*)'\]/g ),
		].map( ( found ) => found[ 1 ] ),
	};
}

/**
 * Whether the client reads one key off the creative it is rendering.
 *
 * @param {string} client Concatenated client sources.
 * @param {string} key    Payload key.
 */
function clientReadsCreative( client, key ) {
	return new RegExp( `creative(?:\\?)?\\.${ key }\\b` ).test( client );
}

function clientReads( client, key ) {
	return new RegExp( `context(?:\\?)?\\.${ key }\\b` ).test( client );
}

/**
 * Runs the check.
 *
 * @return {Promise<void>} Resolves when the lane has reported.
 */
async function main() {
	const problems = [];
	const required = [
		PHP_CONTEXT,
		FILL_CONTROLLER,
		DECISIONS_CONTROLLER,
		SLOT_RENDERER,
		DECISION_ENGINE,
		FILL_SERVICE,
		...CLIENT_FILES,
	];
	let scanned = 0;

	for ( const relative of required ) {
		try {
			await stat( resolve( relative ) );
		} catch {
			problems.push(
				`check-client-contract: ${ relative } does not exist, so this ` +
					'lane is protecting nothing. Update the path when the file moves.'
			);
		}
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		process.exit( 1 );
	}

	const php = await readFile( resolve( PHP_CONTEXT ), 'utf8' );
	const controller = codeOnly(
		await readFile( resolve( FILL_CONTROLLER ), 'utf8' )
	);
	const clientParts = [];

	for ( const relative of CLIENT_FILES ) {
		clientParts.push(
			codeOnly( await readFile( resolve( relative ), 'utf8' ) )
		);
		scanned += 1;
	}

	const client = clientParts.join( '\n' );
	const slotRenderer = codeOnly(
		await readFile( resolve( SLOT_RENDERER ), 'utf8' )
	);
	const engine = codeOnly(
		await readFile( resolve( DECISION_ENGINE ), 'utf8' )
	);
	const fillService = codeOnly(
		await readFile( resolve( FILL_SERVICE ), 'utf8' )
	);
	const keys = contextKeys( php );

	if ( null === keys || 0 === keys.length ) {
		console.error(
			'check-client-contract: resolved_context() was not found, or its ' +
				'return array has no keys, so this lane is reading nothing.'
		);
		process.exit( 1 );
	}

	let read = 0;

	for ( const key of keys ) {
		if ( ! clientReads( client, key ) ) {
			problems.push(
				`context.${ key } is sent by Slot_Options::resolved_context() and ` +
					'no runtime client file reads it. A key with no reader is how ' +
					'maxRefreshes shipped as a publisher cap the timer ignored.'
			);
			continue;
		}

		read += 1;
	}

	const fill =
		clientParts[
			CLIENT_FILES.indexOf( 'src/blocks-interactivity/ad-slot/fill.js' )
		];
	const view =
		clientParts[
			CLIENT_FILES.indexOf( 'src/blocks-interactivity/ad-slot/view.js' )
		];

	/*
	 * Every optional parameter the fill route declares needs a client that
	 * sends it.
	 *
	 * Derived from the controller rather than listed here, because the listed
	 * version only knew about `n` — and `w` was added, shipped, and would have
	 * gone unsent with this lane green. That is the same defect twice: a route
	 * that reads something the live client never writes, which looks like a
	 * feature nobody uses rather than a wire that was never connected.
	 *
	 * `slot` is excluded because it is a path segment rather than a query
	 * parameter, so it cannot be sent with `searchParams`.
	 */
	const declared = [
		...controller.matchAll( /'([a-z_]+)'\s*=> array\(\s*\n\s*'type'/g ),
	]
		.map( ( match ) => match[ 1 ] )
		.filter(
			( name ) =>
				! Object.prototype.hasOwnProperty.call( SERVER_SUPPLIED, name )
		);

	if ( 0 === declared.length ) {
		problems.push(
			'No fill-route parameters were found to check. The controller shape ' +
				'changed and this guard is now reading nothing.'
		);
	}

	for ( const name of declared ) {
		const sends = new RegExp(
			`searchParams\\.set\\(\\s*['"]${ name }['"]`
		);

		if ( ! sends.test( fill ) ) {
			problems.push(
				`fill.js does not searchParams.set( '${ name }', … ), but the ` +
					'fill route declares it. A parameter the live client never ' +
					'writes is a server reader with no writer.'
			);
		}

		if (
			! new RegExp( `get_param\\(\\s*'${ name }'\\s*\\)` ).test(
				controller
			)
		) {
			problems.push(
				`Fill_Controller declares '${ name }' and never calls ` +
					`get_param( '${ name }' ), so the client sends it into nothing.`
			);
		}
	}

	/*
	 * The other half of the split: a parameter the server is supposed to supply
	 * must actually be supplied somewhere, and must still be read.
	 */
	for ( const [ name, source ] of Object.entries( SERVER_SUPPLIED ) ) {
		if ( ! new RegExp( `'${ name }'\\s*=> array\\(` ).test( controller ) ) {
			continue;
		}

		if (
			! new RegExp( `get_param\\(\\s*'${ name }'\\s*\\)` ).test(
				controller
			)
		) {
			problems.push(
				`Fill_Controller declares '${ name }' and never calls ` +
					`get_param( '${ name }' ), so nothing reads what the server sends.`
			);
		}

		if ( null === source ) {
			continue;
		}

		const renderer = codeOnly(
			await readFile( resolve( source ), 'utf8' )
		);

		if (
			! new RegExp( `add_query_arg\\(\\s*'${ name }'` ).test( renderer )
		) {
			problems.push(
				`${ source } does not add_query_arg( '${ name }', … ), but the ` +
					`fill route declares it as server-supplied. A parameter no ` +
					`renderer writes is a server reader with no writer.`
			);
		}
	}

	/*
	 * The batch route, held to the same contract as the per-slot one.
	 *
	 * It went two years accepting a viewport and a post id that no caller ever
	 * sent, because nothing checked this side. Worse, the route had no browser
	 * caller at all — page coordination was implemented, tested and never once
	 * run for a visitor. A lane that only ever read the per-slot controller
	 * could not have noticed either.
	 */
	const decisions = codeOnly(
		await readFile( resolve( DECISIONS_CONTROLLER ), 'utf8' )
	);
	const batch = codeOnly( await readFile( resolve( BATCH_CLIENT ), 'utf8' ) );

	/*
	 * Anchored on the argument list's own indentation. An unanchored match also
	 * picks up `items`, the nested shape declaration inside `slots`, and then
	 * demands a reader and a writer for a parameter that does not exist.
	 */
	const batchDeclared = [
		...decisions.matchAll( /^\t{5}'([a-z_]+)'\s*=> array\(/gm ),
	].map( ( match ) => match[ 1 ] );

	if ( 0 === batchDeclared.length ) {
		problems.push(
			'No batch-route parameters were found to check. The decisions ' +
				'controller shape changed and this guard is now reading nothing.'
		);
	}

	for ( const name of batchDeclared ) {
		if (
			! new RegExp( `get_param\\(\\s*'${ name }'\\s*\\)` ).test(
				decisions
			)
		) {
			problems.push(
				`Decisions_Controller declares '${ name }' and never calls ` +
					`get_param( '${ name }' ), so the client sends it into nothing.`
			);
		}

		if ( ! new RegExp( `\\b${ name }\\s*:` ).test( batch ) ) {
			problems.push(
				`batch.js does not put '${ name }' in the request body, but the ` +
					'batch route declares it. A parameter the live client never ' +
					'writes is a server reader with no writer.'
			);
		}
	}

	/*
	 * And the route must be reachable from a rendered page at all. The slot
	 * renderer bakes its URL; nothing else can tell the browser where it is.
	 */
	/*
	 * Matched with its value, not by name alone. The attribute name also
	 * appears in the wrapper's escaping switch, so a bare name match stayed
	 * green when the attribute itself was removed from the array — this guard
	 * passed over exactly the change it exists to catch.
	 */
	if ( ! /'data-aggr-decisions'\s*=>\s*rest_url\(/.test( slotRenderer ) ) {
		problems.push(
			'The slot renderer no longer emits data-aggr-decisions, so no page ' +
				'can reach the batch route and page coordination stops running ' +
				'for every visitor, silently.'
		);
	}

	if ( ! /aggrDecisions/.test( batch ) ) {
		problems.push(
			'batch.js no longer reads dataset.aggrDecisions, so the URL the ' +
				'server bakes onto every slot has no reader.'
		);
	}

	/*
	 * A caller, not the definition. `batch.js` is one of the client files, and
	 * it exports `pageDecisions` — so searching every client file for the name
	 * found the export and reported a caller that did not exist, which is the
	 * production-dead route this whole lane was extended to prevent.
	 */
	const viewer = codeOnly(
		await readFile(
			resolve( 'src/blocks-interactivity/ad-slot/view.js' ),
			'utf8'
		)
	);

	if ( ! /pageDecisions\(/.test( viewer ) ) {
		problems.push(
			'view.js never calls pageDecisions(), so the batch route has no ' +
				'browser caller and page rules run for nobody.'
		);
	}

	if ( ! /get_param\(\s*'n'\s*\)/.test( controller ) ) {
		problems.push(
			"Fill_Controller no longer reads get_param( 'n' ), so the sequence " +
				'the client sends has no server reader.'
		);
	}

	if ( ! /searchParams\.set\(\s*['"]n['"]/.test( fill ) ) {
		problems.push(
			"fill.js does not searchParams.set( 'n', … ). The server reads a " +
				'sequence the live client never writes, so every rotation files as ' +
				'a page opportunity.'
		);
	}

	if ( ! /\bsequence\b/.test( fill ) || ! /String\(\s*n\s*\)/.test( fill ) ) {
		problems.push(
			'fill.js must send `n` from the sequence argument, not a literal. ' +
				'A baked-in n=0 is the increment() that nobody called.'
		);
	}

	if ( ! /fillSlot\s*\(\s*[^,)]+\s*,\s*rotations\s*\)/.test( view ) ) {
		problems.push(
			'view.js never calls fillSlot( …, rotations ). Sending n=0 on every ' +
				'tick is a write that does not meet the read.'
		);
	}

	if ( ! /rotationCap\s*\(\s*context(?:\?)?\.maxRefreshes\b/.test( view ) ) {
		problems.push(
			'view.js does not call rotationCap( context.maxRefreshes ). A bare ' +
				'context.maxRefreshes identifier next to MAX_ROTATIONS is how the ' +
				'publisher cap shipped unread.'
		);
	}

	const e2eDir = resolve( 'tests/e2e' );
	let e2eScanned = 0;

	try {
		const entries = await readdir( e2eDir );

		for ( const name of entries ) {
			if ( ! /\.(php|ts|js)$/.test( name ) ) {
				continue;
			}

			const source = codeOnly(
				await readFile( path.join( e2eDir, name ), 'utf8' )
			);

			e2eScanned += 1;

			const asks = /rotate"\s*:\s*true|rotate=["']true["']/.test(
				source
			);
			const grants = /set_refresh_policy\s*\(/.test( source );

			if ( asks && ! grants ) {
				problems.push(
					`tests/e2e/${ name }: asks rotate:true and does not call ` +
						'set_refresh_policy() in this file. A grant in another seed ' +
						'does not cover a new rotating page — that placement is ' +
						'created after activation and defaults to refresh off.'
				);
			}
		}
	} catch {
		problems.push(
			'check-client-contract: tests/e2e does not exist, so the seed half ' +
				'of this lane is protecting nothing.'
		);
	}

	/*
	 * **Every value the server puts on a creative, the browser must read.**
	 *
	 * This is the half the lane was missing, and it is the half that broke
	 * twice. `_aggr_target_blank` was stored, carried across every creative
	 * revision, and read by nothing — an advertisement could not be made to
	 * open a new tab because the wire was never connected. `servable` is the
	 * same shape of key added since. Neither surface was watched, because the
	 * checks above read slot context and fill *parameters*, and a creative
	 * payload is neither.
	 *
	 * Scoped to the creative object rather than the whole response. That is
	 * where a key means "render this", so an unread one is a feature that does
	 * nothing. The response's own top-level `slot` and `size` are the reply
	 * describing itself and are read by no browser, which is a weaker claim
	 * this lane deliberately does not make — an exemption list is how a guard
	 * turns into a blind spot, so the boundary is stated instead of carved
	 * out.
	 */
	const paidKeys = returnedKeys( engine, 'payload_from_row' );
	const houseKeys = returnedKeys( fillService, 'house_creative' );
	const lateKeys = assignedKeys( fillService, 'paid_creative', 'payload' );
	const effects = tokenEffects( fillService );

	if (
		null === paidKeys ||
		null === houseKeys ||
		null === lateKeys ||
		null === effects
	) {
		problems.push(
			'check-client-contract: could not read the creative payload out of ' +
				`${ DECISION_ENGINE } and ${ FILL_SERVICE }. This lane is ` +
				'protecting nothing until the parse is fixed — do not delete it ' +
				'to get green.'
		);
	}

	let payloadRead = 0;

	if (
		null !== paidKeys &&
		null !== houseKeys &&
		null !== lateKeys &&
		null !== effects
	) {
		const sent = [
			...new Set( [
				...paidKeys,
				...houseKeys,
				...lateKeys,
				...effects.added,
			] ),
		].filter( ( key ) => ! effects.stripped.includes( key ) );

		if ( 0 === sent.length ) {
			problems.push(
				'check-client-contract: the creative payload parsed as empty, ' +
					'so every assertion below it is vacuous.'
			);
		}

		for ( const key of sent ) {
			payloadRead += 1;

			if ( ! clientReadsCreative( client, key ) ) {
				problems.push(
					`check-client-contract: the fill response puts "${ key }" on ` +
						'a creative and no client file reads it. Either the ' +
						'browser half was never written, or the key is dead ' +
						'weight — both were real defects here.'
				);
			}
		}

		/*
		 * The opposite assertion for the keys `with_tokens()` removes, rather
		 * than letting them skip the check. A client reading one of these is
		 * reading `undefined` on every fill, which is silent: `placement`,
		 * `campaign` and `creative` exist only long enough to mint a token.
		 */
		for ( const key of effects.stripped ) {
			if ( clientReadsCreative( client, key ) ) {
				problems.push(
					`check-client-contract: a client file reads "${ key }" off a ` +
						'creative, but with_tokens() removes it before the ' +
						'response is sent. That read is undefined on every fill.'
				);
			}
		}
	}

	if ( problems.length > 0 ) {
		console.error( problems.join( '\n' ) );
		process.exit( 1 );
	}

	console.log(
		`check-client-contract: ok (${ read } context keys, ${ payloadRead } ` +
			`creative payload keys, ${ scanned } client files, ${ e2eScanned } ` +
			'e2e files)'
	);
}

await main();
