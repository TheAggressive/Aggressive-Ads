/**
 * Talking to a translation engine: which one, how, and what to do when it says
 * no.
 *
 * Split out of translate.mjs when that file crossed the thousand-line hard
 * limit. The seam is real rather than convenient — everything here is about
 * getting one string translated, and everything left behind is about walking a
 * catalog and writing it back. The providers changed four times in two days
 * (placeholder parity, an echoed source, a tagged refusal, an exhausted quota)
 * while the catalog walk did not change once.
 *
 * @package Aggressive\Ads
 */

import { MT_REFUSED, classifyMtFailure } from './run-completeness.mjs';
import {
	PLACEHOLDER_PATTERN,
	extractPlaceholders,
	placeholdersIntact,
} from './po.mjs';

/** WordPress locale → MyMemory / DeepL language code. */
export const LOCALE_MAP = {
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
const LOCALE_GLOSSARY = {
	/*
	 * Earned on 2026-09-10, reviewing the first German draft that reached a
	 * pull request. Longest keys first, because the replacement is a plain
	 * substring pass: "Konvertierungen" has to go before "Konvertierung" or it
	 * becomes "Conversionen".
	 *
	 * Only substitutions that cannot change the grammar around them are here.
	 * "Ausweis" (an identity card) is wrong for an API credential, but it is
	 * masculine where "Anmeldedaten" is plural, so swapping the noun alone
	 * turns "Dieser Ausweis" into "Dieser Anmeldedaten" — worse German than the
	 * wrong word. That one needs a translator, not a rule.
	 */
	de_DE: [
		// The ad industry keeps the English loanword; "Konvertierung" is what
		// you call converting a file. MyMemory used both, in adjacent strings.
		[ 'Konvertierungen', 'Conversions' ],
		[ 'Konvertierung', 'Conversion' ],

		// "Namensnennung" is attribution in the give-credit sense — it is the
		// word in the German Creative Commons licences — not the advertising
		// sense of crediting a click with an outcome.
		[ 'Fenster Namensnennung', 'Attributionsfenster' ],

		// "Befundung" is a radiologist writing up a scan.
		[ 'Befundungsschlüssel', 'Berichtsschlüssel' ],
	],
};

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
	/*
	 * MyMemory echoes the query when it cannot translate, and this used to
	 * return that echo as the translation. The run then wrote English into the
	 * German catalog, flagged it `aggr-mt`, cleared its fuzzy mark and counted
	 * it among the strings it had filled — the catalog claiming a translation
	 * it had never been given. The 2026-09-10 draft carried eleven of them,
	 * including "None" and "Singapore dollar" presented as finished German.
	 *
	 * An echo is not evidence of a translation, so it is refused: the entry
	 * stays empty, gettext falls back to the source string exactly as the echo
	 * would have displayed, and the string is named for a human instead of
	 * hidden among the finished ones. Some strings really are identical in both
	 * languages — "Euro", "Code", "CTR" — and nothing here can tell those from
	 * a provider giving up, which is the point: a person can, and now gets
	 * asked.
	 */
	if ( translated === text ) {
		throw refusal(
			`MT echoed the source instead of translating it (source=${ text.slice(
				0,
				40
			) }…)`
		);
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
export function resolveProviderMode() {
	const raw = ( process.env.I18N_MT_PROVIDER || 'auto' ).toLowerCase();

	if ( raw === 'deepl' || raw === 'mymemory' || raw === 'local' ) {
		return raw;
	}

	return 'auto';
}

/**
 * Language names for the local model's prompt. A model reads "German" more
 * reliably than "de_DE", and a locale missing here falls back to its code.
 */
const LOCAL_LANGUAGE = {
	de_DE: 'German (Germany)',
	fr_FR: 'French (France)',
	fr_CA: 'French (Canada)',
	es_ES: 'Spanish (Spain)',
	es_MX: 'Spanish (Mexico)',
	it_IT: 'Italian',
	pt_BR: 'Portuguese (Brazil)',
	pt_PT: 'Portuguese (Portugal)',
	nl_NL: 'Dutch',
	pl_PL: 'Polish',
	sv_SE: 'Swedish',
	ja: 'Japanese',
	ko_KR: 'Korean',
	zh_CN: 'Chinese (Simplified)',
};

/**
 * How a locale addresses the reader. One of the defects the MyMemory drafts
 * shipped was a German string switching from "Sie" to "du" mid-catalog.
 */
const LOCAL_REGISTER = {
	de_DE: 'Address the reader formally with "Sie" and its forms. Never use "du".',
	fr_FR: 'Address the reader formally with "vous". Never use "tu".',
	fr_CA: 'Address the reader formally with "vous". Never use "tu".',
	es_ES: 'Address the reader formally with "usted". Never use "tú".',
	es_MX: 'Address the reader formally with "usted". Never use "tú".',
	it_IT: 'Address the reader formally with "Lei". Never use "tu".',
};

/**
 * Terms the model must render one way, by locale.
 *
 * **Proposed on 2026-09-11, for product review rather than as settled
 * German.** These are what an MT engine cannot be told and a model can: the
 * domain meaning of words English overloads. The MyMemory drafts rendered
 * "creative" as creative *people* (Kreative), "fill" as a dental filling
 * (Füllung) and screen widths as sieve widths (Siebbreiten). Each entry names
 * the sense in brackets, because the sense is the whole point.
 *
 * `LOCALE_GLOSSARY` still runs afterwards as a substring backstop; this is the
 * instruction, that is the correction.
 */
const LOCAL_TERMS = {
	de_DE: [
		[ 'creative (the ad artwork, a noun)', 'Werbemittel' ],
		[
			'conversion (a tracked outcome)',
			'Conversion (plural: Conversions)',
		],
		[ 'impression (one ad shown)', 'Impression (plural: Impressionen)' ],
		[
			'click-through rate',
			'Klickrate (keep the abbreviation CTR as CTR)',
		],
		[ 'placement (an ad slot on the site)', 'Platzierung' ],
		[ 'advertiser', 'Werbetreibender (plural: Werbetreibende)' ],
		[ 'campaign', 'Kampagne' ],
		[ 'line item', 'Werbebuchung' ],
		[ 'viewable, viewability', 'sichtbar, Sichtbarkeit' ],
		[ 'delivery, to serve an ad', 'Auslieferung, ausliefern' ],
		[ 'fill (a request answered with an ad)', 'Auslieferung' ],
		[ 'publisher (the site owner)', 'Publisher' ],
		[ 'credential (API access)', 'Zugangsdaten' ],
		[ 'attribution window', 'Attributionsfenster' ],
		[ 'screen width', 'Bildschirmbreite' ],
		[ 'forecast', 'Prognose' ],
	],
};

/**
 * The system prompt for one string.
 *
 * Context and the translator's note go here rather than in the user message,
 * so the user message is exactly the text to translate and nothing else the
 * model might translate or echo back.
 *
 * @param {string}      locale  WordPress locale.
 * @param {string|null} context The entry's msgctxt.
 * @param {string}      notes   The entry's translator comments.
 * @return {string}
 */
export function localSystemPrompt( locale, context = null, notes = '' ) {
	const language = LOCAL_LANGUAGE[ locale ] ?? locale;
	const lines = [
		"You translate the interface of Aggressive Ads, a WordPress plugin for selling and serving display advertising on a publisher's own website. Advertisers use a portal to build campaigns and upload ad creatives; the publisher's staff review them in wp-admin.",
		'',
		`Translate the user's text from English into ${ language }.`,
		'',
		'Rules:',
		'1. Reply with the translation only: no quotation marks around it, no notes, no alternatives.',
		`2. ${
			LOCAL_REGISTER[ locale ] ??
			'Use the register a software interface uses for this language.'
		}`,
		'3. Tokens such as __AGGR_PH_0__ and __AGGR_BR_0__ stand for values and names inserted at runtime. Copy each exactly once, where the sentence needs it. Never translate, drop, repeat or renumber them.',
		'4. Keep acronyms and names unchanged: CTR, CSV, JSON, WordPress, wp-admin, pnpm.',
		'5. Match the form of the source: a short label stays a short label, and a label without a full stop gets none.',
	];

	const terms = LOCAL_TERMS[ locale ] ?? [];

	if ( terms.length > 0 ) {
		lines.push( '6. Use these terms consistently:' );

		for ( const [ english, local ] of terms ) {
			lines.push( `   - ${ english } → ${ local }` );
		}
	}

	if ( context || notes ) {
		lines.push( '', 'About this string:' );

		if ( context ) {
			lines.push( `- It appears in this context: ${ context }` );
		}

		if ( notes ) {
			lines.push( `- Note from the developer: ${ notes }` );
		}
	}

	return lines.join( '\n' );
}

/**
 * Translates one string through an OpenAI-compatible chat completions API.
 *
 * Written against `/v1/chat/completions` rather than any one server, so LM
 * Studio, Ollama's compatible endpoint or llama.cpp's server all work. The
 * model decides how good the German is; this only decides what it is told and
 * what of its answer is kept.
 *
 * **Only `message.content` is read.** Reasoning models return their thinking
 * separately (`reasoning_content`, 120 tokens of it for "Save changes" on the
 * first probe) or inline as `<think>…</think>`, depending on the server.
 * Either reaching a catalog would be a paragraph of English deliberation
 * filed as a German button label.
 *
 * @param {string}      text    Source, placeholders and brands protected.
 * @param {string}      locale  WordPress locale.
 * @param {string|null} context The entry's msgctxt.
 * @param {string}      notes   The entry's translator comments.
 * @return {Promise<string>}
 */
async function translateLocal( text, locale, context = null, notes = '' ) {
	const base = ( process.env.I18N_LOCAL_URL || '' ).replace( /\/+$/, '' );
	const model = process.env.I18N_LOCAL_MODEL || '';

	if ( ! base || ! model ) {
		throw new Error(
			'The local provider needs I18N_LOCAL_URL and I18N_LOCAL_MODEL'
		);
	}

	const headers = { 'Content-Type': 'application/json' };

	if ( process.env.I18N_LOCAL_API_KEY ) {
		headers.Authorization = `Bearer ${ process.env.I18N_LOCAL_API_KEY }`;
	}

	const timeout = Number.parseInt(
		process.env.I18N_LOCAL_TIMEOUT_MS || '180000',
		10
	);

	const res = await fetch( `${ base }/chat/completions`, {
		method: 'POST',
		headers,
		body: JSON.stringify( {
			model,
			temperature: 0,
			messages: [
				{
					role: 'system',
					content: localSystemPrompt( locale, context, notes ),
				},
				{ role: 'user', content: text },
			],
		} ),
		signal: AbortSignal.timeout( timeout ),
	} );

	if ( ! res.ok ) {
		// "HTTP 4xx" is what classifyMtFailure() reads as the provider
		// refusing for good: a model name the server does not have is that.
		throw new Error( `Local model HTTP ${ res.status }` );
	}

	const data = await res.json();
	const content = data?.choices?.[ 0 ]?.message?.content;

	if ( 'string' !== typeof content ) {
		throw new Error( 'Local model returned no message content' );
	}

	let translated = content.replace( /<think>[\s\S]*?<\/think>/gi, '' ).trim();

	// Told not to, a model still sometimes quotes its whole answer. Removed
	// only when the source was not itself quoted.
	const quoted = /^(["“„«])([\s\S]*)(["”“»])$/;

	if ( quoted.test( translated ) && ! quoted.test( text ) ) {
		translated = translated.replace( quoted, '$2' ).trim();
	}

	if ( '' === translated ) {
		throw refusal(
			`Local model returned an empty translation (source=${ text.slice(
				0,
				40
			) }…)`
		);
	}

	// The same rule MyMemory's echoes get, for the same reason: an identical
	// answer is a claim a person should confirm, not one to file silently.
	if ( translated === text ) {
		throw refusal(
			`MT echoed the source instead of translating it (source=${ text.slice(
				0,
				40
			) }…)`
		);
	}

	return translated;
}

/**
 * Whether the configured local model is there to be asked, checked once
 * before a run touches any catalog.
 *
 * Without it, an unreachable server fails every string individually: each is
 * skipped as a one-off error, the run grinds through the whole catalog, and it
 * ends reporting a thousand strings untranslated for a reason it never states.
 *
 * @return {Promise<{ok: boolean, reason: string}>}
 */
export async function checkLocalProvider() {
	const base = ( process.env.I18N_LOCAL_URL || '' ).replace( /\/+$/, '' );
	const model = process.env.I18N_LOCAL_MODEL || '';

	if ( ! base || ! model ) {
		return {
			ok: false,
			reason: 'set I18N_LOCAL_URL and I18N_LOCAL_MODEL (see .env.example)',
		};
	}

	let res;

	try {
		res = await fetch( `${ base }/models`, {
			signal: AbortSignal.timeout( 10000 ),
		} );
	} catch ( err ) {
		return {
			ok: false,
			reason: `cannot reach ${ base } (${ err.message })`,
		};
	}

	if ( ! res.ok ) {
		return {
			ok: false,
			reason: `HTTP ${ res.status } from ${ base }/models`,
		};
	}

	const data = await res.json().catch( () => null );
	const ids = Array.isArray( data?.data )
		? data.data.map( ( m ) => String( m?.id ?? '' ) )
		: [];

	if ( ! ids.includes( model ) ) {
		return {
			ok: false,
			reason: `${ base } does not serve "${ model }" (it serves: ${
				ids.join( ', ' ) || 'nothing'
			})`,
		};
	}

	return { ok: true, reason: '' };
}

/**
 * Providers that have stopped answering for the rest of this run.
 *
 * A quota is exhausted for the day, not for the request. Without this, `auto`
 * mode asked DeepL about every single string after it had already answered
 * `HTTP 456: Quota exceeded` — 341 filled strings cost 341 doomed DeepL calls
 * plus 341 MyMemory ones, and the run took six minutes to do half of one
 * locale. Remembering the refusal halves the requests and the wall time.
 *
 * Only a `provider-stop` failure counts. A timeout or a dropped connection is
 * this request's problem and says nothing about the next one, so retiring a
 * working provider over one blip would be worse than the waste it saves.
 *
 * Deliberately not applied to `mode === 'deepl'`: somebody who names a provider
 * gets that provider or an error, never a quiet substitution.
 */
const exhausted = { deepl: false };

/**
 * Forgets which providers gave up, so one run's exhaustion is not another's.
 *
 * @return {void}
 */
export function resetProviderHealth() {
	exhausted.deepl = false;
}

/**
 * Records a provider's refusal when it is the kind that will repeat.
 *
 * @param {string}  name Provider key.
 * @param {unknown} err  What it threw.
 * @return {void}
 */
function noteProviderFailure( name, err ) {
	if ( 'provider-stop' !== classifyMtFailure( err ) || exhausted[ name ] ) {
		return;
	}

	exhausted[ name ] = true;

	console.warn(
		`\ni18n:translate: ${ name } is out of quota; using the fallback for the rest of this run`
	);
}

/**
 * @param {string} text
 * @param {{ mymemory: string, deepl: string }} localeCodes
 * @param {'auto' | 'mymemory' | 'deepl' | 'local'} mode
 * @param {string} locale
 * @param {string|null} context The entry's msgctxt.
 * @param {string} notes The entry's translator comments; only the local model reads them.
 * @returns {Promise<{ text: string, via: string }>}
 */
export async function mt(
	text,
	localeCodes,
	mode,
	locale,
	context = null,
	notes = ''
) {
	const { protectedText, tokens: ph } = protectPlaceholders( text );
	const brand = protectBrandTerms( protectedText );
	let out;
	let via;

	const hasDeeplKey = Boolean( process.env.DEEPL_AUTH_KEY );
	const deeplUsable = hasDeeplKey && ! exhausted.deepl;

	/*
	 * No fallback, deliberately. Somebody who names a provider gets that
	 * provider or an error — and the local model is being measured against the
	 * others, so a quiet substitution would contaminate the measurement.
	 */
	if ( mode === 'local' ) {
		out = await translateLocal( brand.text, locale, context, notes );
		via = 'local';
	} else if ( mode === 'deepl' ) {
		out = await translateDeepL( brand.text, localeCodes.deepl, context );
		via = 'deepl';
	} else if ( mode === 'auto' && deeplUsable ) {
		try {
			out = await translateDeepL(
				brand.text,
				localeCodes.deepl,
				context
			);
			via = 'deepl';
		} catch ( err ) {
			noteProviderFailure( 'deepl', err );
			console.warn(
				`\ni18n:translate: DeepL failed (${ err.message }); falling back to MyMemory`
			);
			out = await translateMyMemory( brand.text, localeCodes.mymemory );
			via = 'mymemory-fallback';
		}
	} else {
		try {
			out = await translateMyMemory( brand.text, localeCodes.mymemory );

			/*
			 * A *remembered* refusal is still a fallback. Retiring DeepL for
			 * the run sends every later string down this branch, which is also
			 * the branch a site with no DeepL key at all takes — so tagging
			 * both `mymemory` erased the one signal saying the preferred engine
			 * had not done the work.
			 *
			 * That is not hypothetical: the first run after the retirement
			 * landed 458 strings tagged plain `mymemory`, and the pull request
			 * that opened said "Translated by mymemory" with no warning, over a
			 * draft that had degraded on its very first request. Two changes,
			 * each tested alone and never together.
			 */
			via =
				'auto' === mode && hasDeeplKey && exhausted.deepl
					? 'mymemory-fallback'
					: 'mymemory';
		} catch ( err ) {
			// Nothing left to fall back to: no key, or DeepL already gave up.
			if ( ! deeplUsable ) {
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
