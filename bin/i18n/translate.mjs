#!/usr/bin/env node
/**
 * Machine-translate empty / fuzzy PO entries through a local model.
 *
 * One provider: an OpenAI-compatible server you run yourself (LM Studio,
 * Ollama, llama.cpp). Nothing leaves the machine, and CI cannot reach it, so
 * translating is a local, deliberate act rather than something a runner does
 * on a push.
 *
 * Only fills empty or fuzzy entries. A clean translation is never overwritten
 * and never re-sent, so a second run costs nothing; a string removed from the
 * source keeps its translation, commented out, in case it comes back.
 *
 * Usage:
 *   node bin/i18n/translate.mjs [--locale=de_DE] [--dry-run] [--limit=N]
 *
 * Env:
 *   I18N_LOCAL_URL=…                  (base URL ending in /v1)
 *   I18N_LOCAL_MODEL=…                (model id the server serves)
 *   I18N_LOCAL_API_KEY=…              (optional bearer token)
 *   I18N_LOCAL_TIMEOUT_MS=180000      (per-request timeout)
 *   I18N_LOCAL_RETRY_DELAYS_MS=…      (optional backoff override, comma list)
 *   I18N_MT_DELAY_MS=0                (pause between requests)
 *
 * Local settings: copy `.env.example` → `.env.local` (gitignored). Existing
 * process env wins over file values.
 */

import fs from 'node:fs';
import path from 'node:path';

import {
	classifyMtFailure,
	judgeRun,
	providerMix,
	refusalAnnotations,
} from './run-completeness.mjs';
import { fileURLToPath } from 'node:url';

import { entryPlaceholdersIntact, parsePo } from './po.mjs';
import {
	checkLocalProvider,
	isSupportedLocale,
	localProviderDownMessage,
	localRunIncompleteMessage,
	mt,
} from './providers.mjs';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );
const PLUGIN_ROOT = path.resolve( __dirname, '../..' );
const LANGUAGES = path.join( PLUGIN_ROOT, 'languages' );
const TEXT_DOMAIN = 'aggressive-ads';

/**
 * Load KEY=value pairs from gitignored dotenv files into process.env.
 * Does not override variables already set (shell / CI take precedence).
 */
function loadLocalEnv() {
	for ( const name of [ '.env.local', '.env' ] ) {
		const file = path.join( PLUGIN_ROOT, name );
		if ( ! fs.existsSync( file ) ) {
			continue;
		}

		for ( const rawLine of fs.readFileSync( file, 'utf8' ).split( '\n' ) ) {
			const line = rawLine.trim();
			if ( ! line || line.startsWith( '#' ) ) {
				continue;
			}

			const eq = line.indexOf( '=' );
			if ( eq <= 0 ) {
				continue;
			}

			const key = line.slice( 0, eq ).trim();
			let value = line.slice( eq + 1 ).trim();
			if (
				( value.startsWith( "'" ) && value.endsWith( "'" ) ) ||
				( value.startsWith( '"' ) && value.endsWith( '"' ) )
			) {
				value = value.slice( 1, -1 );
			}

			if ( process.env[ key ] === undefined ) {
				process.env[ key ] = value;
			}
		}
	}
}

function parseArgs( argv ) {
	const out = { locale: null, dryRun: false, limit: Infinity };
	for ( const arg of argv ) {
		if ( arg === '--dry-run' ) {
			out.dryRun = true;
		} else if ( arg.startsWith( '--locale=' ) ) {
			out.locale = arg.slice( '--locale='.length );
		} else if ( arg.startsWith( '--limit=' ) ) {
			out.limit = Number.parseInt( arg.slice( '--limit='.length ), 10 );
		}
	}
	return out;
}

function sleep( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

function escapePo( str ) {
	return str
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\t/g, '\\t' )
		.replace( /\n/g, '\\n' );
}

function formatPoString( keyword, value ) {
	if ( ! value.includes( '\n' ) ) {
		return `${ keyword } "${ escapePo( value ) }"`;
	}
	const parts = value.split( '\n' );
	const lines = [ `${ keyword } ""` ];
	for ( let i = 0; i < parts.length; i++ ) {
		const piece = parts[ i ] + ( i < parts.length - 1 ? '\n' : '' );
		if ( piece.length ) {
			lines.push( `"${ escapePo( piece ) }"` );
		}
	}
	return lines.join( '\n' );
}

function serializeEntry( entry ) {
	// An obsolete entry goes back exactly as it arrived: it is a record of a
	// translation for a string the source no longer has, not something this
	// run has any business rewriting.
	if ( entry.obsolete ) {
		return String( entry.raw ).trimEnd();
	}

	// gettext comment order: # / #. / #: / #, / #| / msgid…
	const translator = [];
	const extracted = [];
	const references = [];
	const previous = [];
	const other = [];

	for ( const comment of entry.comments ) {
		if ( comment.startsWith( '#.' ) ) {
			extracted.push( comment );
		} else if ( comment.startsWith( '#:' ) ) {
			references.push( comment );
		} else if ( comment.startsWith( '#|' ) ) {
			previous.push( comment );
		} else if ( comment.startsWith( '# ' ) || comment === '#' ) {
			translator.push( comment );
		} else {
			other.push( comment );
		}
	}

	const lines = [ ...translator, ...extracted, ...references, ...other ];

	/*
	 * Flags are written exactly as they stand. This used to clear `fuzzy` and
	 * stamp `aggr-mt` on every entry it serialized, which is the whole
	 * catalog — not the handful this run translated — and both halves of that
	 * were destructive.
	 *
	 * Clearing `fuzzy` promoted every uncertain match msgmerge had just made
	 * to a finished translation. That is how the third failing string on
	 * master got there: msgmerge fuzzy-matched a new "…cannot be used: %s"
	 * onto the older translation of the same sentence without the `%s`, marked
	 * it fuzzy exactly as it should, and the next write silently un-marked it.
	 * The lint skips fuzzy entries because gettext falls back to English for
	 * them, so removing the flag is what turned a correct draft into a
	 * published string missing its placeholder.
	 *
	 * Stamping `aggr-mt` relabelled reviewed human translations as machine
	 * output, and that flag is what marks a machine draft as a
	 * restorable draft — so a person's work became something a later run felt
	 * free to treat as its own.
	 *
	 * Neither line was needed: the translation loop already sets both flags on
	 * the entries it actually fills.
	 */
	if ( entry.flags.size ) {
		lines.push( `#, ${ [ ...entry.flags ].join( ', ' ) }` );
	}
	// Previous-string comments must sit immediately before msgid.
	lines.push( ...previous );
	if ( entry.msgctxt !== null ) {
		lines.push( formatPoString( 'msgctxt', entry.msgctxt ) );
	}
	lines.push( formatPoString( 'msgid', entry.msgid ) );
	if ( entry.msgidPlural !== null ) {
		lines.push( formatPoString( 'msgid_plural', entry.msgidPlural ) );
		const keys = Object.keys( entry.msgstrs )
			.filter( ( k ) => k.startsWith( 'msgstr[' ) )
			.sort();
		for ( const key of keys ) {
			lines.push( formatPoString( key, entry.msgstrs[ key ] ?? '' ) );
		}
	} else {
		lines.push( formatPoString( 'msgstr', entry.msgstrs.msgstr ?? '' ) );
	}
	return lines.join( '\n' );
}

function serializePo( header, entries ) {
	const chunks = [];
	if ( header ) {
		chunks.push( header.trimEnd() );
	}
	for ( const entry of entries ) {
		chunks.push( serializeEntry( entry ) );
	}
	return `${ chunks.join( '\n\n' ) }\n`;
}

function needsTranslation( entry ) {
	const isFuzzy = entry.flags.has( 'fuzzy' );
	if ( entry.msgidPlural !== null ) {
		const values = Object.values( entry.msgstrs );
		const empty = values.length === 0 || values.some( ( v ) => v === '' );
		return empty || isFuzzy;
	}
	const msgstr = entry.msgstrs.msgstr ?? '';
	return msgstr === '' || isFuzzy;
}

function listPoFiles( localeFilter ) {
	const files = fs
		.readdirSync( LANGUAGES )
		.filter(
			( f ) =>
				f.startsWith( `${ TEXT_DOMAIN }-` ) &&
				f.endsWith( '.po' ) &&
				! f.includes( 'aa_TEST' )
		)
		.map( ( f ) => path.join( LANGUAGES, f ) )
		.sort();

	if ( ! localeFilter ) {
		return files;
	}
	const wanted = path.join(
		LANGUAGES,
		`${ TEXT_DOMAIN }-${ localeFilter }.po`
	);
	return files.filter( ( f ) => f === wanted );
}

function localeFromPo( file ) {
	return path.basename( file, '.po' ).slice( `${ TEXT_DOMAIN }-`.length );
}

/**
 * The developer's notes on an entry, for a translator that can read them.
 *
 * `#.` lines are extracted comments: the `translators:` notes naming what each
 * placeholder is. This run's own "Auto-translated (aggr-mt)" marker is also an
 * extracted comment and is not advice, so it is left out.
 *
 * @param {Record<string, any>} entry Parsed entry.
 * @return {string}
 */
export function translatorNotes( entry ) {
	return ( entry.comments ?? [] )
		.filter(
			( c ) =>
				c.startsWith( '#.' ) &&
				! c.includes( 'Auto-translated (aggr-mt)' )
		)
		.map( ( c ) =>
			c.replace( /^#\.\s*/, '' ).replace( /^translators:\s*/i, '' )
		)
		.join( ' ' )
		.trim();
}

export async function translatePoFile( file, opts ) {
	const locale = localeFromPo( file );
	if ( ! isSupportedLocale( locale ) ) {
		console.warn(
			`i18n:translate: skip ${ locale } (add it to LOCAL_LANGUAGE in providers.mjs)`
		);
		return {
			locale,
			updated: 0,
			skipped: 0,
			remaining: 0,
			truncated: false,
			refused: [],
		};
	}

	const delay = Number.parseInt( process.env.I18N_MT_DELAY_MS || '350', 10 );
	const flushEvery = Number.parseInt(
		process.env.I18N_FLUSH_EVERY || '25',
		10
	);

	const content = fs.readFileSync( file, 'utf8' );
	const { header, entries } = parsePo( content );
	let updated = 0;
	let skipped = 0;
	let lastVia = 'local';
	/*
	 * Which engine answered, counted rather than assumed. There is one
	 * provider now, and the tally is still what tells a reviewer what wrote
	 * the catalog in front of them.
	 */
	const providers = {};
	let truncated = false;
	/** @type {string[]} Sources whose translation came back unusable. */
	const refused = [];

	/** @type {string[]} Machine entries held back for a person. */
	const demoted = [];
	let dirty = false;

	/**
	 * Sweeps the catalog and writes it as it stands.
	 *
	 * **Called as the run goes, not only at the end.** A full catalog takes
	 * well over an hour on a local model, and writing once at the end meant an
	 * interruption — a reboot, a closed session, a stopped process — threw away
	 * everything the run had done. It did: one German run translated 567
	 * strings and left nothing on disk.
	 *
	 * The sweep is the backstop, not the fix: no entry this run is responsible
	 * for may leave here claiming to be a finished translation while carrying
	 * the wrong placeholders. Anything that does is demoted to fuzzy, which is
	 * what it actually is — a draft — and which the lint and gettext both treat
	 * as one. mt() already refuses such a translation and serializeEntry() no
	 * longer strips `fuzzy` from entries this run never touched; this is here
	 * because both of those were found by reading a failing job rather than by
	 * anything asserting the invariant.
	 *
	 * Only the machine's own output is swept. A human translation that fails
	 * parity is left exactly as it is, to fail the lint loudly, because quietly
	 * flagging somebody's work hides a problem only they can fix.
	 */
	const flush = () => {
		for ( const entry of entries ) {
			if (
				entry.obsolete ||
				entry.flags.has( 'fuzzy' ) ||
				! entry.flags.has( 'aggr-mt' )
			) {
				continue;
			}

			if ( entryPlaceholdersIntact( entry ) ) {
				continue;
			}

			entry.flags.add( 'fuzzy' );
			demoted.push( entry.msgid );
			refused.push( entry.msgid );
			dirty = true;
		}

		if ( ! dirty || opts.dryRun ) {
			return;
		}

		fs.writeFileSync( file, serializePo( header, entries ), 'utf8' );
	};

	for ( const entry of entries ) {
		// Not a string anybody can translate: it is already commented out.
		if ( entry.obsolete ) {
			continue;
		}

		if ( ! needsTranslation( entry ) ) {
			skipped += 1;
			continue;
		}
		if ( updated >= opts.limit ) {
			break;
		}

		try {
			if ( entry.msgidPlural !== null ) {
				const singular = await mt(
					entry.msgid,
					locale,
					entry.msgctxt,
					translatorNotes( entry )
				);
				await sleep( delay );
				const plural = await mt(
					entry.msgidPlural,
					locale,
					entry.msgctxt,
					translatorNotes( entry )
				);
				entry.msgstrs[ 'msgstr[0]' ] = singular.text;
				entry.msgstrs[ 'msgstr[1]' ] = plural.text;
				lastVia = plural.via;
				providers[ lastVia ] = ( providers[ lastVia ] ?? 0 ) + 1;
			} else {
				const result = await mt(
					entry.msgid,
					locale,
					entry.msgctxt,
					translatorNotes( entry )
				);
				entry.msgstrs.msgstr = result.text;
				lastVia = result.via;
				providers[ lastVia ] = ( providers[ lastVia ] ?? 0 ) + 1;
			}
			entry.flags.delete( 'fuzzy' );
			entry.flags.add( 'aggr-mt' );
			// Drop msgmerge `#|` previous-string hints once filled — they break
			// msgfmt if other comments/flags are emitted after them.
			entry.comments = entry.comments.filter(
				( c ) => ! c.startsWith( '#|' )
			);
			if (
				! entry.comments.some( ( c ) =>
					c.includes( 'Auto-translated (aggr-mt)' )
				)
			) {
				entry.comments.push(
					`#. Auto-translated (aggr-mt) via ${ lastVia } — review before release.`
				);
			}
			updated += 1;
			dirty = true;
			process.stdout.write( '.' );

			if ( flushEvery > 0 && 0 === updated % flushEvery ) {
				flush();
			}
		} catch ( err ) {
			console.warn(
				`\ni18n:translate: ${ locale }: "${ entry.msgid.slice(
					0,
					60
				) }…" → ${ err.message }`
			);

			/*
			 * A refused string is this string's problem and says nothing about
			 * the next one. Leave it untranslated — empty or still fuzzy, both
			 * of which the lint skips and gettext falls back to English for —
			 * and keep going. Stopping the locale here is what deadlocked the
			 * drafting workflow: validation runs before the draft branch is
			 * written, so one string the provider kept mangling threw away the
			 * other thousand every run, and the next run regenerated it.
			 */
			if ( 'refused' === classifyMtFailure( err ) ) {
				refused.push( entry.msgid );
				await sleep( delay );
				continue;
			}
			/*
			 * Stop this locale on a quota or hard error, so whatever was
			 * translated is still written rather than lost.
			 *
			 * The stop is deliberate; reporting success afterwards was not.
			 * `judgeRun()` turns a truncated locale into a failure once every
			 * file has been written, so the work survives and the claim does
			 * not. See bin/i18n/run-completeness.mjs.
			 */
			if ( 'provider-stop' === classifyMtFailure( err ) ) {
				truncated = true;
				break;
			}
		}

		await sleep( delay );
	}

	process.stdout.write( '\n' );

	flush();

	if ( demoted.length > 0 ) {
		console.warn(
			`i18n:translate: ${ locale }: ${ demoted.length } machine ` +
				'translation(s) held back as fuzzy — wrong placeholders, and ' +
				'not refused at request time. This should not happen; see ' +
				'docs/i18n.md.'
		);
	}

	// Counted from the entries as they now stand, not inferred from arithmetic:
	// a `break` leaves the two out of step, and the count is the whole point.
	const remaining = entries.filter(
		( entry ) => ! entry.obsolete && needsTranslation( entry )
	).length;

	return {
		locale,
		updated,
		skipped,
		remaining,
		truncated,
		refused,
		providers,
		provider: 'local',
	};
}

async function main() {
	loadLocalEnv();

	const opts = parseArgs(
		process.argv.slice( 2 ).filter( ( a ) => a !== '--' )
	);
	const files = listPoFiles( opts.locale );

	if ( ! files.length ) {
		console.log(
			'i18n:translate: No locale .po files. Scaffold one with: pnpm i18n:locale -- fr_FR'
		);
		process.exit( 0 );
	}

	/*
	 * Checked before any catalog is touched. An unreachable server otherwise
	 * fails every string one at a time, each skipped as a one-off error, and
	 * the run ends a thousand strings short without ever saying the server was
	 * not there.
	 */
	const health = await checkLocalProvider();

	if ( ! health.ok ) {
		console.error( localProviderDownMessage( health.reason ) );
		process.exit( 1 );
	}

	console.log(
		`i18n:translate: model=${ process.env.I18N_LOCAL_MODEL } at ${ process.env.I18N_LOCAL_URL } dryRun=${ opts.dryRun }`
	);

	let total = 0;
	const results = [];

	for ( const file of files ) {
		const result = await translatePoFile( file, opts );
		console.log(
			`i18n:translate: ${ result.locale }: updated=${ result.updated } already-ok=${ result.skipped } remaining=${ result.remaining } refused=${ result.refused.length }`
		);
		total += result.updated;
		results.push( result );
	}

	console.log( `i18n:translate: Done. ${ total } string(s) filled.` );

	/*
	 * Named on the run and, when the workflow asks for it, written where the
	 * pull request body can pick it up. A reviewer must not have to grep a PO
	 * comment to find out which engine wrote the German in front of them.
	 */
	const mix = providerMix( results );

	console.log(
		`i18n:translate: engines — ${ JSON.stringify( mix.counts ) }`
	);

	if ( process.env.AGGR_I18N_SUMMARY_FILE ) {
		fs.writeFileSync(
			process.env.AGGR_I18N_SUMMARY_FILE,
			mix.summary,
			'utf8'
		);
	}

	/*
	 * Named, not just counted. A refusal means a human has to translate that
	 * string, and a number alone tells nobody which one.
	 */
	const refusals = results.filter( ( r ) => r.refused.length > 0 );

	if ( refusals.length > 0 ) {
		console.log(
			'\ni18n:translate: left for a human — the translation was refused ' +
				'(an echoed source, an empty answer, or the wrong placeholders):'
		);

		for ( const result of refusals ) {
			for ( const msgid of result.refused ) {
				console.log( `  ${ result.locale }: "${ msgid }"` );
			}
		}

		/*
		 * And on the run itself, not only in a log nobody opens. A refusal is
		 * not a failure, so without this the string would go missing quietly.
		 */
		if ( process.env.GITHUB_ACTIONS ) {
			for ( const line of refusalAnnotations( results ) ) {
				console.log( line );
			}
		}
	}
	if ( opts.dryRun && total > 0 ) {
		console.log( 'i18n:translate: dry-run — no files written.' );
	}

	/*
	 * Every file is written by now, so failing here keeps the work and drops
	 * only the claim that the run finished. A partial set of catalogs opening a
	 * pull request that looks complete is the failure this prevents.
	 */
	const verdict = judgeRun( results, {
		limited: Number.isFinite( opts.limit ),
	} );

	if ( ! verdict.ok ) {
		/*
		 * A local model gets its own words: "the run did not finish" reads as
		 * a quota or a network blip, and the fix here is to start the model
		 * again — or correct its address in .env.local.
		 */
		console.error( localRunIncompleteMessage() );

		for ( const problem of verdict.problems ) {
			console.error( `  ${ problem }` );
		}

		console.error(
			'\nRe-run once the provider is answering again. Translation is ' +
				'incremental, so a re-run only fills what is still missing.'
		);

		process.exit( 1 );
	}
}

/*
 * Guarded so the module can be imported by a test. Without this, `mt()` could only be exercised by running the whole script
 * against a paid API — which is why the refusal path had never been tested
 * through the code that raises it, only through an error a test built itself.
 */
if (
	process.argv[ 1 ] &&
	path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	main().catch( ( err ) => {
		console.error( err );
		process.exit( 1 );
	} );
}
