/**
 * What a pull request is, and whether automation may merge it.
 *
 * Pure, and separate from the workflow that calls it, for the same reason
 * `summary-rules.mjs` is separate from `check-summary.mjs`: this is the highest
 * consequence logic in the repository after that file. A mistake here either
 * blocks every pull request or — far worse — merges one that needed a person.
 *
 * The design rule throughout is **fail closed**. Every function answers "may
 * automation act?" and every uncertain answer is "no". A label, a branch name,
 * a title and an event payload are all attacker-influenced or simply wrong
 * sometimes; none of them is permitted to be the reason a merge happens. They
 * can only ever *withhold* permission.
 *
 * The caller is responsible for the facts. This file judges them.
 *
 * **It replaces `.github/workflows/dependabot-auto-merge.yml`,** which did the
 * same job for Dependabot alone in ninety lines of untested shell. Every
 * property that workflow had is kept and asserted here: the author is verified
 * against the API rather than the payload, majors are refused, a `BEHIND`
 * branch is updated and then left for fresh checks, `DIRTY` is refused, an
 * empty check list is refused rather than read as "nothing failed", and the
 * merge is registered with GitHub rather than performed. Running both would
 * have meant two workflows racing to register auto-merge on the same pull
 * request, with the shell copy the one nothing tested.
 */

/**
 * Conventional Commit types this repository uses, mapped to a `type:` label.
 *
 * Squash titles feed semantic-release, so an unrecognised type is not a style
 * problem — it is a release that silently does not happen, or happens at the
 * wrong version.
 */
const TYPE_LABELS = Object.freeze( {
	feat: 'type:feature',
	fix: 'type:fix',
	perf: 'type:refactor',
	refactor: 'type:refactor',
	docs: 'type:docs',
	test: 'type:chore',
	build: 'type:chore',
	ci: 'type:chore',
	chore: 'type:chore',
	style: 'type:chore',
	revert: 'type:fix',
} );

export const KNOWN_TYPES = Object.freeze( Object.keys( TYPE_LABELS ) );

/**
 * Paths where a mistake is not caught by review of the diff alone.
 *
 * Everything here can change what the *other* gates do. A workflow edit can
 * remove a required job; a ruleset edit can remove a protection; a release
 * script edit can change what ships; a `bin/ci/` edit can turn a guard off —
 * and this repository has now found three guards that were silently reading
 * nothing, so that last one is not theoretical.
 *
 * Security-sensitive product code is here for a different reason: the diff can
 * be small and correct-looking and still widen who may read another
 * organization's unpublished creative.
 */
const HIGH_RISK_PATTERNS = Object.freeze( [
	// Anything that can change what CI, protection or publishing does.
	{ re: /^\.github\/workflows\//, why: 'workflow definition' },
	{ re: /^\.github\/rulesets\//, why: 'branch protection ruleset' },
	{ re: /^\.github\/CODEOWNERS$/, why: 'code owners' },
	{ re: /^\.github\/dependabot\.yml$/, why: 'dependency update policy' },
	{ re: /^\.github\/actions\//, why: 'composite action' },
	{ re: /^\.releaserc\.json$/, why: 'release configuration' },
	{ re: /^bin\/release\//, why: 'release machinery' },
	{ re: /^bin\/ci\//, why: 'CI contract or enforcement script' },
	{ re: /^bin\/check-/, why: 'enforcement script' },
	{
		re: /^(phpcs\.xml\.dist|phpstan\.neon(\.dist)?|\.?eslintrc.*|eslint\.config\..*|\.stylelintrc.*)$/,
		why: 'static analysis configuration',
	},
	{
		re: /^(phpunit.*\.xml(\.dist)?|playwright\.config\..*)$/,
		why: 'test configuration',
	},

	// Anything that decides who may do what, or what is destroyed.
	{ re: /^inc\/Security\//, why: 'authorization code' },
	{ re: /^inc\/REST\//, why: 'REST authorization boundary' },
	{ re: /^inc\/Install\//, why: 'installer, migrations or uninstaller' },
	{ re: /^inc\/Storage\//, why: 'private creative storage and encryption' },
	{ re: /^uninstall\.php$/, why: 'destructive uninstall' },

	// The runtime floor. A silent bump strands sites on the old version.
	{ re: /^aggressive-ads\.php$/, why: 'plugin header and runtime floor' },
	{ re: /^\.nvmrc$/, why: 'runtime version contract' },
] );

/**
 * Dependency manifests, which are version contracts *except* when Dependabot
 * is the one editing them.
 *
 * Treating these as unconditionally high risk would mean no Dependabot pull
 * request ever auto-merges, which defeats the purpose. Dependabot is already
 * constrained far more tightly than a person is: it can only bump a dependency
 * version, majors are refused outright, and every gate still has to pass. A
 * human editing the same file could be moving the PHP floor.
 */
const MANIFEST_PATTERNS = Object.freeze( [
	/^composer\.json$/,
	/^package\.json$/,
] );

/**
 * Area labels, in priority order. A file may match several; all are applied.
 */
const AREA_PATTERNS = Object.freeze( [
	{ label: 'area:release', re: /^(bin\/release\/|\.releaserc\.json$)/ },
	{ label: 'area:ci', re: /^(\.github\/|bin\/ci\/|bin\/check-)/ },
	{ label: 'area:i18n', re: /^(languages\/|bin\/i18n\/)/ },
	{ label: 'area:blocks', re: /^src\/blocks\// },
	{
		label: 'area:tests',
		re: /^(tests\/|.*\.test\.mjs$|phpunit.*\.xml|playwright\.config\.)/,
	},
	{
		label: 'area:frontend',
		re: /^(src\/|webpack\..*\.mjs$|.*\.(ts|tsx|css)$)/,
	},
	{
		label: 'area:php',
		re: /^(inc\/|templates\/|.*\.php$|composer\.json$|phpcs\.xml\.dist$|phpstan\.neon)/,
	},
] );

/** Paths that cannot change behaviour. */
const PROSE_RE = /^(docs\/|.*\.md$|LICENSE$|\.github\/ISSUE_TEMPLATE\/)/;

/**
 * Parses a Conventional Commit title.
 *
 * Dependabot's generated titles must keep working: with
 * `commit-message.prefix: chore` and `include: scope` they arrive as
 * `chore(deps): bump the actions group ...`, which is ordinary conventional
 * form and needs no special case.
 *
 * @param {string} title Pull request title.
 * @return {{valid: boolean, type: string|null, scope: string|null, breaking: boolean, problem: string|null}}
 */
export function parseTitle( title ) {
	if ( 'string' !== typeof title || '' === title.trim() ) {
		return {
			valid: false,
			type: null,
			scope: null,
			breaking: false,
			problem: 'the title is empty',
		};
	}

	// type(scope)!: subject
	const match = /^([a-z]+)(?:\(([^)]+)\))?(!)?:\s+(.+)$/.exec( title.trim() );

	if ( null === match ) {
		return {
			valid: false,
			type: null,
			scope: null,
			breaking: false,
			problem:
				'the title is not Conventional Commit form, e.g. "fix(cart): prevent duplicate updates"',
		};
	}

	const [ , type, scope = null, bang, subject ] = match;

	if ( ! Object.hasOwn( TYPE_LABELS, type ) ) {
		return {
			valid: false,
			type,
			scope,
			breaking: '!' === bang,
			problem: `"${ type }" is not one of: ${ KNOWN_TYPES.join( ', ' ) }`,
		};
	}

	if ( subject.trim().endsWith( '.' ) ) {
		return {
			valid: false,
			type,
			scope,
			breaking: '!' === bang,
			problem: 'the subject must not end in a full stop',
		};
	}

	return {
		valid: true,
		type,
		scope,
		breaking: '!' === bang,
		problem: null,
	};
}

/**
 * Whether a login is Dependabot.
 *
 * Both spellings appear: the REST API reports `app/dependabot` for the author
 * of a pull request and `dependabot[bot]` in event payloads.
 *
 * @param {string} login Account login.
 * @return {boolean}
 */
export function isDependabot( login ) {
	return 'app/dependabot' === login || 'dependabot[bot]' === login;
}

/**
 * Classifies a pull request from its title and changed files.
 *
 * @param {object}   pr        Pull request facts.
 * @param {string}   pr.title  Title.
 * @param {string}   pr.author Author login.
 * @param {string[]} pr.files  Changed file paths, repository-relative.
 * @return {{labels: string[], risk: string, riskReasons: string[], prose: boolean, title: object}}
 */
export function classify( { title, author, files } ) {
	const paths = Array.isArray( files ) ? files.filter( Boolean ) : [];
	const parsed = parseTitle( title );
	const labels = new Set();

	if ( parsed.valid ) {
		labels.add( TYPE_LABELS[ parsed.type ] );
	}

	if ( isDependabot( author ) ) {
		labels.add( 'dependencies' );
	}

	for ( const { label, re } of AREA_PATTERNS ) {
		if ( paths.some( ( file ) => re.test( file ) ) ) {
			labels.add( label );
		}
	}

	const riskReasons = [];

	for ( const file of paths ) {
		for ( const { re, why } of HIGH_RISK_PATTERNS ) {
			if ( re.test( file ) ) {
				riskReasons.push( `${ file } — ${ why }` );

				break;
			}
		}

		// A manifest is a version contract in a person's hands and routine
		// churn in Dependabot's. See MANIFEST_PATTERNS.
		if (
			! isDependabot( author ) &&
			MANIFEST_PATTERNS.some( ( re ) => re.test( file ) )
		) {
			riskReasons.push(
				`${ file } — dependency or runtime version contract`
			);
		}
	}

	/*
	 * A breaking change is high risk whatever it touches: semantic-release will
	 * cut a major from it, and that reaches every installed site.
	 */
	if ( parsed.breaking ) {
		riskReasons.push( 'the title declares a breaking change' );
	}

	/*
	 * An empty file list is not a low-risk pull request, it is a pull request
	 * whose files could not be read. Fail closed: the whole point of this
	 * module is that "we could not tell" never becomes "go ahead".
	 */
	if ( 0 === paths.length ) {
		riskReasons.push( 'no changed files could be determined' );
	}

	const prose =
		paths.length > 0 && paths.every( ( file ) => PROSE_RE.test( file ) );

	let risk = 'risk:medium';

	if ( riskReasons.length > 0 ) {
		risk = 'risk:high';
	} else if ( prose ) {
		risk = 'risk:low';
	} else if (
		paths.every(
			( file ) =>
				/^(tests\/|languages\/)/.test( file ) || PROSE_RE.test( file )
		)
	) {
		risk = 'risk:low';
	}

	labels.add( risk );

	return {
		labels: [ ...labels ].sort(),
		risk,
		riskReasons,
		prose,
		title: parsed,
	};
}

/**
 * Every label this policy owns.
 *
 * The workflow removes any of these that no longer apply, so a pull request
 * that stops being high risk stops being labelled high risk. It must never
 * remove a label a person added for their own reasons, which is why this list
 * is explicit rather than "everything on the pull request".
 */
export const MANAGED_LABELS = Object.freeze( [
	...new Set( Object.values( TYPE_LABELS ) ),
	...AREA_PATTERNS.map( ( area ) => area.label ),
	'risk:low',
	'risk:medium',
	'risk:high',
	'needs-attention',
] );

/**
 * Decides what automation may do with a pull request.
 *
 * Returns an action rather than performing one, so every branch is stateable
 * in a test instead of inferred from a CI run.
 *
 * @param {object}   input                    Facts, all server-verified by the caller.
 * @param {object}   input.classification     Result of `classify()`.
 * @param {string}   input.author             Author login, from the API.
 * @param {string[]} input.labels             Labels currently on the pull request.
 * @param {string}   input.mergeStateStatus   GitHub `mergeStateStatus`.
 * @param {boolean}  input.draft              Whether it is a draft.
 * @param {string[]} input.checkConclusions   Conclusion of every reported check.
 * @param {string}   [input.reviewDecision]   GitHub `reviewDecision`.
 * @param {string[]} [input.trustedAuthors]   Logins allowed to use `automerge`.
 * @return {{action: string, reason: string}} One of `merge`, `update-branch`, `wait`, `skip`.
 */
export function decide( {
	classification,
	author,
	labels = [],
	mergeStateStatus,
	draft = false,
	checkConclusions = [],
	reviewDecision = '',
	trustedAuthors = [],
} ) {
	const has = ( label ) => labels.includes( label );

	if ( draft ) {
		return { action: 'skip', reason: 'the pull request is a draft' };
	}

	/*
	 * High risk always wins over `automerge`. This is the single most important
	 * line in the file: a label is something a person types, and the whole
	 * arrangement is worthless if typing one can override the classification of
	 * a workflow or ruleset change.
	 */
	if ( 'risk:high' === classification?.risk ) {
		return {
			action: 'skip',
			reason: 'high-risk pull requests are never merged by automation',
		};
	}

	if ( ! classification?.title?.valid ) {
		return {
			action: 'skip',
			reason: 'the title is not a valid Conventional Commit, and it becomes the squash subject',
		};
	}

	const dependabot = isDependabot( author );

	if ( dependabot ) {
		// Majors are the one Dependabot update that always waits for a person.
		if ( has( 'dependency-major' ) ) {
			return {
				action: 'skip',
				reason: 'a major version update needs a person',
			};
		}
	} else if ( ! has( 'automerge' ) ) {
		return {
			action: 'skip',
			reason: 'no automerge label, so this pull request waits for a person',
		};
	} else if ( ! trustedAuthors.includes( author ) ) {
		/*
		 * The label says what to do; the author list says whether this account
		 * may ask. Checking only the label would let anyone who can label a
		 * pull request merge one.
		 */
		return {
			action: 'skip',
			reason: `${ author } is not permitted to use the automerge label`,
		};
	}

	if ( 'CHANGES_REQUESTED' === reviewDecision ) {
		return {
			action: 'skip',
			reason: 'changes were requested by a reviewer',
		};
	}

	switch ( mergeStateStatus ) {
		case 'DIRTY':
			return {
				action: 'skip',
				reason: 'the pull request conflicts with its base',
			};
		case 'BLOCKED':
			// An unresolved review thread or a failing required check. Either
			// is a person's business, and GitHub is the authority on which.
			return {
				action: 'skip',
				reason: 'GitHub reports the merge is blocked',
			};
		case 'BEHIND':
			return {
				action: 'update-branch',
				reason: 'the branch is behind its base; fresh checks must decide',
			};
		case 'UNKNOWN':
			return {
				action: 'wait',
				reason: 'GitHub has not finished computing mergeability',
			};
		case 'CLEAN':
		case 'HAS_HOOKS':
		case 'UNSTABLE':
			break;
		default:
			return {
				action: 'skip',
				reason: `unrecognised merge state ${
					mergeStateStatus || '(none)'
				}`,
			};
	}

	if ( 0 === checkConclusions.length ) {
		return {
			action: 'skip',
			reason: 'no checks have reported, which is not the same as no checks failing',
		};
	}

	const unfinished = checkConclusions.filter(
		( state ) => ! [ 'SUCCESS', 'SKIPPED', 'NEUTRAL' ].includes( state )
	);

	if ( unfinished.length > 0 ) {
		return {
			action: 'wait',
			reason: `checks not green: ${ [ ...new Set( unfinished ) ]
				.sort()
				.join( ', ' ) }`,
		};
	}

	return {
		action: 'merge',
		reason: 'every check passed and nothing needs a person',
	};
}
