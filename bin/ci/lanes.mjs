#!/usr/bin/env node

/**
 * The lane list, read out of the workflow rather than kept beside it.
 *
 * `check-ci-parity.sh` proved that every `ci:*` script appears in both
 * `ci.yml` and `verify.sh`. That is name parity, and it is not enough: the two
 * can still disagree about *order*, about which job runs a command more than
 * once, and about anything CI runs that is not a `ci:*` script at all. A second
 * hand-maintained list is a second thing to forget.
 *
 * So there is no second list. `verify.sh` asks this file what to run, and this
 * file reads `.github/workflows/ci.yml`. Adding a step to a verification job is
 * now the only edit needed; the local rehearsal picks it up because it is the
 * same source.
 *
 * Output is one `pnpm <command>` per line, in the order CI would reach them:
 * jobs in dependency order, steps in file order, with a command dropped once it
 * has already run.
 *
 * Print it with `pnpm ci:lanes` when you want to see what a local run will do.
 */

import { readFile } from 'node:fs/promises';
import path from 'node:path';
import process from 'node:process';

/*
 * Overridable so check-ci-parity can point the real parser at a fixture tree.
 * Without this a fixture run reads this repository's own workflow, and the
 * "the workflow runs it but lanes.mjs does not reach it" branch could never be
 * exercised by a test.
 */
const ROOT =
	process.env.AGGR_LANES_ROOT ?? path.resolve( import.meta.dirname, '../..' );
const WORKFLOW = path.join( ROOT, '.github/workflows/ci.yml' );

/**
 * Jobs that publish rather than verify.
 *
 * These hold write tokens, talk to the GitHub API and cut releases. Rehearsing
 * them on a laptop is neither possible nor desirable, and none of them decides
 * whether the change under test is sound.
 */
const PUBLISHING_JOBS = new Set( [ 'release-plan', 'release' ] );

/**
 * Commands CI runs that a local rehearsal deliberately does not.
 *
 * This is the *only* place the two are allowed to differ, which is why each
 * entry carries its reason. Anything not listed here runs locally exactly as it
 * runs remotely.
 */
const LOCAL_SKIP = new Map( [
	[
		'install --frozen-lockfile',
		{
			reason: 'dependencies are already installed; CI starts bare',
			replaceWith: null,
		},
	],
	[
		'install --frozen-lockfile --ignore-scripts',
		{ reason: 'as above, in the release jobs', replaceWith: null },
	],
	[
		'test:e2e:install',
		{
			reason:
				'--with-deps apt-gets browser libraries through sudo on every ' +
				'run, without checking whether they are already there',
			replaceWith: 'test:e2e:browsers',
		},
	],
	[
		'test:e2e:install:chromium',
		{
			reason:
				'the pull-request variant of the above, and sudo apt is no more ' +
				'welcome on a laptop for having installed one browser fewer',
			replaceWith: 'test:e2e:browsers',
		},
	],
] );

/**
 * Parses the jobs out of the workflow.
 *
 * A hand-rolled reader rather than a YAML dependency, because the shape it has
 * to understand is small and fixed — job name, `needs:`, and `run:` lines — and
 * a runtime dependency here would ship in nothing and still need updating.
 *
 * @param {string} yaml Workflow source.
 * @return {Map<string, { needs: string[], commands: string[] }>} Jobs by name.
 */
function parseJobs( yaml ) {
	const jobs = new Map();
	const lines = yaml.split( '\n' );

	let current = null;
	let inJobs = false;

	for ( const line of lines ) {
		if ( /^jobs:\s*$/.test( line ) ) {
			inJobs = true;
			continue;
		}

		if ( ! inJobs ) {
			continue;
		}

		const job = /^ {2}([\w-]+):\s*$/.exec( line );

		if ( job ) {
			current = job[ 1 ];
			jobs.set( current, { needs: [], commands: [] } );
			continue;
		}

		if ( null === current ) {
			continue;
		}

		const needs = /^\s*needs:\s*\[([^\]]*)\]/.exec( line );

		if ( needs ) {
			jobs.get( current ).needs = needs[ 1 ]
				.split( ',' )
				.map( ( name ) => name.trim() )
				.filter( Boolean );
			continue;
		}

		const single = /^\s*needs:\s*([\w-]+)\s*$/.exec( line );

		if ( single ) {
			jobs.get( current ).needs = [ single[ 1 ] ];
			continue;
		}

		// `- run: pnpm x` and the `run: pnpm x` under a named step both count.
		const run = /^\s*-?\s*run:\s*pnpm\s+(.+?)\s*$/.exec( line );

		if ( run ) {
			jobs.get( current ).commands.push( run[ 1 ] );
		}
	}

	return jobs;
}

/**
 * Job names in dependency order, stable for jobs that do not depend on anything.
 *
 * @param {Map<string, { needs: string[] }>} jobs Parsed jobs.
 * @return {string[]} Ordered job names.
 */
function ordered( jobs ) {
	const done = new Set();
	const order = [];

	let progressed = true;

	while ( progressed ) {
		progressed = false;

		for ( const [ name, job ] of jobs ) {
			if ( done.has( name ) ) {
				continue;
			}

			const ready = job.needs.every(
				( need ) => done.has( need ) || ! jobs.has( need )
			);

			if ( ready ) {
				done.add( name );
				order.push( name );
				progressed = true;
			}
		}
	}

	// A cycle would leave jobs unplaced. Reporting beats silently running less.
	for ( const name of jobs.keys() ) {
		if ( ! done.has( name ) ) {
			console.error( `ci:lanes: ${ name } is in a dependency cycle` );
			process.exit( 1 );
		}
	}

	return order;
}

const yaml = await readFile( WORKFLOW, 'utf8' );
const jobs = parseJobs( yaml );
const seen = new Set();
const lanes = [];

for ( const name of ordered( jobs ) ) {
	if ( PUBLISHING_JOBS.has( name ) ) {
		continue;
	}

	for ( const command of jobs.get( name ).commands ) {
		const substitution = LOCAL_SKIP.get( command );
		const local = substitution ? substitution.replaceWith : command;

		if ( null === local || undefined === local || seen.has( local ) ) {
			continue;
		}

		seen.add( local );
		lanes.push( `${ name }\t${ local }` );
	}
}

if ( 0 === lanes.length ) {
	console.error( 'ci:lanes: no commands found — has the workflow moved?' );
	process.exit( 1 );
}

console.log( lanes.join( '\n' ) );
