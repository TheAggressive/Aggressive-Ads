#!/usr/bin/env node
/**
 * Resume machine-translation progress without letting a stale draft overwrite
 * newer reviewed translations on master.
 *
 * The workflow keeps its in-progress catalogs on `i18n/mt-drafts`. A later run
 * starts from the current master catalog and imports only saved `aggr-mt`
 * entries where master is still empty or fuzzy. Current references, comments,
 * and clean human translations therefore stay authoritative.
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

import { parsePo, placeholdersIntact } from './po.mjs';

function needsTranslation( entry ) {
	const isFuzzy = entry.flags.has( 'fuzzy' );

	if ( entry.msgidPlural !== null ) {
		const values = Object.values( entry.msgstrs );
		const empty =
			values.length === 0 || values.some( ( value ) => value === '' );

		return empty || isFuzzy;
	}

	return ( entry.msgstrs.msgstr ?? '' ) === '' || isFuzzy;
}

function entryKey( entry ) {
	return `${ entry.msgctxt ?? '' }\u0004${ entry.msgid }`;
}

function hasRestorableMachineTranslation( entry ) {
	if ( ! entry.flags.has( 'aggr-mt' ) ) {
		return false;
	}

	if ( entry.msgidPlural !== null ) {
		const singular = entry.msgstrs[ 'msgstr[0]' ] ?? '';
		const plural = entry.msgstrs[ 'msgstr[1]' ] ?? '';

		return (
			Boolean( singular ) &&
			Boolean( plural ) &&
			placeholdersIntact( entry.msgid, singular ) &&
			placeholdersIntact( entry.msgidPlural, plural )
		);
	}

	const translated = entry.msgstrs.msgstr ?? '';

	return (
		Boolean( translated ) && placeholdersIntact( entry.msgid, translated )
	);
}

function escapePo( str ) {
	return str
		.replace( /\\/g, '\\\\' )
		.replace( /"/g, '\\"' )
		.replace( /\t/g, '\\t' )
		.replace( /\n/g, '\\n' );
}

function formatPoString( keyword, value ) {
	if ( ! value.includes( '\n' ) ) {
		return `${ keyword } "${ escapePo( value ) }"`;
	}

	const parts = value.split( '\n' );
	const lines = [ `${ keyword } ""` ];

	for ( let i = 0; i < parts.length; i++ ) {
		const piece = parts[ i ] + ( i < parts.length - 1 ? '\n' : '' );

		if ( piece.length ) {
			lines.push( `"${ escapePo( piece ) }"` );
		}
	}

	return lines.join( '\n' );
}

function serializeEntry( entry ) {
	// Kept verbatim, for the reason po.mjs records: a commented-out string is
	// a translation waiting for its source to come back.
	if ( entry.obsolete ) {
		return String( entry.raw ).trimEnd();
	}

	const translator = [];
	const extracted = [];
	const references = [];
	const previous = [];
	const other = [];

	for ( const comment of entry.comments ) {
		if ( comment.startsWith( '#.' ) ) {
			extracted.push( comment );
		} else if ( comment.startsWith( '#:' ) ) {
			references.push( comment );
		} else if ( comment.startsWith( '#|' ) ) {
			previous.push( comment );
		} else if ( comment.startsWith( '# ' ) || comment === '#' ) {
			translator.push( comment );
		} else {
			other.push( comment );
		}
	}

	const lines = [ ...translator, ...extracted, ...references, ...other ];

	if ( entry.flags.size ) {
		lines.push( `#, ${ [ ...entry.flags ].join( ', ' ) }` );
	}

	lines.push( ...previous );

	if ( entry.msgctxt !== null ) {
		lines.push( formatPoString( 'msgctxt', entry.msgctxt ) );
	}

	lines.push( formatPoString( 'msgid', entry.msgid ) );

	if ( entry.msgidPlural !== null ) {
		lines.push( formatPoString( 'msgid_plural', entry.msgidPlural ) );

		const keys = Object.keys( entry.msgstrs )
			.filter( ( key ) => key.startsWith( 'msgstr[' ) )
			.sort();

		for ( const key of keys ) {
			lines.push( formatPoString( key, entry.msgstrs[ key ] ?? '' ) );
		}
	} else {
		lines.push( formatPoString( 'msgstr', entry.msgstrs.msgstr ?? '' ) );
	}

	return lines.join( '\n' );
}

function serializePo( header, entries ) {
	const chunks = [];

	if ( header ) {
		chunks.push( header.trimEnd() );
	}

	for ( const entry of entries ) {
		if ( entry.obsolete ) {
			continue;
		}

		chunks.push( serializeEntry( entry ) );
	}

	return `${ chunks.join( '\n\n' ) }\n`;
}

/**
 * Import saved machine translations into the current catalog.
 *
 * @param {string} baseContent  Current master catalog.
 * @param {string} draftContent Saved draft-branch catalog.
 * @return {{content: string, restored: number}}
 */
export function resumeCatalog( baseContent, draftContent ) {
	const base = parsePo( baseContent );
	const draft = parsePo( draftContent );
	const draftByKey = new Map(
		draft.entries
			.filter( ( entry ) => ! entry.obsolete )
			.map( ( entry ) => [ entryKey( entry ), entry ] )
	);
	let restored = 0;

	for ( const entry of base.entries ) {
		if ( ! needsTranslation( entry ) ) {
			continue;
		}

		const saved = draftByKey.get( entryKey( entry ) );

		if ( ! saved || ! hasRestorableMachineTranslation( saved ) ) {
			continue;
		}

		entry.msgstrs = { ...saved.msgstrs };
		entry.flags.delete( 'fuzzy' );
		entry.flags.add( 'aggr-mt' );
		entry.comments = entry.comments.filter(
			( comment ) => ! comment.startsWith( '#|' )
		);

		for ( const comment of saved.comments.filter( ( value ) =>
			value.includes( 'Auto-translated (aggr-mt)' )
		) ) {
			if ( ! entry.comments.includes( comment ) ) {
				entry.comments.push( comment );
			}
		}

		restored += 1;
	}

	return {
		content: serializePo( base.header, base.entries ),
		restored,
	};
}

function main() {
	const [ baseFile, draftFile ] = process.argv.slice( 2 );

	if ( ! baseFile || ! draftFile ) {
		console.error(
			'Usage: node bin/i18n/resume-progress.mjs <base.po> <saved-draft.po>'
		);
		process.exit( 2 );
	}

	const result = resumeCatalog(
		fs.readFileSync( baseFile, 'utf8' ),
		fs.readFileSync( draftFile, 'utf8' )
	);

	if ( result.restored > 0 ) {
		fs.writeFileSync( baseFile, result.content, 'utf8' );
	}

	console.log(
		`i18n:resume: ${ path.basename( baseFile ) }: restored=${
			result.restored
		}`
	);
}

if (
	process.argv[ 1 ] &&
	path.resolve( process.argv[ 1 ] ) === fileURLToPath( import.meta.url )
) {
	main();
}
