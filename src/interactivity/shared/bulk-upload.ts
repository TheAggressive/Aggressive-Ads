/**
 * The drop zone: every file at once, each sent to the size it matches.
 *
 * Bundled into `@aggr/upload`, beside the per-size forms it drives.
 *
 * **There is no second upload route.** Each file goes through the form of the
 * placement it matched — that form's own fields, nonce and `admin-post.php`
 * handler — with the file set on the body. The server cannot tell a dropped
 * file from one chosen on the card, which is the point: every check an upload
 * passes is the one it always passed.
 *
 * The zone is script-only. Without script it is hidden and each size's own
 * form is the way to upload, exactly as before it existed.
 */

import {
	checkCreativeFile,
	matchFilesToSizes,
	nearMiss,
	normaliseLink,
	parsePixelSize,
	type FileMatch,
	type SizeTarget,
} from '@aggr/logic';
import { actionOf, sendBody } from './upload-send';
import { navigateSameOrigin } from '../../admin/shared/navigate';

/** What the zone needs from the upload store's hydrated state. */
export interface BulkUploadState {
	expectedSize: string;
	maxBytes: number;
	maxPixels: number;
	allowedMime: string[];
	sizeMessage: string;
	name?: string;
	open?: boolean;
}

export interface BulkDeps {
	uploads: () => Record< string, BulkUploadState >;
	i18n: () => Partial< Record< string, string > >;
	readImageSize: (
		file: File
	) => Promise< { width: number; height: number } >;
}

/** Every file that arrived, and what became of it. */
interface Entry {
	file: File;
	width: number;
	height: number;
	row: HTMLLIElement;
	status: HTMLElement;
	match: FileMatch | null;

	// Where it goes: one placement, or all of them when it had to ask.
	chosen: string[] | null;

	// Of those, the ones not sent yet.
	pending: string[];
	queued: boolean;

	// Why a file that nearly fits a waiting size was not used; empty otherwise.
	warning: string;
}

/** Everything sent this round, so the page moves on once, at the end. */
interface Round {
	sent: number;
	landed: string;
	refused: string;
	failed: string[];

	// Answered, but not with anything this could read: the server has it.
	unknown: number;
	busy: boolean;

	// Files are in, the campaign's link is not: held until it is.
	waitingForLink: boolean;
}

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
interface Note {
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

function formFor( id: string ): HTMLFormElement | null {
	const form = document.querySelector(
		`#aggr-uploads form[data-aggr-upload="${ CSS.escape( id ) }"]`
	);

	return form instanceof HTMLFormElement ? form : null;
}

function format( template: string | undefined, ...values: string[] ): string {
	let index = 0;

	return ( template ?? '' )
		.replace(
			/%(\d)\$[sd]/g,
			( _, n: string ) => values[ Number( n ) - 1 ] ?? ''
		)
		.replace( /%[sd]/g, () => values[ index++ ] ?? '' );
}

/**
 * The placements a file can be matched against.
 *
 * Only a size with a form on the card counts as open, whatever the state
 * says: the form is what the file is sent through, and a size without one
 * has nowhere to send it.
 *
 * @param uploads The store's hydrated placements.
 * @return One target per placement with a readable size.
 */
function targetsFrom(
	uploads: Record< string, BulkUploadState >
): SizeTarget[] {
	const targets: SizeTarget[] = [];

	for ( const [ id, upload ] of Object.entries( uploads ) ) {
		const size = parsePixelSize( upload.expectedSize );

		if ( size !== null ) {
			targets.push( {
				id,
				width: size.width,
				height: size.height,
				open: true === upload.open && formFor( id ) !== null,
			} );
		}
	}

	return targets;
}

/**
 * Wires the zone: its drop target, its file input and the list it reports in.
 *
 * @param zone The `[data-aggr-bulk]` element.
 * @param deps The upload store's state, read when needed rather than copied.
 */
export function wireBulkUpload( zone: HTMLElement, deps: BulkDeps ): void {
	const input = zone.querySelector< HTMLInputElement >(
		'input[type="file"][data-aggr-bulk-input]'
	);
	const browse = zone.querySelector< HTMLButtonElement >(
		'[data-aggr-bulk-browse]'
	);
	const list = zone.querySelector< HTMLUListElement >(
		'[data-aggr-bulk-list]'
	);
	const status = zone.querySelector< HTMLElement >(
		'[data-aggr-bulk-status]'
	);

	if ( ! input || ! browse || ! list || ! status ) {
		return;
	}

	const entries: Entry[] = [];
	const round: Round = {
		sent: 0,
		landed: '',
		refused: '',
		failed: [],
		unknown: 0,
		busy: false,
		waitingForLink: false,
	};

	// Files that are not images at all. Not used, like the rest, and said once.
	const unreadable: string[] = [];
	const t = ( key: string ): string | undefined => deps.i18n()[ key ];
	const say = ( message: string, tone: 'note' | 'error' = 'note' ) => {
		status.textContent = message;
		status.classList.toggle( 'is-error', 'error' === tone );
	};

	// Leaving mid-round drops files on the floor; the browser asks first.
	const guard = ( event: BeforeUnloadEvent ): void => {
		event.preventDefault();
	};

	const nameOf = ( id: string ): string => {
		const upload = deps.uploads()[ id ];
		const size = parsePixelSize( upload?.expectedSize ?? '' );

		return (
			upload?.name || ( size ? `${ size.width } × ${ size.height }` : id )
		);
	};

	const campaignLink = (): HTMLInputElement | null => {
		const link = document.getElementById( 'aggr-campaign-link' );

		return link instanceof HTMLInputElement ? link : null;
	};

	/**
	 * Whether a size's form has a destination the server will accept,
	 * taking the campaign's link when the card has none of its own.
	 *
	 * The campaign's field is copied into the cards as it is typed, so a
	 * card holds `example.com` while the campaign field is still being
	 * written — valid to a person, not to a URL input. Normalised here the
	 * way the campaign field itself is saved, and only into a card that is
	 * empty or still following the campaign's link: a card given a link of
	 * its own keeps it.
	 */
	const readyUrl = ( form: HTMLFormElement ): boolean => {
		const url = form.querySelector< HTMLInputElement >(
			'input[name="click_url"]'
		);

		if ( ! url ) {
			return false;
		}

		const link = campaignLink();
		const typed = link?.value.trim() ?? '';
		const own = url.value.trim();

		const normal = normaliseLink( typed );

		/*
		 * Compared normalised on both sides: the campaign field may already
		 * have been tidied to `https://…` by its own save while the card still
		 * holds the text as typed, and those are the same link.
		 */
		if ( normal && ( '' === own || normaliseLink( own ) === normal ) ) {
			url.value = normal;
		}

		return '' !== url.value.trim() && url.checkValidity();
	};

	/**
	 * Picks the round back up once the link it was waiting for is there.
	 *
	 * On commit rather than on each keystroke, for the reason the cards
	 * give: `https://exa` is already a valid URL.
	 */
	const resume = (): void => {
		if ( ! round.waitingForLink ) {
			return;
		}

		round.waitingForLink = false;
		window.removeEventListener( 'beforeunload', guard );
		say( '' );
		pump();
	};

	campaignLink()?.addEventListener( 'change', resume );
	campaignLink()?.addEventListener( 'blur', resume );

	/*
	 * Said before anything is dropped, while there is no link: the files
	 * will wait for it, and knowing that up front is what stops the drop
	 * looking stuck.
	 */
	const linkHint = zone.querySelector< HTMLElement >(
		'[data-aggr-bulk-link-hint]'
	);

	campaignLink()?.addEventListener( 'input', () => {
		if ( linkHint ) {
			linkHint.hidden = '' !== ( campaignLink()?.value.trim() ?? '' );
		}
	} );

	/**
	 * Re-matches everything, then shows and queues what the matches say.
	 *
	 * Re-run on every change, because an answer to one "choose" can decide
	 * another: with two open 300×250 placements and two such files, sending
	 * the first to Header leaves the second only Sidebar.
	 */
	const refresh = (): void => {
		const targets = targetsFrom( deps.uploads() );
		const claimed = new Set< string >();

		for ( const entry of entries ) {
			if ( entry.queued ) {
				( entry.chosen ?? [] ).forEach( ( id ) => claimed.add( id ) );
			}
		}

		const waiting = entries.filter( ( entry ) => ! entry.queued );
		const matches = matchFilesToSizes( waiting, targets, claimed );

		waiting.forEach( ( entry, index ) => {
			entry.match = matches[ index ] ?? null;
			show( entry, targets );
		} );

		pump();
	};

	const show = ( entry: Entry, targets: SizeTarget[] ): void => {
		const match = entry.match;
		entry.row.querySelector( 'select' )?.parentElement?.remove();

		if ( match === null ) {
			return;
		}

		/*
		 * **No size for it: left out, not refused.** A folder of artwork
		 * holds sizes for other campaigns, and a red row for each was a page
		 * of errors about files nobody meant to use here. They are named once,
		 * together, at the end.
		 *
		 * The exception is a file that nearly fits a size still waiting — an
		 * @2x export, a pixel off. That size would otherwise stay empty while
		 * the advertiser thinks it is done, so it is said here and carried.
		 */
		if ( 'none' === match.kind || 'taken' === match.kind ) {
			const near = nearMiss( entry, targets );
			const dims = `${ entry.width } × ${ entry.height }`;
			const wanted = near
				? deps.uploads()[ near.target ]?.expectedSize ?? ''
				: '';
			const size = parsePixelSize( wanted );
			const needs = size ? `${ size.width } × ${ size.height }` : wanted;

			entry.warning = near
				? format(
						'scaled' === near.kind
							? t( 'bulkScaled' )
							: t( 'bulkOff' ),
						entry.file.name,
						dims,
						nameOf( near.target ),
						needs,
						'scaled' === near.kind ? String( near.factor ) : ''
				  )
				: '';
			entry.row.hidden = '' === entry.warning;
			entry.row.classList.toggle( 'is-error', '' !== entry.warning );
			entry.status.textContent = entry.warning;
			return;
		}

		entry.warning = '';
		entry.row.hidden = false;
		entry.row.classList.remove( 'is-error' );

		if ( 'one' === match.kind ) {
			entry.chosen = [ match.target ];
			entry.status.textContent = format(
				t( 'bulkGoesTo' ),
				nameOf( match.target )
			);
			return;
		}

		entry.status.textContent = t( 'bulkChoose' ) ?? '';
		entry.row.append( chooser( entry, match.targets ) );
	};

	/**
	 * Asks where a file goes when more than one placement is its size.
	 *
	 * "All of them" is the shared-file case: one design for a header and a
	 * break of the same size. Each placement still gets a creative of its own.
	 */
	const chooser = ( entry: Entry, ids: string[] ): HTMLElement => {
		const wrap = document.createElement( 'span' );
		const label = document.createElement( 'label' );
		const select = document.createElement( 'select' );
		const selectId = `aggr-bulk-choice-${ entries.indexOf( entry ) }`;

		label.className = 'aggr-sr';
		label.htmlFor = selectId;
		label.textContent = format( t( 'bulkChooseLabel' ), entry.file.name );
		select.id = selectId;

		const options: Array< [ string, string ] > = [
			[ '', t( 'bulkPick' ) ?? '' ],
			...ids.map( ( id ): [ string, string ] => [ id, nameOf( id ) ] ),
			[ ids.join( ',' ), t( 'bulkAll' ) ?? '' ],
		];

		for ( const [ value, text ] of options ) {
			const option = document.createElement( 'option' );

			option.value = value;
			option.textContent = text;
			select.add( option );
		}

		select.addEventListener( 'change', () => {
			if ( '' === select.value ) {
				return;
			}

			entry.chosen = select.value.split( ',' );
			entry.pending = [ ...entry.chosen ];
			entry.queued = true;
			entry.status.textContent = t( 'bulkWaiting' ) ?? '';
			wrap.remove();
			refresh();
		} );

		wrap.append( label, select );

		return wrap;
	};

	/** Sends the next queued file, one at a time, in the order they came. */
	const pump = (): void => {
		if ( round.busy ) {
			return;
		}

		for ( const entry of entries ) {
			if ( ! entry.queued && entry.match?.kind === 'one' ) {
				entry.queued = true;
				entry.pending = [ ...( entry.chosen ?? [] ) ];
			}
		}

		const next = entries.find(
			( entry ) => entry.queued && entry.pending.length > 0
		);

		if ( ! next ) {
			finish();
			return;
		}

		const id = next.pending.shift() as string;
		const form = formFor( id );
		const upload = deps.uploads()[ id ];

		if ( ! form || ! upload ) {
			refresh();
			return;
		}

		const refusal = checkFor( next, upload );

		if ( '' !== refusal ) {
			next.pending = [];
			next.status.textContent = refusal;
			next.row.classList.add( 'is-error' );
			pump();
			return;
		}

		/*
		 * **Held, not dropped.** No link yet means every file would be
		 * refused for the same reason, and throwing them away made the
		 * advertiser drag them in a second time after typing it. They wait,
		 * listed, and go by themselves once the link is committed.
		 */
		if ( ! readyUrl( form ) ) {
			next.pending.unshift( id );

			for ( const entry of entries ) {
				if ( entry.queued && entry.pending.length > 0 ) {
					entry.status.textContent = format(
						t( 'bulkWaitingLink' ),
						entry.pending.map( nameOf ).join( ', ' )
					);
				}
			}

			if ( ! round.waitingForLink ) {
				round.waitingForLink = true;
				window.addEventListener( 'beforeunload', guard );
				say( t( 'bulkNeedsUrl' ) ?? '' );
				campaignLink()?.focus();
			}

			return;
		}

		const body = new FormData( form );
		body.set( 'file', next.file, next.file.name );
		body.set( 'aggr_async', '1' );

		round.busy = true;
		window.addEventListener( 'beforeunload', guard );

		const bar = document.createElement( 'progress' );
		bar.max = 100;
		bar.value = 0;
		bar.className = 'aggr-dropzone__progress';
		bar.setAttribute( 'aria-label', next.file.name );
		next.row.append( bar );
		next.status.textContent = format(
			t( 'uploadingFile' ) ?? t( 'uploading' ),
			next.file.name
		);

		type Outcome = 'sent' | 'refused' | 'unknown' | 'lost';

		const settle = ( outcome: Outcome, message: string, redirect = '' ) => {
			round.busy = false;
			window.removeEventListener( 'beforeunload', guard );
			bar.remove();
			next.status.textContent = message;
			next.row.classList.toggle(
				'is-error',
				'refused' === outcome || 'lost' === outcome
			);

			if ( 'sent' === outcome ) {
				round.sent++;
				round.landed = redirect || round.landed;
			} else if ( 'refused' === outcome ) {
				round.refused = round.refused || redirect;
			} else if ( 'unknown' === outcome ) {
				round.unknown++;
			} else {
				round.failed.push( next.file.name );
			}

			pump();
		};

		const handle = sendBody( actionOf( form ), body, {
			progress: ( percent ) => {
				bar.value = percent;
			},
			done: ( _landedAt, text ) => {
				let answer: unknown = null;

				try {
					answer = JSON.parse( text );
				} catch {
					// Handled below with every other unreadable answer.
				}

				const read =
					typeof answer === 'object' && answer !== null
						? ( answer as { ok?: unknown; redirect?: unknown } )
						: {};
				const redirect =
					typeof read.redirect === 'string' ? read.redirect : '';

				/*
				 * **An answer is not a lost upload.** Anything that arrived
				 * back reached the server, which may well have saved the
				 * file — so an unreadable one (a notice printed ahead of the
				 * JSON, a login page) is "sent, outcome unknown", and the
				 * page the round ends on is the server's, which knows.
				 *
				 * It used to count as never sent. The round stopped on
				 * stale cards with every row saying "Not uploaded", the
				 * advertiser dropped the files again, and each size ended
				 * up with two copies of one ad.
				 */
				if ( true === read.ok ) {
					settle( 'sent', t( 'bulkSent' ) ?? '', redirect );
				} else if ( false === read.ok && '' !== redirect ) {
					settle( 'refused', t( 'bulkRefused' ) ?? '', redirect );
				} else {
					settle( 'unknown', t( 'bulkSentUnknown' ) ?? '' );
				}
			},
			fail: ( reason ) => {
				settle(
					'lost',
					( {
						cancelled: t( 'uploadCancelled' ),
						timeout: t( 'uploadTimedOut' ),
						error: t( 'uploadFailed' ),
					}[ reason ] ?? '' ) as string
				);
			},
		} );

		if ( null === handle ) {
			// No readable progress in this browser, and no way to send a file
			// that is not in a form: the cards' own forms still work.
			settle( 'lost', t( 'uploadFailed' ) ?? '' );
		}
	};

	/**
	 * Once nothing is sending or queued, shows the result the server rendered.
	 *
	 * A refusal first: its page names the field and reopens the card, and the
	 * files that did land are on that page too. Waits while any file still has
	 * a question on it, since answering it is part of this round.
	 */
	const finish = (): void => {
		const asking = entries.some(
			( entry ) => ! entry.queued && entry.match?.kind === 'choose'
		);

		if ( round.busy || asking || round.waitingForLink ) {
			return;
		}

		const notes = notesFor();
		const reached =
			round.sent + round.unknown + ( '' === round.refused ? 0 : 1 );

		// Nothing reached the server, so there is nothing to reload for.
		if ( 0 === reached ) {
			if ( round.failed.length > 0 ) {
				// Only lost files: the rows say which, and a new drop retries.
				say( t( 'bulkPartial' ) ?? '', 'error' );
			} else {
				const unused = notes.find( ( note ) => 'info' === note.level );

				say( unused?.message ?? '' );
			}

			return;
		}

		/*
		 * Something reached the server, so the page is stale whatever else
		 * happened, and staying on it is how files get dropped twice. Files
		 * that were lost on the way are named on the page that loads, so
		 * those, and only those, are added again.
		 */
		if ( round.failed.length > 0 ) {
			notes.unshift( {
				message: format( t( 'bulkLost' ), round.failed.join( ', ' ) ),
				level: 'warning',
			} );
		}

		const target = '' !== round.refused ? round.refused : round.landed;

		if ( round.sent > 0 ) {
			say( format( t( 'bulkDone' ), String( round.sent ) ) );
		}

		try {
			const path =
				'' === target
					? window.location.pathname
					: new URL( target, window.location.href ).pathname;

			if ( notes.length > 0 ) {
				window.sessionStorage.setItem(
					notesKey( path ),
					JSON.stringify( notes )
				);
			}
		} catch {
			// Storage refused: the uploads still landed, and the page says so.
		}

		// No address to follow means this page, loaded again: it is stale.
		if ( ! navigateSameOrigin( target || window.location.href ) ) {
			window.location.reload();
		}
	};

	/**
	 * What is left to say once the round is over: a warning for each file
	 * that nearly fit, and one line naming every file that was not used.
	 */
	const notesFor = (): Note[] => {
		const notes: Note[] = entries
			.filter( ( entry ) => '' !== entry.warning )
			.map( ( entry ) => ( {
				message: entry.warning,
				level: 'warning' as const,
			} ) );
		const unused = [
			...entries
				.filter(
					( entry ) =>
						'' === entry.warning &&
						( entry.match?.kind === 'none' ||
							entry.match?.kind === 'taken' )
				)
				.map( ( entry ) => entry.file.name ),
			...unreadable,
		];

		if ( unused.length > 0 ) {
			notes.push( {
				message: format(
					1 === unused.length
						? t( 'bulkUnusedOne' )
						: t( 'bulkUnusedMany' ),
					unused.join( ', ' )
				),
				level: 'info',
			} );
		}

		return notes;
	};

	const checkFor = ( entry: Entry, upload: BulkUploadState ): string => {
		const expected = parsePixelSize( upload.expectedSize );

		if ( expected === null ) {
			return t( 'dimensions' ) ?? '';
		}

		const result = checkCreativeFile( {
			mime: entry.file.type,
			bytes: entry.file.size,
			width: entry.width,
			height: entry.height,
			expectedWidth: expected.width,
			expectedHeight: expected.height,
			maxBytes: upload.maxBytes,
			maxPixels: upload.maxPixels,
			allowedMime: upload.allowedMime,
		} );

		if ( result.ok ) {
			return '';
		}

		return 'size' === result.code
			? upload.sizeMessage
			: t( result.code ) ?? '';
	};

	const accept = async ( files: File[] ) => {
		if ( files.length === 0 ) {
			return;
		}

		/*
		 * A new drop is a new answer. Files the last one left out, or warned
		 * about, have been seen; carrying them into this round's summary would
		 * name them again under files that have nothing to do with them.
		 */
		for ( let i = entries.length - 1; i >= 0; i-- ) {
			const entry = entries[ i ];

			if ( entry && ! entry.queued && entry.match?.kind !== 'choose' ) {
				entry.row.remove();
				entries.splice( i, 1 );
			}
		}

		unreadable.length = 0;
		say( '' );
		list.hidden = false;

		for ( const file of files ) {
			const row = document.createElement( 'li' );
			const name = document.createElement( 'span' );
			const line = document.createElement( 'span' );

			row.className = 'aggr-dropzone__item';
			name.className = 'aggr-dropzone__name';
			name.textContent = file.name;
			line.className = 'aggr-dropzone__status';
			row.append( name, line );
			list.append( row );

			try {
				const size = await deps.readImageSize( file );

				entries.push( {
					file,
					width: size.width,
					height: size.height,
					row,
					status: line,
					match: null,
					chosen: null,
					pending: [],
					queued: false,
					warning: '',
				} );
			} catch {
				// Not an image: not used, named with the others at the end.
				row.remove();
				unreadable.push( file.name );
			}
		}

		refresh();
	};

	browse.addEventListener( 'click', () => input.click() );
	input.addEventListener( 'change', () => {
		// Copied out first: clearing the input, so the same file can be
		// chosen again after a refusal, empties the list it came in.
		const chosen = Array.from( input.files ?? [] );

		input.value = '';
		void accept( chosen );
	} );

	zone.addEventListener( 'dragover', ( event ) => {
		event.preventDefault();
		zone.classList.add( 'is-drop-target' );
	} );
	zone.addEventListener( 'dragleave', () => {
		zone.classList.remove( 'is-drop-target' );
	} );
	zone.addEventListener( 'drop', ( event ) => {
		event.preventDefault();
		zone.classList.remove( 'is-drop-target' );
		void accept( Array.from( event.dataTransfer?.files ?? [] ) );
	} );
}
