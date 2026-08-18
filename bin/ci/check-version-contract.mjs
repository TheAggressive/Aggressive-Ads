#!/usr/bin/env node

/**
 * Verify that no checked-in version claims to be a release.
 *
 * package.json stays at the development placeholder; everything WordPress reads
 * carries the last released version and must agree with itself. The released
 * version is stamped into the staged tree by `package.sh` either way. See
 * `bin/release/version-contract.mjs`.
 */

import process from 'node:process';

import { assertSourceVersions } from '../release/version-contract.mjs';

try {
	const version = await assertSourceVersions();
	console.log( `check-version-contract: ${ version } ok` );
} catch ( error ) {
	const message = error instanceof Error ? error.message : String( error );
	console.error( `check-version-contract: ${ message }` );
	process.exitCode = 1;
}
