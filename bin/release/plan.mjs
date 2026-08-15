#!/usr/bin/env node

/**
 * Determine whether semantic-release would publish from the current commit.
 *
 * The JavaScript API returns structured data and rejects on operational
 * errors, so CI can fail closed without parsing human-readable output.
 */

import { appendFile } from 'node:fs/promises';
import process from 'node:process';

import semanticRelease from 'semantic-release';

async function planRelease() {
	const outputPath = process.env.GITHUB_OUTPUT;

	if (!outputPath) {
		throw new Error('GITHUB_OUTPUT is required for release planning.');
	}

	const result = await semanticRelease(
		{ dryRun: true },
		{
			cwd: process.cwd(),
			env: process.env,
			stdout: process.stdout,
			stderr: process.stderr,
		}
	);

	if (!result) {
		await appendFile(outputPath, 'should_release=false\n', 'utf8');
		console.log('No release-worthy commits found.');
		return;
	}

	const { type, version } = result.nextRelease;

	await appendFile(
		outputPath,
		[
			'should_release=true',
			`release_type=${type}`,
			`next_version=${version}`,
			'',
		].join('\n'),
		'utf8'
	);

	console.log(`Release due: ${type} version ${version}.`);
}

try {
	await planRelease();
} catch (error) {
	const errorName = error instanceof Error ? error.name : 'UnknownError';
	console.error(
		`Release planning failed (${errorName}). Review the sanitized semantic-release log above.`
	);
	process.exitCode = 1;
}
