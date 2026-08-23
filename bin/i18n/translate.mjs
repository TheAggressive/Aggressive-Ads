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
import { fileURLToPath } from 'node:url';

import { PLACEHOLDER_PATTERN, parsePo, placeholdersIntact } from './po.mjs';

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
	const flags = new Set( entry.flags );
	flags.delete( 'fuzzy' );
	if ( ! flags.has( 'aggr-mt' ) ) {
		flags.add( 'aggr-mt' );
	}
	if ( flags.size ) {
		lines.push( `#, ${ [ ...flags ].join( ', ' ) }` );
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

async function translateDeepL( text, lang ) {
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
async function mt( text, localeCodes, mode, locale ) {
	const { protectedText, tokens: ph } = protectPlaceholders( text );
	const brand = protectBrandTerms( protectedText );
	let out;
	let via;

	const hasDeeplKey = Boolean( process.env.DEEPL_AUTH_KEY );

	if ( mode === 'deepl' ) {
		out = await translateDeepL( brand.text, localeCodes.deepl );
		via = 'deepl';
	} else if ( mode === 'auto' && hasDeeplKey ) {
		try {
			out = await translateDeepL( brand.text, localeCodes.deepl );
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
			out = await translateDeepL( brand.text, localeCodes.deepl );
			via = 'deepl-fallback';
		}
	}

	out = restoreBrandTerms( out, brand.tokens );
	out = restorePlaceholders( out, ph );
	out = sanitizeMtOutput( out );
	out = applyLocaleGlossary( out, locale );

	if ( ! placeholdersIntact( text, out ) ) {
		throw new Error(
			`MT dropped placeholders (source=${ text.slice( 0, 40 ) }…)`
		);
	}

	// Reject leftover HTML entities MyMemory invents (e.g. Progress → Progrès&#10;).
	if ( /&#\w+;|&[a-z]+;/i.test( out ) && ! /&#\w+;|&[a-z]+;/i.test( text ) ) {
		throw new Error(
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

async function translatePoFile( file, opts ) {
	const locale = localeFromPo( file );
	const codes = LOCALE_MAP[ locale ];
	if ( ! codes ) {
		console.warn(
			`i18n:translate: skip ${ locale } (add mapping in translate.mjs LOCALE_MAP)`
		);
		return { locale, updated: 0, skipped: 0 };
	}

	const mode = resolveProviderMode();
	const delay = Number.parseInt( process.env.I18N_MT_DELAY_MS || '350', 10 );

	const content = fs.readFileSync( file, 'utf8' );
	const { header, entries } = parsePo( content );
	let updated = 0;
	let skipped = 0;
	let lastVia = mode;

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
				const singular = await mt( entry.msgid, codes, mode, locale );
				await sleep( delay );
				const plural = await mt(
					entry.msgidPlural,
					codes,
					mode,
					locale
				);
				entry.msgstrs[ 'msgstr[0]' ] = singular.text;
				entry.msgstrs[ 'msgstr[1]' ] = plural.text;
				lastVia = plural.via;
			} else {
				const result = await mt( entry.msgid, codes, mode, locale );
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
			// Stop this file on quota / hard errors so a partial PR can still open.
			if (
				/HTTP 4\d\d|LIMIT|quota|MYMEMORY WARNING/i.test( String( err ) )
			) {
				break;
			}
		}

		await sleep( delay );
	}

	process.stdout.write( '\n' );

	if ( updated > 0 && ! opts.dryRun ) {
		fs.writeFileSync( file, serializePo( header, entries ), 'utf8' );
	}

	return { locale, updated, skipped, provider: mode };
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
	for ( const file of files ) {
		const result = await translatePoFile( file, opts );
		console.log(
			`i18n:translate: ${ result.locale }: updated=${ result.updated } already-ok=${ result.skipped }`
		);
		total += result.updated;
	}

	console.log( `i18n:translate: Done. ${ total } string(s) filled.` );
	if ( opts.dryRun && total > 0 ) {
		console.log( 'i18n:translate: dry-run — no files written.' );
	}
}

main().catch( ( err ) => {
	console.error( err );
	process.exit( 1 );
} );
