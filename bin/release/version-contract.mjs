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
 * The cost is that a checkout reports `0.0.0-development` rather than the
 * release it was cut from. That is the intended reading: this tree is not a
 * release, and the tag is the only source of truth about what is.
 */

import { readFile } from 'node:fs/promises';
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

export const VERSION_PATHS = Object.freeze([
	'package.json',
	'aggressive-ads.php',
	'src/blocks/placement/block.json',
	'README.md',
	'tests/php/bootstrap-unit.php',
	'tests/php/phpstan-bootstrap.php',
]);

const STRICT_SEMVER = /^\d+\.\d+\.\d+$/u;
const CONSTANT_PATTERN = /define\(\s*'AGGR_VERSION',\s*'([^']+)'\s*\);/gu;

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
	const block = JSON.parse(sources['src/blocks/placement/block.json']);

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
 * Refuse any checked-in declaration that names a real version.
 *
 * A hand-edited version is the failure this guards. One file bumped to a real
 * number would be stamped over in the package and ignored everywhere else, so
 * it would not break a build — it would just quietly assert something untrue
 * about a tree that is not a release.
 */
export async function assertDevelopmentVersions(root = process.cwd()) {
	const versions = await readSourceVersions(root);

	const wrong = Object.entries(versions).filter(
		([, version]) => version !== DEVELOPMENT_VERSION
	);

	if (wrong.length > 0) {
		throw new Error(
			`Checked-in versions must be ${DEVELOPMENT_VERSION}; the release version is stamped at package time. ` +
				`Found ${JSON.stringify(Object.fromEntries(wrong))}.`
		);
	}

	return DEVELOPMENT_VERSION;
}
