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

import {
	MT_PROVIDER_STOP,
	MT_REFUSED,
	MT_RETRYABLE,
	classifyMtFailure,
} from './run-completeness.mjs';
import {
	PLACEHOLDER_PATTERN,
	extractPlaceholders,
	placeholdersIntact,
} from './po.mjs';

const DO_NOT_TRANSLATE = [
	// Chosen from what the catalog actually contains, not from a guess at what
	// an advertising plugin might say. Each of these is a proper noun or an
	// industry acronym that has one spelling everywhere, and MT will happily
	// localise all of them: a translator will localise all of them, including the
	// screen WordPress itself already translates.
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
		// you call converting a file. the first drafts used both, in adjacent strings.
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
 * ASCII-only tokens, so nothing in the round trip mangles them.
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
 * Some engines emit HTML entities (e.g. &#10; for newline).
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
 * Post-translation corrections for one locale.
 *
 * The backstop behind the prompt's terminology: a plain substring pass over the
 * answer, longest keys first, recording mistakes the model actually made on
 * this catalog rather than ones it might make.
 *
 * @param {string} text   The model's answer.
 * @param {string} locale WordPress locale.
 * @return {string}
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
 * How a locale addresses the reader. One of the defects the first machine drafts
 * shipped was a German string switching from "Sie" to "du" mid-catalog.
 */
/**
 * Whether this pipeline will translate a locale at all.
 *
 * A locale with no language name is skipped rather than guessed at: "de_DE" is
 * not a target language to hand a model.
 *
 * @param {string} locale WordPress locale.
 * @return {boolean}
 */
export function isSupportedLocale( locale ) {
	return Object.prototype.hasOwnProperty.call( LOCAL_LANGUAGE, locale );
}

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
 * domain meaning of words English overloads. The first machine drafts rendered
 * "creative" as creative *people* (Kreative), "fill" as a dental filling
 * (Füllung) and screen widths as sieve widths (Siebbreiten). Each entry names
 * the sense in brackets, because the sense is the whole point.
 *
 * `LOCALE_GLOSSARY` still runs afterwards as a substring backstop; this is the
 * instruction, that is the correction.
 *
 * **Revised after product review the same day.** *Werbebuchung*, *Platzierung*,
 * *Zugangsdaten* and *Auslieferung* for delivery were confirmed — the first two
 * are the terms Google Ad Manager's German interface uses. Two changes:
 * *delivery* and *fill* were one blanket rule and are now separate concepts,
 * because what is delivered is the ad and an ad request is served or
 * processed, never itself delivered; and "worth reporting" gained a rule,
 * because the first gate rendered it *meldepflichtig*, the legal term for a
 * mandatory report. Neither was tuned to a sample the model will be graded on:
 * the next gate is drawn fresh.
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
		[
			'delivery; to deliver or serve an ad, creative, impression or line item',
			'Auslieferung; ausliefern',
		],
		[
			'an ad request, and a request being filled',
			'Anfrage. A request is bedient (served) or verarbeitet (processed); never say the request itself was ausgeliefert. What is ausgeliefert is the ad.',
		],
		[
			'worth reporting (advisable, not an obligation)',
			'sollte gemeldet werden. Never meldepflichtig, which means legally required to report.',
		],
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
 * Statuses that waiting cannot change: authentication, authorization, and a
 * model or route the server does not have.
 */
const PERMANENT_STATUSES = new Set( [ 401, 403, 404 ] );

/**
 * Backoff between attempts at one string, in milliseconds.
 *
 * About two minutes in all, which outlasts a model being reloaded. Overridable
 * as a comma list in I18N_LOCAL_RETRY_DELAYS_MS, so tests do not wait.
 *
 * @return {number[]}
 */
function localRetryDelays() {
	const raw = process.env.I18N_LOCAL_RETRY_DELAYS_MS;

	if ( undefined === raw || '' === raw.trim() ) {
		return [ 2000, 5000, 10000, 20000, 30000, 60000 ];
	}

	return raw
		.split( ',' )
		.map( ( value ) => Number.parseInt( value.trim(), 10 ) )
		.filter( ( value ) => Number.isFinite( value ) && value >= 0 );
}

/**
 * @param {number} ms Milliseconds.
 * @return {Promise<void>}
 */
function pause( ms ) {
	return new Promise( ( resolve ) => setTimeout( resolve, ms ) );
}

/**
 * An error carrying the code the run decides on.
 *
 * @param {string} code    MT_RETRYABLE or MT_PROVIDER_STOP.
 * @param {string} message What happened, including the server's own reason.
 * @return {Error}
 */
function failure( code, message ) {
	const err = new Error( message );
	err.code = code;

	return err;
}

/**
 * The server's reason for refusing, bounded, or nothing.
 *
 * @param {Response} res A response that was not ok.
 * @return {Promise<string>}
 */
async function reasonFrom( res ) {
	try {
		const body = 'function' === typeof res.text ? await res.text() : '';

		return String( body ).replace( /\s+/g, ' ' ).trim().slice( 0, 300 );
	} catch {
		return '';
	}
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

	const body = JSON.stringify( {
		model,
		temperature: 0,
		messages: [
			{
				role: 'system',
				content: localSystemPrompt( locale, context, notes ),
			},
			{ role: 'user', content: text },
		],
	} );

	/*
	 * A local server is somebody's machine, and it gets restarted. The first
	 * full German run stopped after 567 of 1,386 strings because the model was
	 * reloaded mid-run: LM Studio answered 400 until it was back, and one 400
	 * was read as the server refusing for good. A failed request is now retried
	 * with backoff for about two minutes, which outlasts a reload; only a
	 * status that waiting cannot change stops at once.
	 */
	const delays = localRetryDelays();
	let data = null;
	let last = '';

	for ( let attempt = 0; attempt <= delays.length; attempt++ ) {
		if ( attempt > 0 ) {
			await pause( delays[ attempt - 1 ] );
		}

		let res;

		try {
			res = await fetch( `${ base }/chat/completions`, {
				method: 'POST',
				headers,
				body,
				signal: AbortSignal.timeout( timeout ),
			} );
		} catch ( err ) {
			last = `unreachable (${ err.message })`;
			continue;
		}

		if ( res.ok ) {
			data = await res.json();
			break;
		}

		// Kept rather than discarded: the first 400 could not be diagnosed
		// because its body was never read.
		const reason = await reasonFrom( res );

		last = `HTTP ${ res.status }${ reason ? `: ${ reason }` : '' }`;

		if ( PERMANENT_STATUSES.has( res.status ) ) {
			throw failure( MT_PROVIDER_STOP, `Local model ${ last }` );
		}
	}

	if ( null === data ) {
		/*
		 * Out of retries. Whose fault that is, the server can say: if it still
		 * serves the model, the request was the problem and only this string
		 * is skipped; if not, the run stops so a re-run can resume, instead of
		 * spending two minutes on every string left.
		 */
		const health = await checkLocalProvider();

		if ( ! health.ok ) {
			throw failure(
				MT_PROVIDER_STOP,
				`Local model unavailable after retries (${ last }); ${ health.reason }`
			);
		}

		throw failure(
			MT_RETRYABLE,
			`Local model rejected this string after retries (${ last })`
		);
	}
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

	// An identical
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
 * Whether to colour a stream, honouring NO_COLOR and FORCE_COLOR.
 *
 * @param {{isTTY?: boolean}} stream
 * @return {boolean}
 */
function useColour( stream ) {
	if ( process.env.NO_COLOR ) {
		return false;
	}

	if ( process.env.FORCE_COLOR ) {
		return true;
	}

	return Boolean( stream && stream.isTTY );
}

/**
 * What a person sees when the local model is not answering.
 *
 * Red and unmissable, and it names the two things worth doing: start the
 * model, or correct its address. A run that translates nothing must not look
 * like a run that had nothing to do.
 *
 * @param {string} reason Why the preflight refused, from checkLocalProvider().
 * @return {string}
 */
export function localProviderDownMessage( reason ) {
	const text =
		'\ni18n:translate: TRANSLATIONS DID NOT RUN — the local model is not answering.\n' +
		`  ${ reason }\n` +
		'  Start it (LM Studio → Developer → Start Server), or set I18N_LOCAL_URL\n' +
		'  in .env.local if its address changed. Nothing was written.';

	return useColour( process.stderr ) ? `\u001b[31m${ text }\u001b[39m` : text;
}

/**
 * What a person sees when the model stopped answering partway through.
 *
 * The catalogs keep what was translated; only the claim that the run finished
 * is withdrawn. Distinct from the message above because the fix is the same
 * but the state is not: there is work on disk to keep.
 *
 * @return {string}
 */
export function localRunIncompleteMessage() {
	const text =
		'\ni18n:translate: TRANSLATIONS DID NOT FINISH — the local model stopped\n' +
		'  answering partway through. What was translated is written; re-run once\n' +
		'  it is back and only the missing strings are asked for.';

	return useColour( process.stderr ) ? `\u001b[31m${ text }\u001b[39m` : text;
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
 * Translates one string, protecting what must survive the round trip.
 *
 * @param {string}      text    Source string.
 * @param {string}      locale  WordPress locale.
 * @param {string|null} context The entry's msgctxt.
 * @param {string}      notes   The entry's translator comments.
 * @returns {Promise<{ text: string, via: string }>}
 */
export async function mt( text, locale, context = null, notes = '' ) {
	const { protectedText, tokens: ph } = protectPlaceholders( text );
	const brand = protectBrandTerms( protectedText );

	let out = await translateLocal( brand.text, locale, context, notes );

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

	// A model answering with markup instead of text (Progress → Progrès&#10;).
	if ( /&#\w+;|&[a-z]+;/i.test( out ) && ! /&#\w+;|&[a-z]+;/i.test( text ) ) {
		throw refusal(
			`MT injected HTML entities (source=${ text.slice( 0, 40 ) }…)`
		);
	}

	return { text: out, via: 'local' };
}
