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
 * Whether a translation preserves every placeholder its source carries.
 *
 * Order is ignored (languages reorder clauses); multiplicity is not, because a
 * dropped or duplicated token is a broken string either way.
 *
 * @param {string} source
 * @param {string} translated
 * @returns {boolean}
 */
export function placeholdersIntact( source, translated ) {
	const a = extractPlaceholders( source );

	if ( a.length === 0 ) {
		return true;
	}

	const b = extractPlaceholders( translated );

	return a.length === b.length && a.every( ( p, i ) => p === b[ i ] );
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
