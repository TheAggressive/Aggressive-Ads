#!/usr/bin/env node

/**
 * Verify that no checked-in version claims to be a release.
 *
 * The released version is stamped into the staged tree by `package.sh` and is
 * never written back to the repository, so every declaration here must read as
 * development. See `bin/release/version-contract.mjs`.
 */

import process from 'node:process';

import { assertDevelopmentVersions } from '../release/version-contract.mjs';

try {
	const version = await assertDevelopmentVersions();
	console.log( `check-version-contract: ${ version } ok` );
} catch ( error ) {
	const message = error instanceof Error ? error.message : String( error );
	console.error( `check-version-contract: ${ message }` );
	process.exitCode = 1;
}
