/**
 * Shared gettext catalog helpers.
 *
 * Extracted so the machine-translation pipeline and the placeholder lint agree
 * on what a placeholder is. They previously did not: MT protected printf
 * specifiers only, so a `{percent}` token could be translated into
 * `{pourcentage}` and ship a product badge reading "Save {pourcentage}%".
 */

/**
 * Everything a translator must copy through untouched.
 *
 * Two families:
 *   printf — `%s`, `%2$d`, `%(name)s`, as used by sprintf() and its JS twin.
 *   braces — `{percent}`, `{pct}`, `{entry}`, substituted by str_replace().
 *
 * @type {RegExp}
 */
export const PLACEHOLDER_PATTERN =
	/%(\d+\$)?[sd]|%\([^)]+\)[sd]|\{[A-Za-z_][A-Za-z0-9_]*\}/g;

/**
 * Placeholders present in a string, sorted so two strings can be compared.
 *
 * @param {string} text
 * @returns {string[]}
 */
export function extractPlaceholders( text ) {
	return [ ...text.matchAll( PLACEHOLDER_PATTERN ) ]
		.map( ( m ) => m[ 0 ] )
		.sort();
}

/**
 * Whether a translation carries exactly the placeholders its source carries.
 *
 * Order is ignored (languages reorder clauses); multiplicity is not, because a
 * dropped or duplicated token is a broken string either way.
 *
 * **Both directions, and the second one is not hypothetical.** This used to
 * return `true` early whenever the source had no placeholders, on the reading
 * that a string with nothing to preserve cannot lose anything. True, and beside
 * the point: a machine translator does not only drop tokens, it invents them,
 * because it answers out of a translation memory full of other people's
 * strings. "All campaigns" came back as German carrying a `%d` borrowed from a
 * neighbouring "%d campaigns", and `%d` in a string PHP never passes an
 * argument to is a broken page, not a cosmetic problem.
 *
 * Every gate built on this function waved that through, while
 * lint-placeholders.mjs — which had always compared both directions — refused
 * it. One rule with two definitions and the upstream one weaker, so the
 * drafting workflow reliably wrote a catalog its own validator would reject and
 * failed every run on master. The lint now calls this instead of restating it.
 *
 * @param {string} source
 * @param {string} translated
 * @returns {boolean}
 */
export function placeholdersIntact( source, translated ) {
	const a = extractPlaceholders( source );
	const b = extractPlaceholders( translated );

	return a.length === b.length && a.every( ( p, i ) => p === b[ i ] );
}

/**
 * Which source string a given msgstr is a translation of.
 *
 * Plural forms translate `msgid_plural`, whose placeholders can legitimately
 * differ from the singular's — comparing every form against `msgid` reports a
 * correct catalog as broken. Defined once because both the lint and the
 * translator's write-time sweep have to make the same choice.
 *
 * @param {Record<string, unknown>} entry Parsed entry.
 * @param {string}                  key   msgstr key, e.g. `msgstr[1]`.
 * @returns {string} The source to compare against.
 */
export function placeholderSourceFor( entry, key ) {
	return 'msgstr[0]' !== key && entry.msgidPlural
		? String( entry.msgidPlural )
		: String( entry.msgid );
}

/**
 * Whether every translated form of an entry carries the right placeholders.
 *
 * Empty forms are drafts and are not judged — gettext falls back to the source
 * string for them, so a placeholder they have not got yet cannot reach a page.
 *
 * @param {Record<string, any>} entry Parsed entry.
 * @returns {boolean}
 */
export function entryPlaceholdersIntact( entry ) {
	for ( const [ key, msgstr ] of Object.entries( entry.msgstrs ?? {} ) ) {
		if ( '' === msgstr ) {
			continue;
		}

		if (
			! placeholdersIntact( placeholderSourceFor( entry, key ), msgstr )
		) {
			return false;
		}
	}

	return true;
}

/**
 * Minimal gettext PO entry parser (msgid / msgstr / fuzzy / msgctxt / plurals).
 *
 * @param {string} content
 * @returns {{ header: string, entries: Array<Record<string, unknown>> }}
 */
export function parsePo( content ) {
	const normalized = content.replace( /\r\n/g, '\n' );
	const blocks = normalized.split( /\n\n+/ );
	const entries = [];
	let header = '';

	for ( const block of blocks ) {
		if ( ! block.trim() ) {
			continue;
		}

		const lines = block.split( '\n' );
		const comments = [];
		const flags = new Set();
		let msgctxt = null;
		let msgid = null;
		let msgidPlural = null;
		/** @type {Record<string, string>} */
		const msgstrs = {};
		let current = null;

		const flushString = ( key, chunk ) => {
			if ( key === 'msgctxt' ) {
				msgctxt = ( msgctxt ?? '' ) + chunk;
			} else if ( key === 'msgid' ) {
				msgid = ( msgid ?? '' ) + chunk;
			} else if ( key === 'msgid_plural' ) {
				msgidPlural = ( msgidPlural ?? '' ) + chunk;
			} else if ( key?.startsWith( 'msgstr' ) ) {
				msgstrs[ key ] = ( msgstrs[ key ] ?? '' ) + chunk;
			}
		};

		for ( const line of lines ) {
			if ( line.startsWith( '#' ) ) {
				if ( line.startsWith( '#,' ) ) {
					line.slice( 2 )
						.split( ',' )
						.map( ( f ) => f.trim() )
						.filter( Boolean )
						.forEach( ( f ) => flags.add( f ) );
				} else {
					comments.push( line );
				}
				continue;
			}

			const quoted = line.match( /^"(.*)"$/ );
			if ( quoted && current ) {
				flushString( current, quoted[ 1 ] );
				continue;
			}

			const m = line.match(
				/^(msgctxt|msgid_plural|msgid|msgstr(?:\[\d+\])?)\s+"(.*)"\s*$/
			);
			if ( m ) {
				current = m[ 1 ];
				flushString( current, m[ 2 ] );
				continue;
			}
		}

		if ( msgid === null ) {
			/*
			 * An obsolete block, kept verbatim.
			 *
			 * `msgmerge` comments out a string the source no longer has, so
			 * every line begins `#~` and none of them parses as a msgid — and
			 * this loop used to drop the block entirely, so the next write
			 * deleted the translation. That is the opposite of why msgmerge
			 * keeps it: restoring a reverted string must not cost its German.
			 * Never re-parsed and never offered to a translator; written back
			 * exactly as it came in.
			 */
			if ( lines.some( ( line ) => line.startsWith( '#~' ) ) ) {
				entries.push( {
					obsolete: true,
					raw: block,
					comments,
					flags: new Set(),
					msgctxt: null,
					msgid: null,
					msgidPlural: null,
					msgstrs: {},
				} );
			}

			continue;
		}

		const unescaped = ( s ) =>
			s
				.replace( /\\n/g, '\n' )
				.replace( /\\t/g, '\t' )
				.replace( /\\"/g, '"' )
				.replace( /\\\\/g, '\\' );

		const entry = {
			raw: block,
			comments,
			flags,
			msgctxt: msgctxt === null ? null : unescaped( msgctxt ),
			msgid: unescaped( msgid ),
			msgidPlural: msgidPlural === null ? null : unescaped( msgidPlural ),
			msgstrs: Object.fromEntries(
				Object.entries( msgstrs ).map( ( [ k, v ] ) => [
					k,
					unescaped( v ),
				] )
			),
		};

		if ( entry.msgid === '' && ! entry.msgctxt ) {
			header = block;
			continue;
		}

		entries.push( entry );
	}

	return { header, entries };
}
