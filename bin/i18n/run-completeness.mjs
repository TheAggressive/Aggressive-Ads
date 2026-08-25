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
 */

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

		if ( result.truncated ) {
			problems.push(
				`${ result.locale }: the provider stopped the run with ` +
					`${ remaining } entr${ 1 === remaining ? 'y' : 'ies' } ` +
					`still untranslated (filled ${ result.updated })`
			);

			continue;
		}

		if ( remaining > 0 ) {
			problems.push(
				`${ result.locale }: ${ remaining } entr` +
					`${ 1 === remaining ? 'y' : 'ies' } still untranslated ` +
					`after a complete pass`
			);
		}
	}

	return { ok: 0 === problems.length, problems };
}
