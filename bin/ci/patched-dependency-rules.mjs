/**
 * Whether a patched transitive dependency is still patched.
 *
 * Separated from `check-patched-dependencies.mjs` and kept pure for the same
 * reason `summary-rules.mjs` is separate from `check-summary.mjs`: the decision
 * is the part worth testing, and it cannot be tested through a script that
 * resolves real paths inside a real `node_modules`.
 *
 * **The failure this exists for.** The guard looped over resolved copies of
 * `adm-zip` and asserted the patch was present in each. If that set came back
 * empty, the loop body never ran — and the only remaining assertion was a
 * round-trip smoke test that an *unpatched* adm-zip passes just as happily. The
 * guard then printed "CVE-2026-39244 fix present" having verified nothing.
 *
 * That is not hypothetical. The file already records the parent list going
 * stale once, when `@wordpress/env` was removed; that time it threw, which is
 * the lucky outcome. An empty list is the same mistake failing quietly.
 */

/**
 * The vulnerable allocation, and the patched one.
 *
 * CVE-2026-39244: adm-zip sized its output buffer from the central-directory
 * header, which the archive supplies. A crafted archive declaring a huge size
 * makes the process allocate it. The patch sizes from the actual compressed
 * data instead.
 */
export const VULNERABLE_MARKER = 'Buffer.alloc(_centralHeader.size)';
export const PATCHED_MARKER = 'Buffer.alloc(compressedData.length)';

/**
 * Judges one resolved copy of adm-zip.
 *
 * Both markers are checked rather than one. Absence of the vulnerable line is
 * not evidence of the patch — an upstream refactor that renamed the variable
 * would remove the marker and satisfy a one-sided check while saying nothing
 * about the allocation.
 *
 * @param {string} source Contents of `adm-zip/zipEntry.js`.
 * @return {string|null} Problem description, or null when patched.
 */
export function judgeSource( source ) {
	if ( 'string' !== typeof source || '' === source ) {
		return 'adm-zip source was empty, so nothing was verified';
	}

	if ( source.includes( VULNERABLE_MARKER ) ) {
		return 'adm-zip still allocates attacker-declared output size';
	}

	if ( ! source.includes( PATCHED_MARKER ) ) {
		return 'adm-zip CVE-2026-39244 patch is missing';
	}

	return null;
}

/**
 * Judges the whole run.
 *
 * The empty case is the point. "No problems found" across zero copies examined
 * is the failure mode every guard in this repository is written to avoid, and
 * it is the one this guard actually had.
 *
 * @param {Array<{path: string, source: string}>} copies Resolved adm-zip copies.
 * @return {{ok: boolean, problems: string[], examined: number}}
 */
export function judgeRun( copies ) {
	if ( ! Array.isArray( copies ) || 0 === copies.length ) {
		return {
			ok: false,
			examined: 0,
			problems: [
				'no copy of adm-zip was resolved, so the patch was not verified',
			],
		};
	}

	const problems = [];

	for ( const copy of copies ) {
		const problem = judgeSource( copy?.source );

		if ( null !== problem ) {
			problems.push( `${ copy?.path ?? 'unknown path' }: ${ problem }` );
		}
	}

	return { ok: 0 === problems.length, problems, examined: copies.length };
}
