#!/usr/bin/env node
/**
 * Machine-translate empty / fuzzy PO entries.
 *
 * Default (`auto`): DeepL when DEEPL_AUTH_KEY is set, falling back to MyMemory
 * if DeepL fails (bad key, quota exhausted, outage); MyMemory alone when no key
 * is present. DeepL leads because MyMemory is a translation-memory aggregator —
 * it returns whole-segment matches from unrelated corpora, which arrive with
 * punctuation and register the source never had (a bare "Measurements in
 * inches" came back as "Le misure sono rappresentate in pollici.").
 * Only fills empty msgstr or fuzzy entries — never overwrites clean translations.
 *
 * Usage:
 *   node bin/i18n/translate.mjs [--locale=fr_FR] [--dry-run] [--limit=N]
 *
 * Env:
 *   I18N_MT_PROVIDER=auto|mymemory|deepl
 *                                     (default: auto; mymemory = MyMemory first;
 *                                      deepl = DeepL-only, no fallback)
 *   DEEPL_AUTH_KEY=…                  (enables DeepL; without it auto = MyMemory)
 *   I18N_MT_EMAIL=…                   (optional; raises MyMemory daily quota)
 *   I18N_MT_DELAY_MS=350              (pause between requests)
 *
 * Local secrets: copy `.env.example` → `.env.local` (gitignored). Existing
 * process env wins over file values.
 */

import fs from 'node:fs';
import path from 'node:path';

import {
	MT_REFUSED,
	classifyMtFailure,
	judgeRun,
	refusalAnnotations,
} from './run-completeness.mjs';
import { fileURLToPath } from 'node:url';

import {
	PLACEHOLDER_PATTERN,
	entryPlaceholdersIntact,
	extractPlaceholders,
	parsePo,
	placeholdersIntact,
} from './po.mjs';

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

/** WordPress locale → MyMemory / DeepL language code. */
const LOCALE_MAP = {
	fr_FR: { mymemory: 'fr', deepl: 'FR' },
	fr_CA: { mymemory: 'fr', deepl: 'FR' },
	es_ES: { mymemory: 'es', deepl: 'ES' },
	es_MX: { mymemory: 'es', deepl: 'ES' },
	de_DE: { mymemory: 'de', deepl: 'DE' },
	it_IT: { mymemory: 'it', deepl: 'IT' },
	pt_BR: { mymemory: 'pt-BR', deepl: 'PT-BR' },
	pt_PT: { mymemory: 'pt', deepl: 'PT-PT' },
	nl_NL: { mymemory: 'nl', deepl: 'NL' },
	pl_PL: { mymemory: 'pl', deepl: 'PL' },
	sv_SE: { mymemory: 'sv', deepl: 'SV' },
	ja: { mymemory: 'ja', deepl: 'JA' },
	ko_KR: { mymemory: 'ko', deepl: 'KO' },
	zh_CN: { mymemory: 'zh-CN', deepl: 'ZH' },
};

const DO_NOT_TRANSLATE = [
	// Chosen from what the catalog actually contains, not from a guess at what
	// an advertising plugin might say. Each of these is a proper noun or an
	// industry acronym that has one spelling everywhere, and MT will happily
	// localise all of them: MyMemory returns "Bibliothèque de médias" for the
	// screen WordPress itself already translates, and turns CTR into a word.
	'Aggressive Ads',
	'WordPress',
	'CTR',
	'CSV',
];

/**
 * Post-MT glossary fixes by WordPress locale.
 *
 * Deliberately empty. The theme this pipeline came from carries a long list,
 * and every entry in it is a commerce term — drops, lookbooks, product badges —
 * that would be nonsense here. A glossary is a record of mistakes MT actually
 * made on *this* catalog, so it earns its entries after the first review pass
 * rather than arriving pre-filled with someone else's.
 *
 * Applied after placeholder and brand restoration; longest keys first.
 *
 * @type {Record<string, Array<[string, string]>>}
 */
const LOCALE_GLOSSARY = {};

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
	 * output, and resume-progress.mjs keys on that flag to decide what is a
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

/**
 * One string's translation is unusable. Marked so the run can tell it apart
 * from the provider going quiet: the message names the offending source, and
 * matching a quota regex against that text read `"Limit to one advertiser"` as
 * an exhausted quota. See classifyMtFailure() in run-completeness.mjs.
 *
 * @param {string} message
 */
function refusal( message ) {
	const err = new Error( message );
	err.code = MT_REFUSED;

	return err;
}

/**
 * ASCII-only tokens — Unicode brackets get mangled by MyMemory.
 *
 * @param {string} text
 */
function protectPlaceholders( text ) {
	const tokens = [];
	const protectedText = text.replace(
		new RegExp( PLACEHOLDER_PATTERN.source, 'g' ),
		( match ) => {
			const idx = tokens.length;
			tokens.push( match );
			return `__AGGR_PH_${ idx }__`;
		}
	);
	return { protectedText, tokens };
}

function restorePlaceholders( text, tokens ) {
	return text.replace(
		/__AGGR_PH_(\d+)__/g,
		( _, n ) => tokens[ Number( n ) ] ?? ''
	);
}

function protectBrandTerms( text ) {
	const tokens = [];
	let out = text;
	for ( const term of DO_NOT_TRANSLATE ) {
		if ( ! out.includes( term ) ) {
			continue;
		}
		const idx = tokens.length;
		tokens.push( term );
		out = out.split( term ).join( `__AGGR_BR_${ idx }__` );
	}
	return { text: out, tokens };
}

function restoreBrandTerms( text, tokens ) {
	return text.replace(
		/__AGGR_BR_(\d+)__/g,
		( _, n ) => tokens[ Number( n ) ] ?? ''
	);
}

/**
 * MyMemory sometimes emits HTML entities (e.g. &#10; for newline).
 *
 * @param {string} text
 */
function sanitizeMtOutput( text ) {
	return text
		.replace( /&#10;|&#x0a;/gi, '\n' )
		.replace( /&quot;/g, '"' )
		.replace( /&apos;/g, "'" )
		.replace( /&lt;/g, '<' )
		.replace( /&gt;/g, '>' )
		.replace( /&amp;/g, '&' )
		.replace( /\s+$/g, '' );
}

/**
 * @param {string} text
 * @param {string} locale
 */
function applyLocaleGlossary( text, locale ) {
	const rules = LOCALE_GLOSSARY[ locale ];
	if ( ! rules ) {
		return text;
	}
	let out = text;
	for ( const [ from, to ] of rules ) {
		out = out.split( from ).join( to );
	}
	return out;
}

async function translateMyMemory( text, lang ) {
	const email = process.env.I18N_MT_EMAIL || '';
	const url = new URL( 'https://api.mymemory.translated.net/get' );
	url.searchParams.set( 'q', text.slice( 0, 500 ) );
	url.searchParams.set( 'langpair', `en|${ lang }` );
	if ( email ) {
		url.searchParams.set( 'de', email );
	}

	const res = await fetch( url );
	if ( ! res.ok ) {
		throw new Error( `MyMemory HTTP ${ res.status }` );
	}
	const data = await res.json();
	const translated = data?.responseData?.translatedText;
	if ( ! translated || data?.responseStatus !== 200 ) {
		throw new Error(
			`MyMemory failed: ${
				data?.responseDetails || data?.responseStatus
			}`
		);
	}
	// MyMemory sometimes echoes the query when it cannot translate.
	if ( translated === text ) {
		return text;
	}
	return translated;
}

/**
 * Translate one string through DeepL.
 *
 * `context` is the entry's msgctxt when it has one. DeepL uses it to
 * disambiguate and neither translates nor bills it, which is exactly what a
 * gettext context is for.
 *
 * Without it, `_x()` disambiguation is invisible to machine translation: the
 * context reaches the .po file and the human reading it, and never reaches the
 * translator. "State" on the organizations screen is why this exists — with a
 * context of "organization status column heading" already in the catalogue,
 * DeepL still returned "Bundesland", a federal state, because nothing sent it.
 *
 * @param {string}      text    Source string, placeholders already protected.
 * @param {string}      lang    DeepL target language code.
 * @param {string|null} context Gettext msgctxt, or null.
 * @return {Promise<string>} Translated text.
 */
async function translateDeepL( text, lang, context = null ) {
	const key = process.env.DEEPL_AUTH_KEY;
	if ( ! key ) {
		throw new Error( 'DEEPL_AUTH_KEY is not set' );
	}
	const endpoint = key.endsWith( ':fx' )
		? 'https://api-free.deepl.com/v2/translate'
		: 'https://api.deepl.com/v2/translate';
	const body = new URLSearchParams();
	body.set( 'text', text );
	body.set( 'source_lang', 'EN' );
	body.set( 'target_lang', lang );
	body.set( 'preserve_formatting', '1' );

	if ( context ) {
		body.set( 'context', context );
	}

	const res = await fetch( endpoint, {
		method: 'POST',
		headers: {
			Authorization: `DeepL-Auth-Key ${ key }`,
			'Content-Type': 'application/x-www-form-urlencoded',
		},
		body,
	} );
	if ( ! res.ok ) {
		throw new Error( `DeepL HTTP ${ res.status }: ${ await res.text() }` );
	}
	const data = await res.json();
	return data?.translations?.[ 0 ]?.text ?? text;
}

/**
 * Resolve provider mode.
 *
 * - auto (default): DeepL first when a key is set, MyMemory on DeepL failure.
 *   With no key it degrades to plain MyMemory, so a fresh clone and CI without
 *   the secret still work with no configuration.
 * - mymemory: MyMemory first; DeepL only if MyMemory fails and key exists.
 * - deepl: DeepL only, no fallback (requires DEEPL_AUTH_KEY).
 *
 * @returns {'auto' | 'mymemory' | 'deepl'}
 */
function resolveProviderMode() {
	const raw = ( process.env.I18N_MT_PROVIDER || 'auto' ).toLowerCase();

	if ( raw === 'deepl' || raw === 'mymemory' ) {
		return raw;
	}

	return 'auto';
}

/**
 * @param {string} text
 * @param {{ mymemory: string, deepl: string }} localeCodes
 * @param {'mymemory' | 'deepl'} mode
 * @param {string} locale
 * @returns {Promise<{ text: string, via: string }>}
 */
export async function mt( text, localeCodes, mode, locale, context = null ) {
	const { protectedText, tokens: ph } = protectPlaceholders( text );
	const brand = protectBrandTerms( protectedText );
	let out;
	let via;

	const hasDeeplKey = Boolean( process.env.DEEPL_AUTH_KEY );

	if ( mode === 'deepl' ) {
		out = await translateDeepL( brand.text, localeCodes.deepl, context );
		via = 'deepl';
	} else if ( mode === 'auto' && hasDeeplKey ) {
		try {
			out = await translateDeepL(
				brand.text,
				localeCodes.deepl,
				context
			);
			via = 'deepl';
		} catch ( err ) {
			console.warn(
				`\ni18n:translate: DeepL failed (${ err.message }); falling back to MyMemory`
			);
			out = await translateMyMemory( brand.text, localeCodes.mymemory );
			via = 'mymemory-fallback';
		}
	} else {
		try {
			out = await translateMyMemory( brand.text, localeCodes.mymemory );
			via = 'mymemory';
		} catch ( err ) {
			if ( ! hasDeeplKey ) {
				throw err;
			}
			console.warn(
				`\ni18n:translate: MyMemory failed (${ err.message }); falling back to DeepL`
			);
			out = await translateDeepL(
				brand.text,
				localeCodes.deepl,
				context
			);
			via = 'deepl-fallback';
		}
	}

	out = restoreBrandTerms( out, brand.tokens );
	out = restorePlaceholders( out, ph );
	out = sanitizeMtOutput( out );
	out = applyLocaleGlossary( out, locale );

	if ( ! placeholdersIntact( text, out ) ) {
		throw refusal(
			`MT returned the wrong placeholders (source=${ text.slice(
				0,
				40
			) }…, expected ${
				extractPlaceholders( text ).join( ' ' ) || 'none'
			}` + `, got ${ extractPlaceholders( out ).join( ' ' ) || 'none' })`
		);
	}

	// Reject leftover HTML entities MyMemory invents (e.g. Progress → Progrès&#10;).
	if ( /&#\w+;|&[a-z]+;/i.test( out ) && ! /&#\w+;|&[a-z]+;/i.test( text ) ) {
		throw refusal(
			`MT injected HTML entities (source=${ text.slice( 0, 40 ) }…)`
		);
	}

	return { text: out, via };
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

export async function translatePoFile( file, opts ) {
	const locale = localeFromPo( file );
	const codes = LOCALE_MAP[ locale ];
	if ( ! codes ) {
		console.warn(
			`i18n:translate: skip ${ locale } (add mapping in translate.mjs LOCALE_MAP)`
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

	const mode = resolveProviderMode();
	const delay = Number.parseInt( process.env.I18N_MT_DELAY_MS || '350', 10 );

	const content = fs.readFileSync( file, 'utf8' );
	const { header, entries } = parsePo( content );
	let updated = 0;
	let skipped = 0;
	let lastVia = mode;
	let truncated = false;
	/** @type {string[]} Sources whose translation came back unusable. */
	const refused = [];

	for ( const entry of entries ) {
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
					codes,
					mode,
					locale,
					entry.msgctxt
				);
				await sleep( delay );
				const plural = await mt(
					entry.msgidPlural,
					codes,
					mode,
					locale,
					entry.msgctxt
				);
				entry.msgstrs[ 'msgstr[0]' ] = singular.text;
				entry.msgstrs[ 'msgstr[1]' ] = plural.text;
				lastVia = plural.via;
			} else {
				const result = await mt(
					entry.msgid,
					codes,
					mode,
					locale,
					entry.msgctxt
				);
				entry.msgstrs.msgstr = result.text;
				lastVia = result.via;
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
			process.stdout.write( '.' );
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

	/*
	 * Last line before the file is written: no entry this run is responsible
	 * for may leave here claiming to be a finished translation while carrying
	 * the wrong placeholders. Anything that does is demoted to fuzzy, which is
	 * what it actually is — a draft — and which the lint and gettext both
	 * already treat as one.
	 *
	 * This is a backstop, not the fix. Two causes are fixed upstream: mt()
	 * refuses a translation whose placeholders do not match, and
	 * serializeEntry() no longer strips `fuzzy` from entries this run never
	 * touched. Both are covered by tests. The backstop stays because those two
	 * were found by reading a failing job rather than by anything asserting
	 * the invariant, and a pipeline that rewrites catalogs unattended should
	 * not depend on every future writer remembering it: whatever puts an entry
	 * in this file, the catalog the run produces passes the validation that
	 * runs next.
	 *
	 * Only the machine's own output is swept. A human translation that fails
	 * parity is left exactly as it is, to fail the lint loudly, because
	 * quietly flagging somebody's work fuzzy hides a problem they need to see.
	 */
	const demoted = [];

	for ( const entry of entries ) {
		if ( entry.flags.has( 'fuzzy' ) || ! entry.flags.has( 'aggr-mt' ) ) {
			continue;
		}

		if ( entryPlaceholdersIntact( entry ) ) {
			continue;
		}

		entry.flags.add( 'fuzzy' );
		demoted.push( entry.msgid );
		refused.push( entry.msgid );
	}

	if ( ( updated > 0 || demoted.length > 0 ) && ! opts.dryRun ) {
		fs.writeFileSync( file, serializePo( header, entries ), 'utf8' );
	}

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
	const remaining = entries.filter( ( entry ) =>
		needsTranslation( entry )
	).length;

	return {
		locale,
		updated,
		skipped,
		remaining,
		truncated,
		refused,
		provider: mode,
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

	const mode = resolveProviderMode();
	const hasDeeplKey = Boolean( process.env.DEEPL_AUTH_KEY );
	let primary;
	let backup;

	if ( mode === 'deepl' ) {
		primary = 'deepl';
		backup = 'none';
	} else if ( mode === 'auto' && hasDeeplKey ) {
		primary = 'deepl';
		backup = 'mymemory-on-deepl-failure';
	} else {
		primary = 'mymemory';
		backup = hasDeeplKey ? 'deepl-on-mymemory-failure' : 'none';
	}

	console.log(
		`i18n:translate: mode=${ mode } primary=${ primary } backup=${ backup } dryRun=${ opts.dryRun }`
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
	 * Named, not just counted. A refusal means a human has to translate that
	 * string, and a number alone tells nobody which one.
	 */
	const refusals = results.filter( ( r ) => r.refused.length > 0 );

	if ( refusals.length > 0 ) {
		console.log(
			'\ni18n:translate: left for a human — the machine translation ' +
				'came back with the wrong placeholders:'
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
		console.error( '\ni18n:translate: the run did not finish.' );

		for ( const problem of verdict.problems ) {
			console.error( `  ${ problem }` );
		}

		console.error(
			'\nRe-run once the provider quota allows it. Translation is ' +
				'incremental, so a re-run only fills what is still missing.'
		);

		process.exit( 1 );
	}
}

/*
 * Guarded so the module can be imported by a test, the way resume-progress.mjs
 * is. Without this, `mt()` could only be exercised by running the whole script
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
