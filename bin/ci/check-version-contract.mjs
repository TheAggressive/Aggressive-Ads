#!/usr/bin/env node

/** Verify that every checked-in product version agrees. */

import process from 'node:process';

import { readSourceVersion } from '../release/version-contract.mjs';

try {
	const version = await readSourceVersion();
	console.log( `check-version-contract: ${ version } ok` );
} catch ( error ) {
	const message = error instanceof Error ? error.message : String( error );
	console.error( `check-version-contract: ${ message }` );
	process.exitCode = 1;
}
