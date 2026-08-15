import assert from 'node:assert/strict';
import { mkdtemp, mkdir, readFile, rm, writeFile } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import test from 'node:test';

import { readSourceVersion, writeSourceVersion } from './version-contract.mjs';

// Written as literal text, not via JSON.stringify: the fixture has to carry the
// Prettier-formatted shape of the real files — inline arrays included — or it
// cannot catch a writer that reformats them out from under `format:check`.
function manifestJson(version) {
	return `{\n\t"name": "fixture",\n\t"version": "${version}",\n\t"private": true,\n\t"browserslist": [ "defaults" ]\n}\n`;
}

function blockJson(version) {
	return `{\n\t"apiVersion": 3,\n\t"name": "aggr/placement",\n\t"version": "${version}",\n\t"keywords": [ "ad", "advertising" ]\n}\n`;
}

async function fixture(version = '1.2.3') {
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

test('reads one synchronized strict version', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	assert.equal(await readSourceVersion(root), '1.2.3');
});

test('rejects drift between declarations', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	await writeFile(
		path.join(root, 'README.md'),
		'| Plugin | Aggressive Ads `9.9.9` |\n'
	);
	await assert.rejects(readSourceVersion(root), /Version declarations disagree/u);
});

test('updates and revalidates every declaration', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	assert.equal(await writeSourceVersion('2.0.0', root), '2.0.0');
	assert.equal(await readSourceVersion(root), '2.0.0');
});

test('rewrites JSON without reformatting anything else', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	await writeSourceVersion('2.0.0', root);

	assert.equal(
		await readFile(path.join(root, 'package.json'), 'utf8'),
		manifestJson('2.0.0')
	);
	assert.equal(
		await readFile(path.join(root, 'src/blocks/placement/block.json'), 'utf8'),
		blockJson('2.0.0')
	);
});

test('rejects non-strict release versions', async (context) => {
	const root = await fixture();
	context.after(() => rm(root, { recursive: true, force: true }));
	await assert.rejects(
		writeSourceVersion('v2.0.0', root),
		/strict x\.y\.z semver/u
	);
});
