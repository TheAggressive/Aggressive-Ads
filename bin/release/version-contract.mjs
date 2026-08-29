/**
 * The product version declarations, and the rule that they are never real.
 *
 * The checkout does not carry the released version. `package.sh` stamps the
 * planned version into the staged tree at package time, so the bytes that are
 * archived, checksummed, attested and uploaded say the right thing while
 * nothing in the repository is mutated.
 *
 * That is a deliberate reversal. Synchronizing versions into `master` meant a
 * bot opening a pull request against a branch that requires signed commits,
 * reviewed pull requests and passing checks — and GitHub will not let its own
 * token do that unattended. The result was a release that stalled twice on
 * human intervention while every check showed green. Removing the write
 * removes the credential, the pull request, and both stalls, and `master` keeps
 * every protection because nothing but a person's pull request ever lands on
 * it.
 *
 * What a checkout declares is split, matching the Aggressive Apparel theme.
 * `package.json` carries `0.0.0-development`, semantic-release's own marker for
 * a project whose version lives in its tags. Everything WordPress reads — the
 * plugin header, `AGGR_VERSION`, the block manifest — carries the last released
 * version instead, so a development install shows a sensible number in the
 * plugins list rather than a placeholder, and the updater compares against a
 * real one.
 *
 * Those declarations go stale between releases, and that is accepted rather
 * than solved: the theme's `style.css` currently trails its published release
 * by two minors. Nothing reads them at release time — `package.sh` stamps the
 * planned version over all of them — so staleness costs a slightly old number
 * on a development site and nothing else.
 */

import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

/**
 * What every checked-in declaration must say.
 *
 * `0.0.0-development` is semantic-release's own convention for a repository
 * whose version lives in its tags, and it sorts below every real release, so
 * anything comparing against it treats a checkout as older than any published
 * build rather than newer.
 */
export const DEVELOPMENT_VERSION = '0.0.0-development';

// Not exported: every caller goes through the readers below, and an exported
// list invites somebody to iterate it and write their own reader.
const VERSION_PATHS = Object.freeze([
	'package.json',
	'aggressive-ads.php',
	'src/blocks-interactivity/ad-slot/block.json',
	'README.md',
	'tests/php/bootstrap-unit.php',
	'tests/php/phpstan-bootstrap.php',
]);

const STRICT_SEMVER = /^\d+\.\d+\.\d+$/u;
const CONSTANT_PATTERN = /define\(\s*'AGGR_VERSION',\s*'([^']+)'\s*\);/gu;

// JSON is edited textually rather than re-serialized. `JSON.stringify` expands
// the inline arrays Prettier keeps on one line, so a round-trip through it
// leaves block.json failing `format:check` — a required lane. Anchored to a
// single tab so it matches only the top-level key, never `"apiVersion"` or a
// nested dependency entry.
const JSON_VERSION_PATTERN = /^(\t"version": ")[^"]+(",?)$/gmu;

function replaceOne(source, pattern, replacement, label) {
	const matches = [...source.matchAll(pattern)];

	if (matches.length !== 1) {
		throw new Error(`${label} must appear exactly once.`);
	}

	return source.replace(pattern, replacement);
}

function filePath(root, relativePath) {
	return path.join(root, relativePath);
}

function exactlyOne(source, pattern, label) {
	const matches = [...source.matchAll(pattern)];

	if (matches.length !== 1 || typeof matches[0][1] !== 'string') {
		throw new Error(`${label} must appear exactly once.`);
	}

	return matches[0][1];
}

/**
 * Strict x.y.z, for a version that is about to be released.
 *
 * Still enforced even though nothing is written back: `package.sh`,
 * `verify-package.sh` and the archive name all require it, so a prerelease has
 * to stop the plan rather than reach packaging.
 */
export function assertVersion(version, label = 'Version') {
	if (typeof version !== 'string' || !STRICT_SEMVER.test(version)) {
		throw new Error(`${label} must be strict x.y.z semver; received ${version}.`);
	}
}

async function readSources(root) {
	const contents = await Promise.all(
		VERSION_PATHS.map((relativePath) =>
			readFile(filePath(root, relativePath), 'utf8')
		)
	);

	return Object.fromEntries(
		VERSION_PATHS.map((relativePath, index) => [relativePath, contents[index]])
	);
}

/**
 * Every declaration the packager stamps, as it currently reads on disk.
 */
export async function readSourceVersions(root = process.cwd()) {
	const sources = await readSources(root);
	const manifest = JSON.parse(sources['package.json']);

	if (manifest.private !== true) {
		throw new Error('package.json must remain private.');
	}

	const plugin = sources['aggressive-ads.php'];
	const block = JSON.parse(sources['src/blocks-interactivity/ad-slot/block.json']);

	return {
		'package.json version': manifest.version,
		'placement block version': block.version,
		'WordPress plugin header': exactlyOne(
			plugin,
			/^\s*\*\s*Version:\s*(\S+)\s*$/gmu,
			'WordPress Version header'
		),
		'AGGR_VERSION constant': exactlyOne(
			plugin,
			CONSTANT_PATTERN,
			'AGGR_VERSION definition'
		),
		'README plugin version': exactlyOne(
			sources['README.md'],
			/^\| Plugin \| Aggressive Ads `([^`]+)` \|$/gmu,
			'README plugin version'
		),
		'unit-test AGGR_VERSION': exactlyOne(
			sources['tests/php/bootstrap-unit.php'],
			CONSTANT_PATTERN,
			'Unit-test AGGR_VERSION definition'
		),
		'PHPStan AGGR_VERSION': exactlyOne(
			sources['tests/php/phpstan-bootstrap.php'],
			CONSTANT_PATTERN,
			'PHPStan AGGR_VERSION definition'
		),
	};
}

/**
 * The one declaration that must stay a placeholder.
 */
const PLACEHOLDER_KEY = 'package.json version';

/**
 * Enforce the split: a placeholder in package.json, one real version elsewhere.
 *
 * The failure this guards is drift between the WordPress-facing declarations.
 * A header saying 1.1.1 beside an AGGR_VERSION saying 1.0.4 breaks nothing at
 * release time, because packaging stamps over both — it just makes two files
 * disagree about what a development install is, which is the kind of untruth
 * somebody eventually debugs.
 */
export async function assertSourceVersions(root = process.cwd()) {
	const versions = await readSourceVersions(root);

	if (versions[PLACEHOLDER_KEY] !== DEVELOPMENT_VERSION) {
		throw new Error(
			`${PLACEHOLDER_KEY} must be ${DEVELOPMENT_VERSION}; found ${versions[PLACEHOLDER_KEY]}.`
		);
	}

	const declared = Object.entries(versions).filter(
		([label]) => label !== PLACEHOLDER_KEY
	);

	for (const [label, version] of declared) {
		assertVersion(version, label);
	}

	const distinct = new Set(declared.map(([, version]) => version));

	if (distinct.size !== 1) {
		throw new Error(
			`Version declarations disagree: ${JSON.stringify(Object.fromEntries(declared))}.`
		);
	}

	return String(declared[0][1]);
}

/**
 * Writes a real version into every declaration WordPress reads.
 *
 * `package.json` is deliberately absent. It carries the development
 * placeholder permanently, and writing a release version into it would put the
 * repository back in the state the version-PR machinery existed to maintain.
 *
 * @param {string} version Strict x.y.z version to write.
 * @param {string} root    Repository root.
 * @return {Promise<string>} The version now declared, re-read from disk.
 */
export async function writeSourceVersions(version, root = process.cwd()) {
	assertVersion(version, 'Requested version');

	const read = async (relativePath) =>
		readFile(filePath(root, relativePath), 'utf8');

	const updates = {
		'src/blocks-interactivity/ad-slot/block.json': replaceOne(
			await read('src/blocks-interactivity/ad-slot/block.json'),
			JSON_VERSION_PATTERN,
			`$1${version}$2`,
			'placement block version'
		),
		'aggressive-ads.php': replaceOne(
			replaceOne(
				await read('aggressive-ads.php'),
				/^(\s*\*\s*Version:\s*)\S+\s*$/gmu,
				`$1${version}`,
				'WordPress Version header'
			),
			CONSTANT_PATTERN,
			`define( 'AGGR_VERSION', '${version}' );`,
			'AGGR_VERSION definition'
		),
		'README.md': replaceOne(
			await read('README.md'),
			/^\| Plugin \| Aggressive Ads `[^`]+` \|$/gmu,
			`| Plugin | Aggressive Ads \`${version}\` |`,
			'README plugin version'
		),
		'tests/php/bootstrap-unit.php': replaceOne(
			await read('tests/php/bootstrap-unit.php'),
			CONSTANT_PATTERN,
			`define( 'AGGR_VERSION', '${version}' );`,
			'Unit-test AGGR_VERSION definition'
		),
		'tests/php/phpstan-bootstrap.php': replaceOne(
			await read('tests/php/phpstan-bootstrap.php'),
			CONSTANT_PATTERN,
			`define( 'AGGR_VERSION', '${version}' );`,
			'PHPStan AGGR_VERSION definition'
		),
	};

	await Promise.all(
		Object.entries(updates).map(([relativePath, contents]) =>
			writeFile(filePath(root, relativePath), contents, 'utf8')
		)
	);

	// Re-read rather than trusting the write: this is also the check that the
	// five files now agree, which is the failure it exists to prevent.
	return assertSourceVersions(root);
}
