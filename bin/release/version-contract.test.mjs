import assert from 'node:assert/strict';
import { mkdtemp, mkdir, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import {
	assertDevelopmentVersions,
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

async function fixture(version = DEVELOPMENT_VERSION) {
	const root = await mkdtemp(path.join(os.tmpdir(), 'aggr-version-'));
	await mkdir(path.join(root, 'tests/php'), { recursive: true });
	await mkdir(path.join(root, 'src/blocks/placement'), { recursive: true });
	await Promise.all([
		writeFile(path.join(root, 'package.json'), manifestJson(version)),
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

test('accepts a tree where every declaration is the development version', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	assert.equal(await assertDevelopmentVersions(root), DEVELOPMENT_VERSION);
});

test('rejects a declaration hand-edited to a real version', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	await writeFile(
		path.join(root, 'README.md'),
		'| Plugin | Aggressive Ads `9.9.9` |\n'
	);
	await assert.rejects(
		assertDevelopmentVersions(root),
		/Checked-in versions must be 0\.0\.0-development/u
	);
});

// The whole tree bumped is the case a per-file comparison would miss: every
// declaration still agrees with every other, and the tree is still not a
// release.
test('rejects a tree where every declaration was bumped together', async (context) => {
	const root = await fixture('2.0.0');
	context.after(() => rm(root, { recursive: true, force: true }));
	await assert.rejects(
		assertDevelopmentVersions(root),
		/Checked-in versions must be 0\.0\.0-development/u
	);
});

// Nothing writes versions back any more, but a release still has to be strict
// x.y.z: package.sh, verify-package.sh and the archive name all require it.
test('rejects a planned release version that is not strict semver', () => {
	assert.throws(
		() => assertVersion('v2.0.0', 'Planned release version'),
		/strict x\.y\.z semver/u
	);
	assert.throws(
		() => assertVersion(DEVELOPMENT_VERSION, 'Planned release version'),
		/strict x\.y\.z semver/u
	);
});

test('accepts a strict planned release version', () => {
	assert.doesNotThrow(() => assertVersion('2.0.0', 'Planned release version'));
});
