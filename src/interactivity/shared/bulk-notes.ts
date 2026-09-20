/**
 * What the drop zone has to say after the page it uploaded from is gone.
 *
 * Bundled into `@aggr/upload` with the zone. Split from it because this half
 * has one job the zone does not — surviving a page load — and nothing of the
 * zone's state: it takes notes, keeps them per campaign page, and raises them
 * as toasts on the page that loads.
 */

/**
 * Where the notes for the page an upload lands on are kept until it loads.
 *
 * Per path, so a note can only be shown on the campaign it was written for.
 *
 * @param path The campaign page's path.
 * @return The storage key.
 */
function notesKey( path: string ): string {
	return `aggr-bulk-notes:${ path }`;
}

/** A notice to show once the page has moved on. */
export interface Note {
	message: string;
	level: 'info' | 'warning';
}

/**
 * Shows what the last drop left to say, now that its page has loaded.
 *
 * The upload replaces the page, and the rows that said which files were not
 * used went with it. Raised as the portal's own toasts, drawn by the save
 * module, after every module has run so its listener is there to hear it.
 */
export function showCarriedNotes(): void {
	let notes: Note[] = [];

	try {
		const key = notesKey( window.location.pathname );
		const raw = window.sessionStorage.getItem( key );

		window.sessionStorage.removeItem( key );
		notes = raw ? ( JSON.parse( raw ) as Note[] ) : [];
	} catch {
		return;
	}

	if ( ! Array.isArray( notes ) || notes.length === 0 ) {
		return;
	}

	const raise = () => {
		for ( const note of notes ) {
			document.dispatchEvent(
				new CustomEvent( 'aggr:toast', { detail: note } )
			);
		}
	};

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', raise, { once: true } );
	} else {
		window.setTimeout( raise, 0 );
	}
}

/**
 * Keeps notes for the page a round is about to load.
 *
 * @param target Where the round is going; empty for this page, reloaded.
 * @param notes  What to say there.
 */
export function carryNotes( target: string, notes: Note[] ): void {
	if ( notes.length === 0 ) {
		return;
	}

	try {
		const path =
			'' === target
				? window.location.pathname
				: new URL( target, window.location.href ).pathname;

		window.sessionStorage.setItem(
			notesKey( path ),
			JSON.stringify( notes )
		);
	} catch {
		// Storage refused: the uploads still landed, and the page says so.
	}
}
