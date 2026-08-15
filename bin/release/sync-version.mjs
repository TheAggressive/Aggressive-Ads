#!/usr/bin/env node

/** Synchronize every checked-in product version for a protected release PR. */

import process from 'node:process';

import { writeSourceVersion } from './version-contract.mjs';

const version = process.argv[2];

try {
	const synchronized = await writeSourceVersion(version);
	console.log(`sync-version: ${synchronized}`);
} catch (error) {
	const message = error instanceof Error ? error.message : String(error);
	console.error(`sync-version: ${message}`);
	process.exitCode = 1;
}
