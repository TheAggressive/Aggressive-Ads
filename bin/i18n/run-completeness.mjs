/**
 * Did the machine-translation run actually finish?
 *
 * Separated from `translate.mjs` and kept pure for the same reason
 * `summary-rules.mjs` is separate from `check-summary.mjs`: the decision is the
 * part worth testing, and it cannot be tested through a script that talks to a
 * paid API and writes files.
 *
 * **The failure this exists for.** `translatePoFile()` deliberately stops a
 * locale on a quota or rate-limit error rather than throwing, so whatever was
 * translated is still written and not lost. That part is right. What was wrong
 * is that the run then reported success: on 2026-08-24 a run exhausted the DeepL
 * quota partway through the first locale, translated 390 of 1014 entries in
 * German and none at all in Spanish, French or Italian, exited zero, and opened
 * a pull request that looked exactly like a complete one.
 *
 * Three empty locales presented as reviewable is worse than no catalogues,
 * because a reviewer has no way to tell the difference without reading the job
 * log. So a truncated run now fails, loudly, naming each locale and what it left
 * behind. The files are still written first — the work is kept, the claim is
 * not.
 */

/**
 * @typedef {object} LocaleResult
 * @property {string}  locale    Locale code, e.g. `de_DE`.
 * @property {number}  updated   Entries filled this run.
 * @property {number}  skipped   Entries already translated.
 * @property {number}  remaining Entries still needing translation afterwards.
 * @property {boolean} truncated Whether the provider cut the locale short.
 * @property {string[]|number} [refused] Entries the run declined to write —
 *                               the source strings, or just how many — because
 *                               the
 *                               machine translation was refused — an echo,
 *                               an empty answer or the wrongplaceholders. They stay untranslated on
 *                               purpose, so they are not evidence of an
 *                               unfinished run.
 */

/**
 * The code carried by an error raised because one string's translation was
 * unusable, rather than because the provider stopped answering.
 */
export const MT_REFUSED = 'aggr-mt-refused';

/**
 * The code on a failure that belongs to one string and not to the provider:
 * the server is up and answering, and rejected this request. Skip the string,
 * carry on with the next.
 */
export const MT_RETRYABLE = 'aggr-mt-retryable';

/**
 * The code on a failure that means the provider has stopped answering: stop
 * the locale, keep what is written, let a re-run resume.
 *
 * Codes rather than message sniffing, because the local provider's messages
 * carry a status — and the message regex below reads any "HTTP 4xx" as the
 * provider refusing for good. That is right for DeepL's 456 and wrong for a
 * local model that answered 400 while it was being reloaded, which is exactly
 * how the first full German run stopped after 567 of 1,386 strings.
 */
export const MT_PROVIDER_STOP = 'aggr-mt-provider-stop';

/**
 * What a single string's failure means for the rest of the locale.
 *
 * - `refused`      — the translation came back wrong (placeholders invented or
 *                    dropped, HTML entities injected). One bad string, nothing
 *                    to conclude about the next one: skip it and carry on.
 * - `provider-stop`— quota, rate limit or a 4xx. The provider is done talking,
 *                    so keep what is written and stop asking.
 * - `retryable`    — anything else; treated like `provider-stop` today, but
 *                    named separately so the two can diverge without a
 *                    re-reading of the regex.
 *
 * **The tag is checked before the message, and that ordering is the fix.** The
 * message of a refusal embeds the source string so a human can see which one
 * broke — and `"Limit to one advertiser"` is a real string in this plugin, so
 * sniffing `/LIMIT/i` across the whole message read a refused string as an
 * exhausted quota and abandoned the rest of the locale. The run then reported
 * "the provider stopped the run", which was not true and pointed the next
 * person at the wrong system. Provider errors carry no source text (see the
 * throws in translate.mjs), so the regex is safe once refusals are taken out
 * ahead of it.
 *
 * @param {unknown} err Error raised while translating one string.
 * @return {'refused'|'provider-stop'|'retryable'} What it means for the run.
 */
export function classifyMtFailure( err ) {
	if ( err && typeof err === 'object' ) {
		if ( MT_REFUSED === err.code ) {
			return 'refused';
		}

		if ( MT_PROVIDER_STOP === err.code ) {
			return 'provider-stop';
		}

		if ( MT_RETRYABLE === err.code ) {
			return 'retryable';
		}
	}

	const message = String(
		err && typeof err === 'object' && err.message ? err.message : err
	);

	if ( /HTTP 4\d\d|LIMIT|quota|MYMEMORY WARNING/i.test( message ) ) {
		return 'provider-stop';
	}

	return 'retryable';
}

/**
 * Escapes text for a GitHub Actions workflow command.
 *
 * `%` first, or the escapes introduced by the later replacements are escaped
 * again. This matters more here than it looks: a refused string is refused
 * *because of its placeholders*, so nearly every message this encodes contains
 * a literal `%s` or `%d` for the runner to misread.
 *
 * @param {string} text
 * @return {string}
 */
function encodeAnnotation( text ) {
	return text
		.replaceAll( '%', '%25' )
		.replaceAll( '\r', '%0D' )
		.replaceAll( '\n', '%0A' );
}

/**
 * Workflow annotations naming the strings a run declined to translate.
 *
 * Refusals do not fail the run, which leaves a gap this closes: a string the
 * provider mangles every time stays untranslated behind a green lane, visible
 * only in the log of whichever run last tried it. The draft pull request's body
 * is written once and never mentions them.
 *
 * A warning is the right volume. The catalogs are correct and the page falls
 * back to English, so this is not a failure — but "the run reported success
 * afterwards" is exactly the defect judgeRun() exists for, and silence about a
 * string no machine will ever get right is the same mistake in a smaller place.
 *
 * @param {LocaleResult[]} results Per-locale outcomes.
 * @return {string[]} `::warning::` lines, one per locale that refused anything.
 */
export function refusalAnnotations( results ) {
	const lines = [];

	for ( const result of results ) {
		const refused = Array.isArray( result.refused ) ? result.refused : [];

		if ( 0 === refused.length ) {
			continue;
		}

		const title = `${ refused.length } string(s) in ${ result.locale } need a human translator`;
		const body =
			'The machine translation was refused — an echoed source, an empty ' +
			`answer or the wrong placeholders — so it was not written:\n${ refused
				.map( ( msgid ) => `  "${ msgid }"` )
				.join( '\n' ) }`;

		lines.push(
			`::warning title=${ encodeAnnotation(
				title
			) }::${ encodeAnnotation( body ) }`
		);
	}

	return lines;
}

/**
 * What a reviewer needs to know before reading a word of the translation.
 *
 * `auto` mode prefers DeepL and silently substitutes MyMemory whenever DeepL
 * refuses. Two runs went out that way — 718 strings, every one from the
 * fallback — and the only trace was a `via` tag inside a PO comment. The pull
 * request said "Machine translation filled empty and fuzzy entries" and named
 * no engine; the job was red for an unrelated reason. The draft was reviewed on
 * its German, found roughly a third defective, and closed.
 *
 * So the mix goes at the top of the pull request, not into a log. `fallback` in
 * a `via` name is the signal: it means the engine that answered was not the one
 * the run was configured to prefer.
 *
 * @param {LocaleResult[]} results Per-locale outcomes.
 * @return {{degraded: boolean, total: number, counts: Record<string, number>, summary: string}}
 */
export function providerMix( results ) {
	const counts = {};

	for ( const result of results ) {
		for ( const [ via, n ] of Object.entries( result.providers ?? {} ) ) {
			counts[ via ] = ( counts[ via ] ?? 0 ) + n;
		}
	}

	const total = Object.values( counts ).reduce( ( a, b ) => a + b, 0 );
	const degraded = Object.keys( counts ).some( ( via ) =>
		via.includes( 'fallback' )
	);

	const ordered = Object.entries( counts ).sort(
		( a, b ) => b[ 1 ] - a[ 1 ]
	);
	const lines = ordered.map(
		( [ via, n ] ) =>
			`- \`${ via }\` — ${ n } string${ 1 === n ? '' : 's' }`
	);

	if ( 0 === total ) {
		return {
			degraded: false,
			total: 0,
			counts,
			summary: 'No strings were translated in this run.',
		};
	}

	const heading = degraded
		? '> [!WARNING]\n' +
		  '> **This draft was produced by a fallback engine.** The preferred\n' +
		  '> provider refused, so these strings came from the substitute. A\n' +
		  '> previous draft in that state was about a third defective and was\n' +
		  '> closed rather than merged — read the translations before trusting\n' +
		  '> this one.\n'
		: '';

	return {
		degraded,
		total,
		counts,
		summary: `${ heading }\n**Translated by**\n\n${ lines.join( '\n' ) }\n`,
	};
}

/**
 * Judges a completed run.
 *
 * A locale is a problem when the provider gave up partway, or when entries are
 * still untranslated after a pass that was not limited on purpose. `--limit` is
 * a debugging tool and stopping early is exactly what it is for, so a limited
 * run is never judged incomplete.
 *
 * @param {LocaleResult[]} results Per-locale outcomes, in run order.
 * @param {object}         [opts]  Run options.
 * @param {boolean}        [opts.limited] Whether `--limit` capped the run.
 * @return {{ok: boolean, problems: string[]}} Verdict and every reason.
 */
export function judgeRun( results, opts = {} ) {
	const problems = [];

	if ( ! Array.isArray( results ) || 0 === results.length ) {
		return {
			ok: false,
			problems: [
				'no locale was processed, so nothing can be said about the catalogs',
			],
		};
	}

	if ( opts.limited ) {
		return { ok: true, problems: [] };
	}

	for ( const result of results ) {
		const remaining = Number( result.remaining ?? 0 );
		const refused = Array.isArray( result.refused )
			? result.refused.length
			: Number( result.refused ?? 0 );

		if ( result.truncated ) {
			problems.push(
				`${ result.locale }: the provider stopped the run with ` +
					`${ remaining } entr${ 1 === remaining ? 'y' : 'ies' } ` +
					`still untranslated (filled ${ result.updated })`
			);

			continue;
		}

		/*
		 * A refused string is untranslated on purpose: the machine gave an
		 * answer that would have broken the page, and declining it is the
		 * correct outcome rather than an unfinished pass. Counting it as
		 * incomplete is what made this lane permanently red — the next run
		 * asks the same provider the same question and is refused again, so
		 * the failure never clears and no draft is ever published. They are
		 * named on stdout by translate.mjs, which has the strings.
		 */
		const unexplained = Math.max( 0, remaining - refused );

		if ( unexplained > 0 ) {
			problems.push(
				`${ result.locale }: ${ unexplained } entr` +
					`${ 1 === unexplained ? 'y' : 'ies' } still untranslated ` +
					`after a complete pass`
			);
		}
	}

	return { ok: 0 === problems.length, problems };
}
