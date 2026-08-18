import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	assertSourceVersions,
	assertVersion,
	DEVELOPMENT_VERSION,
} from './version-contract.mjs';

// Written as literal text, not via JSON.stringify: the fixture has to carry the
// Prettier-formatted shape of the real files — inline arrays included — or it
// cannot catch a writer that reformats them out from under `format:check`.
function manifestJson(version) {
	return `{\n\t"name": "fixture",\n\t"version": "${version}",\n\t"private": true,\n\t"browserslist": [ "defaults" ]\n}\n`;
}

function blockJson(version) {
	return `{\n\t"apiVersion": 3,\n\t"name": "aggr/placement",\n\t"version": "${version}",\n\t"keywords": [ "ad", "advertising" ]\n}\n`;
}

async function fixture(version = '1.2.3', manifest = DEVELOPMENT_VERSION) {
	const root = await mkdtemp(path.join(os.tmpdir(), 'aggr-version-'));
	await mkdir(path.join(root, 'tests/php'), { recursive: true });
	await mkdir(path.join(root, 'src/blocks/placement'), { recursive: true });
	await Promise.all([
		writeFile(path.join(root, 'package.json'), manifestJson(manifest)),
		writeFile(
			path.join(root, 'aggressive-ads.php'),
			`/**\n * Version:           ${version}\n */\ndefine( 'AGGR_VERSION', '${version}' );\n`
		),
		writeFile(
			path.join(root, 'src/blocks/placement/block.json'),
			blockJson(version)
		),
		writeFile(
			path.join(root, 'README.md'),
			`| Plugin | Aggressive Ads \`${version}\` |\n`
		),
		writeFile(
			path.join(root, 'tests/php/bootstrap-unit.php'),
			`<?php\ndefine( 'AGGR_VERSION', '${version}' );\n`
		),
		writeFile(
			path.join(root, 'tests/php/phpstan-bootstrap.php'),
			`<?php\ndefine( 'AGGR_VERSION', '${version}' );\n`
		),
	]);

	return root;
}

test('accepts a placeholder manifest beside one real version elsewhere', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	assert.equal(await assertSourceVersions(root), '1.2.3');
});

test('rejects a manifest that names a real version', async (context) => {
	const root = await fixture('1.2.3', '1.2.3');
	context.after(() => rm(root, { recursive: true, force: true }));
	await assert.rejects(
		assertSourceVersions(root),
		/package\.json version must be 0\.0\.0-development/u
	);
});

// The failure that actually happens: one WordPress-facing file bumped and the
// rest left behind, so a development install disagrees with itself.
test('rejects drift between the WordPress-facing declarations', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	await writeFile(
		path.join(root, 'README.md'),
		'| Plugin | Aggressive Ads `9.9.9` |\n'
	);
	await assert.rejects( assertSourceVersions(root), /disagree/u );
});

test('rejects a WordPress-facing version that is not strict semver', async (context) => {
	const root = await fixture(DEVELOPMENT_VERSION);
	context.after(() => rm(root, { recursive: true, force: true }));
	await assert.rejects( assertSourceVersions(root), /strict x\.y\.z semver/u );
});

test('rejects a planned release version that is not strict semver', () => {
	assert.throws(
		() => assertVersion('v2.0.0', 'Planned release version'),
		/strict x\.y\.z semver/u
	);
});

test('accepts a strict planned release version', () => {
	assert.doesNotThrow(() => assertVersion('2.0.0', 'Planned release version'));
});
