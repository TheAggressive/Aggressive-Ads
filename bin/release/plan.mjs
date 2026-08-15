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

import { assertVersion, readSourceVersion } from './version-contract.mjs';

// semantic-release redacts secrets in the log stream it writes, but a thrown
// error's message never passes through that filter. This job holds a
// write-capable token, so the catch redacts before printing to a build log.
const SECRET_NAME = /token|secret|password|passwd|credential|private_key/iu;

function sanitize(message) {
	let sanitized = message;

	for (const [name, value] of Object.entries(process.env)) {
		if (
			typeof value === 'string' &&
			value.length >= 8 &&
			SECRET_NAME.test(name)
		) {
			sanitized = sanitized.split(value).join('[secure]');
		}
	}

	return sanitized;
}

// Safe only because both operands are asserted strict x.y.z first; `Number`
// would otherwise yield NaN for a prerelease and silently compare as equal.
function compareVersions(left, right) {
	const leftParts = left.split('.').map(Number);
	const rightParts = right.split('.').map(Number);

	for (let index = 0; index < 3; index += 1) {
		if (leftParts[index] !== rightParts[index]) {
			return leftParts[index] - rightParts[index];
		}
	}

	return 0;
}

async function writeOutputs(outputPath, values) {
	await appendFile(
		outputPath,
		`${Object.entries(values)
			.map(([key, value]) => `${key}=${value}`)
			.join('\n')}\n`,
		'utf8'
	);
}

async function planRelease() {
	const outputPath = process.env.GITHUB_OUTPUT;

	if (!outputPath) {
		throw new Error('GITHUB_OUTPUT is required for release planning.');
	}

	const sourceVersion = await readSourceVersion();
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
		await writeOutputs(outputPath, {
			should_release: false,
			source_version: sourceVersion,
			needs_version_sync: false,
		});
		console.log(`No release-worthy commits found; source is ${sourceVersion}.`);
		return;
	}

	const { type, version } = result.nextRelease;
	// Fail closed on anything the rest of the pipeline cannot stamp. package.sh,
	// verify-package.sh and the version contract all require strict x.y.z, so a
	// prerelease must stop planning rather than skip the sync step.
	assertVersion(version, 'Planned release version');
	const comparison = compareVersions(sourceVersion, version);
	if (comparison > 0) {
		throw new Error(
			`Source version ${sourceVersion} is ahead of planned release ${version}.`
		);
	}

	const needsVersionSync = comparison < 0;
	await writeOutputs(outputPath, {
		should_release: true,
		release_type: type,
		next_version: version,
		source_version: sourceVersion,
		needs_version_sync: needsVersionSync,
	});

	console.log(
		`Release due: ${type} version ${version}; source ${sourceVersion}; sync ${needsVersionSync}.`
	);
}

try {
	await planRelease();
} catch (error) {
	const errorName = error instanceof Error ? error.name : 'UnknownError';
	const message = error instanceof Error ? error.message : String(error);
	console.error(`Release planning failed (${errorName}): ${sanitize(message)}`);
	process.exitCode = 1;
}
