#!/usr/bin/env node

import { assertSafeTarget, parseDuration, runFill } from './mixed-view.mjs';

const baseUrl = process.argv[ 2 ] ?? 'http://127.0.0.1:9961';
const duration = process.argv[ 3 ] ?? '60s';
const connections = Number.parseInt( process.argv[ 4 ] ?? '64', 10 );

if ( ! Number.isSafeInteger( connections ) || connections <= 0 ) {
	throw new Error( 'connections must be a positive integer.' );
}

assertSafeTarget( baseUrl, process.env.AGGR_LOAD_REMOTE_CONFIRM );
const result = await runFill( baseUrl, parseDuration( duration ), connections );

process.stdout.write( `AGGR_LOAD_JSON:${ JSON.stringify( result ) }\n` );
