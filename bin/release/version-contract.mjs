/**
 * Read and update the product version declarations reviewed in release PRs.
 */

import { readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

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

// JSON is edited textually rather than re-serialized. `JSON.stringify` expands
// the inline arrays Prettier keeps on one line, so a round-trip through it
// leaves block.json failing `format:check` — which is a required lane, so the
// generated version PR could never merge and the release could never publish.
// Anchored to a single tab so it matches only the top-level key, never
// `"apiVersion"` or a nested dependency entry.
const JSON_VERSION_PATTERN = /^(\t"version": ")[^"]+(",?)$/gmu;

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

function replaceOne(source, pattern, replacement, label) {
	const matches = [...source.matchAll(pattern)];
	if (matches.length !== 1) {
		throw new Error(`${label} must appear exactly once.`);
	}

	return source.replace(pattern, replacement);
}

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

export async function readSourceVersion(root = process.cwd()) {
	const sources = await readSources(root);
	const manifest = JSON.parse(sources['package.json']);
	if (manifest.private !== true) {
		throw new Error('package.json must remain private.');
	}

	const plugin = sources['aggressive-ads.php'];
	const block = JSON.parse(sources['src/blocks/placement/block.json']);
	const versions = {
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

	for (const [label, version] of Object.entries(versions)) {
		assertVersion(version, label);
	}

	if (new Set(Object.values(versions)).size !== 1) {
		throw new Error(
			`Version declarations disagree: ${JSON.stringify(versions)}.`
		);
	}

	return String(manifest.version);
}

export async function writeSourceVersion(version, root = process.cwd()) {
	assertVersion(version, 'Requested version');
	const sources = await readSources(root);

	const updates = {
		'package.json': replaceOne(
			sources['package.json'],
			JSON_VERSION_PATTERN,
			`$1${version}$2`,
			'package.json version'
		),
		'src/blocks/placement/block.json': replaceOne(
			sources['src/blocks/placement/block.json'],
			JSON_VERSION_PATTERN,
			`$1${version}$2`,
			'placement block version'
		),
		'aggressive-ads.php': replaceOne(
			replaceOne(
				sources['aggressive-ads.php'],
				/^(\s*\*\s*Version:\s*)\S+\s*$/gmu,
				`$1${version}`,
				'WordPress Version header'
			),
			CONSTANT_PATTERN,
			`define( 'AGGR_VERSION', '${version}' );`,
			'AGGR_VERSION definition'
		),
		'README.md': replaceOne(
			sources['README.md'],
			/^\| Plugin \| Aggressive Ads `[^`]+` \|$/gmu,
			`| Plugin | Aggressive Ads \`${version}\` |`,
			'README plugin version'
		),
		'tests/php/bootstrap-unit.php': replaceOne(
			sources['tests/php/bootstrap-unit.php'],
			CONSTANT_PATTERN,
			`define( 'AGGR_VERSION', '${version}' );`,
			'Unit-test AGGR_VERSION definition'
		),
		'tests/php/phpstan-bootstrap.php': replaceOne(
			sources['tests/php/phpstan-bootstrap.php'],
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

	return readSourceVersion(root);
}
