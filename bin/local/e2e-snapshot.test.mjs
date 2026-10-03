/**
 * Tests for the snapshot the Studio runner puts a site back from.
 *
 * It deletes files, so the half that matters most is what it must not touch:
 * uploads that were there before, anything outside uploads, and whatever a
 * link inside uploads points at. Each is asserted by count against a real
 * SQLite file and a real directory tree — the restore is the production code,
 * not a copy of it.
 */

import { strict as assert } from 'node:assert';
import {
	existsSync,
	mkdirSync,
	mkdtempSync,
	readdirSync,
	readFileSync,
	rmSync,
	symlinkSync,
	writeFileSync,
} from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { DatabaseSync } from 'node:sqlite';
import test, { after } from 'node:test';

import { SNAPSHOT_DIR, restore, take } from './e2e-snapshot.mjs';

const roots = [];

after( () => {
	for ( const root of roots ) {
		rmSync( root, { recursive: true, force: true } );
	}
} );

/** A site with a database holding two posts and two uploads. */
function site() {
	const root = mkdtempSync( path.join( tmpdir(), 'aggr-snapshot-' ) );
	roots.push( root );

	const uploads = path.join( root, 'wp-content', 'uploads' );
	mkdirSync( path.join( uploads, '2026', '09' ), { recursive: true } );
	mkdirSync( path.join( uploads, 'ads-uploads' ) );
	writeFileSync( path.join( uploads, '2026', '09', 'kept.png' ), 'kept' );
	writeFileSync( path.join( uploads, 'ads-uploads', 'kept.png' ), 'kept' );
	writeFileSync( path.join( root, 'outside.txt' ), 'outside' );

	const database = path.join( root, 'wp-content', 'database.sqlite' );
	const db = new DatabaseSync( database );
	db.exec(
		'CREATE TABLE wp_posts (ID INTEGER PRIMARY KEY, post_title TEXT)'
	);
	db.exec(
		"INSERT INTO wp_posts (post_title) VALUES ('real'), ('also real')"
	);
	db.exec( 'CREATE TABLE wp_options (option_name TEXT, option_value TEXT)' );
	db.exec( "INSERT INTO wp_options VALUES ('stylesheet', 'laao')" );
	db.close();

	return { root, uploads, database };
}

/** What a run does: rows added and changed, files added and deleted. */
function run( { root, uploads, database } ) {
	const db = new DatabaseSync( database );
	db.exec(
		"INSERT INTO wp_posts (post_title) VALUES ('fixture'), ('fixture')"
	);
	db.exec( "UPDATE wp_options SET option_value = 'twentytwentyfive'" );
	db.close();

	mkdirSync( path.join( uploads, '2026', '10', 'nested' ), {
		recursive: true,
	} );
	writeFileSync(
		path.join( uploads, '2026', '10', 'nested', 'new.png' ),
		'new'
	);
	writeFileSync( path.join( uploads, 'ads-uploads', 'new-1.png' ), 'new' );
	writeFileSync( path.join( uploads, 'ads-uploads', 'new-2.png' ), 'new' );

	// A seed deleting an earlier run's creative takes its private file too.
	rmSync( path.join( uploads, 'ads-uploads', 'kept.png' ) );

	// A link out of uploads, to a directory that must survive the restore.
	mkdirSync( path.join( root, 'elsewhere' ) );
	writeFileSync( path.join( root, 'elsewhere', 'precious.txt' ), 'precious' );
	symlinkSync( path.join( root, 'elsewhere' ), path.join( uploads, 'link' ) );
}

function query( database, sql ) {
	const db = new DatabaseSync( database, { readOnly: true } );

	try {
		return db.prepare( sql ).all();
	} finally {
		db.close();
	}
}

test( 'restore puts the database back as it was taken', async () => {
	const fixture = site();
	await take( fixture.root, fixture.database );
	run( fixture );

	assert.equal(
		query( fixture.database, 'SELECT * FROM wp_posts' ).length,
		4
	);

	await restore( fixture.root, fixture.database );

	assert.deepEqual(
		query(
			fixture.database,
			'SELECT post_title FROM wp_posts ORDER BY ID'
		).map( ( row ) => row.post_title ),
		[ 'real', 'also real' ]
	);
	assert.equal(
		query( fixture.database, 'SELECT option_value FROM wp_options' )[ 0 ]
			.option_value,
		'laao'
	);
} );

test( 'restore removes what the run uploaded and nothing that was there', async () => {
	const fixture = site();
	await take( fixture.root, fixture.database );
	run( fixture );

	const result = await restore( fixture.root, fixture.database );

	// The deleted one back; three new files and the link; 2026/10 and
	// 2026/10/nested.
	assert.deepEqual( result, {
		restoredFiles: 1,
		removedFiles: 4,
		removedDirs: 2,
	} );

	assert.ok(
		existsSync( path.join( fixture.uploads, '2026', '09', 'kept.png' ) )
	);
	assert.equal(
		readFileSync(
			path.join( fixture.uploads, 'ads-uploads', 'kept.png' ),
			'utf8'
		),
		'kept'
	);
	assert.deepEqual( readdirSync( fixture.uploads ).sort(), [
		'2026',
		'ads-uploads',
	] );
	assert.deepEqual( readdirSync( path.join( fixture.uploads, '2026' ) ), [
		'09',
	] );
	assert.deepEqual(
		readdirSync( path.join( fixture.uploads, 'ads-uploads' ) ),
		[ 'kept.png' ]
	);

	// Outside uploads, and behind the link: untouched.
	assert.ok( existsSync( path.join( fixture.root, 'outside.txt' ) ) );
	assert.ok(
		existsSync( path.join( fixture.root, 'elsewhere', 'precious.txt' ) )
	);
} );

test( 'a successful restore drops the snapshot, so the next run takes a fresh one', async () => {
	const fixture = site();
	await take( fixture.root, fixture.database );
	await restore( fixture.root, fixture.database );

	assert.equal(
		existsSync( path.join( fixture.root, SNAPSHOT_DIR ) ),
		false
	);
	await take( fixture.root, fixture.database );
} );

test( 'take refuses to replace a snapshot a dead run left', async () => {
	const fixture = site();
	await take( fixture.root, fixture.database );
	run( fixture );

	// The run died here. Its leftovers must not become the next baseline.
	await assert.rejects(
		take( fixture.root, fixture.database ),
		/did not restore/
	);

	await restore( fixture.root, fixture.database );
	assert.equal(
		query( fixture.database, 'SELECT * FROM wp_posts' ).length,
		2
	);
} );

test( 'restore without a complete snapshot deletes nothing', async () => {
	const fixture = site();
	run( fixture );

	await assert.rejects(
		restore( fixture.root, fixture.database ),
		/missing or incomplete/
	);

	// A snapshot directory with a database and no upload list is incomplete.
	mkdirSync( path.join( fixture.root, SNAPSHOT_DIR ) );
	writeFileSync(
		path.join( fixture.root, SNAPSHOT_DIR, 'database.sqlite' ),
		''
	);
	await assert.rejects(
		restore( fixture.root, fixture.database ),
		/missing or incomplete/
	);

	assert.equal(
		query( fixture.database, 'SELECT * FROM wp_posts' ).length,
		4
	);
	// Two added, one deleted, and nothing put back.
	assert.equal(
		readdirSync( path.join( fixture.uploads, 'ads-uploads' ) ).length,
		2
	);
} );
