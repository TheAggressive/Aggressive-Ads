#!/usr/bin/env node
/**
 * Take, and put back, the state of a Studio site around a browser-suite run.
 *
 *   node bin/local/e2e-snapshot.mjs take    <site path> <database file>
 *   node bin/local/e2e-snapshot.mjs restore <site path> <database file>
 *
 * The suite used to clean up after itself, spec by spec and seed by seed, and
 * that is how a working site collected about 190 fixture campaigns and 476
 * orphaned creatives: every new seed had to remember, a run that died never
 * reached its cleanup, and a reset password or a switched theme is an
 * overwrite that no amount of deleting undoes. So the runner stops trusting the
 * tests to clean up and puts the site back as a whole instead.
 *
 * A Studio site's database is one SQLite file, so the whole of it is copied
 * through SQLite's backup API, both ways. Not `cp`: the site may be serving
 * when the snapshot is taken, and the backup API copies under SQLite's own
 * locking, so neither side ever reads a half-written file. The runner stops the
 * site before a restore, so nothing writes after the database is put back.
 *
 * Uploads are files, and can run to gigabytes, so they are hard-linked into the
 * snapshot rather than copied: no space, no time, and a file the run deletes
 * survives under its second name. A restore links back every listed file that is
 * missing — seeds delete a previous run's creatives, and their private files go
 * with them, which left the restored database pointing at files that were gone —
 * and deletes every file under `wp-content/uploads` the list does not name. A
 * file the run *changed* in place is not put back, since a hard link shares its
 * content; nothing in the suite does that.
 *
 * The snapshot lives in the site, at `.aggr-e2e-snapshot/`. Its existence means
 * a run is in progress or died without restoring; the runner's lock
 * (e2e-lock.mjs) tells the two apart, and a dead run's snapshot is the truth
 * rather than whatever the site says now — `take` refuses to overwrite it, so a
 * dead run's leftovers can never become the next run's baseline. `restore`
 * removes it last, and only when everything before that worked.
 */

import {
	copyFileSync,
	existsSync,
	linkSync,
	lstatSync,
	mkdirSync,
	readdirSync,
	readFileSync,
	realpathSync,
	rmSync,
	rmdirSync,
	unlinkSync,
	writeFileSync,
} from 'node:fs';
import path from 'node:path';
import { DatabaseSync, backup } from 'node:sqlite';
import { fileURLToPath } from 'node:url';

export const SNAPSHOT_DIR = '.aggr-e2e-snapshot';

const DATABASE = 'database.sqlite';
const UPLOADS = 'uploads.json';
const FILES = 'uploads';

/**
 * Every file and directory under `root`, relative to it.
 *
 * Links are listed as files and never followed — a Dirent does not resolve
 * them — so one pointing out of uploads cannot make a restore walk, or delete,
 * outside it. Removing a new link removes the link alone.
 *
 * @param {string} root
 * @return {{ files: string[], dirs: string[] }}
 */
function walk( root ) {
	const files = [];
	const dirs = [];

	if ( ! existsSync( root ) ) {
		return { files, dirs };
	}

	const visit = ( relative ) => {
		for ( const entry of readdirSync( path.join( root, relative ), {
			withFileTypes: true,
		} ) ) {
			const child = relative
				? path.join( relative, entry.name )
				: entry.name;

			if ( entry.isDirectory() ) {
				dirs.push( child );
				visit( child );
			} else {
				files.push( child );
			}
		}
	};

	visit( '' );

	return { files, dirs };
}

/**
 * @param {string} site
 * @param {string} database
 */
function paths( site, database ) {
	const siteRoot = realpathSync( site );
	const snapshot = path.join( siteRoot, SNAPSHOT_DIR );

	return {
		database: realpathSync( database ),
		snapshot,
		snapshotDatabase: path.join( snapshot, DATABASE ),
		snapshotUploads: path.join( snapshot, UPLOADS ),
		snapshotFiles: path.join( snapshot, FILES ),
		uploads: path.join( siteRoot, 'wp-content', 'uploads' ),
	};
}

/**
 * A second name for `from` at `to`, or a copy where a link cannot reach.
 *
 * @param {string} from
 * @param {string} to
 */
function keep( from, to ) {
	mkdirSync( path.dirname( to ), { recursive: true } );

	try {
		linkSync( from, to );
	} catch ( error ) {
		// Another filesystem, or one without hard links.
		if ( ! [ 'EXDEV', 'EPERM', 'ENOTSUP' ].includes( error.code ) ) {
			throw error;
		}

		copyFileSync( from, to );
	}
}

/**
 * Record the database and the uploads.
 *
 * @param {string} site
 * @param {string} database
 */
export async function take( site, database ) {
	const where = paths( site, database );

	if ( existsSync( where.snapshot ) ) {
		throw new Error(
			`${ where.snapshot } already exists: a run did not restore. ` +
				'Restore it before taking another.'
		);
	}

	mkdirSync( where.snapshot );

	const source = new DatabaseSync( where.database, { readOnly: true } );

	try {
		await backup( source, where.snapshotDatabase );
	} finally {
		source.close();
	}

	const uploads = walk( where.uploads );

	for ( const file of uploads.files ) {
		const from = path.join( where.uploads, file );

		// A link is listed, so it is not deleted, but not kept: re-creating
		// one is not this file's business, and following it could reach
		// anywhere.
		if ( ! lstatSync( from ).isSymbolicLink() ) {
			keep( from, path.join( where.snapshotFiles, file ) );
		}
	}

	// Written last: a snapshot without its upload list is incomplete, and
	// restore refuses one rather than deleting by a list it does not have.
	writeFileSync( where.snapshotUploads, JSON.stringify( uploads ) );
}

/**
 * Put the database back, put back the uploads the run deleted, delete the ones
 * it added, then drop the snapshot.
 *
 * @param {string} site
 * @param {string} database
 * @return {Promise<{ restoredFiles: number, removedFiles: number, removedDirs: number }>}
 */
export async function restore( site, database ) {
	const where = paths( site, database );

	if (
		! existsSync( where.snapshotUploads ) ||
		! existsSync( where.snapshotDatabase )
	) {
		throw new Error(
			`${ where.snapshot } is missing or incomplete; nothing restored.`
		);
	}

	const before = JSON.parse( readFileSync( where.snapshotUploads, 'utf8' ) );
	const keptFiles = new Set( before.files );
	const keptDirs = new Set( before.dirs );

	const saved = new DatabaseSync( where.snapshotDatabase, {
		readOnly: true,
	} );

	try {
		await backup( saved, where.database );
	} finally {
		saved.close();
	}

	const now = walk( where.uploads );
	let restoredFiles = 0;
	let removedFiles = 0;
	let removedDirs = 0;

	const present = new Set( now.files );

	for ( const file of before.files ) {
		const kept = path.join( where.snapshotFiles, file );

		if ( ! present.has( file ) && existsSync( kept ) ) {
			keep( kept, path.join( where.uploads, file ) );
			restoredFiles++;
		}
	}

	for ( const file of now.files ) {
		if ( ! keptFiles.has( file ) ) {
			unlinkSync( path.join( where.uploads, file ) );
			removedFiles++;
		}
	}

	// Deepest first, so a new directory's new children are gone before it is.
	for ( const dir of now.dirs.sort( ( a, b ) => b.length - a.length ) ) {
		if ( ! keptDirs.has( dir ) ) {
			rmdirSync( path.join( where.uploads, dir ) );
			removedDirs++;
		}
	}

	rmSync( where.snapshot, { recursive: true } );

	return { restoredFiles, removedFiles, removedDirs };
}

if ( process.argv[ 1 ] === fileURLToPath( import.meta.url ) ) {
	const [ command, site, database ] = process.argv.slice( 2 );

	if ( ! [ 'take', 'restore' ].includes( command ) || ! site || ! database ) {
		console.error(
			'usage: e2e-snapshot.mjs take|restore <site path> <database file>'
		);
		process.exit( 2 );
	}

	try {
		if ( 'take' === command ) {
			await take( site, database );
		} else {
			const { restoredFiles, removedFiles, removedDirs } = await restore(
				site,
				database
			);
			console.log(
				`e2e-snapshot: database restored; put back ${ restoredFiles } upload file(s) the run deleted, removed ${ removedFiles } file(s) and ${ removedDirs } directory(ies) it added.`
			);
		}
	} catch ( error ) {
		console.error( `e2e-snapshot: ${ error.message }` );
		process.exit( 1 );
	}
}
