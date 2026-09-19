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
	openSizes,
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
}

/** Everything sent this round, so the page moves on once, at the end. */
interface Round {
	sent: number;
	landed: string;
	refused: string;
	failed: number;
	busy: boolean;
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
		failed: 0,
		busy: false,
	};
	const t = ( key: string ): string | undefined => deps.i18n()[ key ];
	const say = ( message: string, tone: 'note' | 'error' = 'note' ) => {
		status.textContent = message;
		status.classList.toggle( 'is-error', 'error' === tone );
	};

	// Leaving mid-round drops files on the floor; the browser asks first.
	const guard = ( event: BeforeUnloadEvent ): void => {
		event.preventDefault();
	};

	const nameOf = ( id: string ): string =>
		deps.uploads()[ id ]?.name || deps.uploads()[ id ]?.expectedSize || id;

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

		const dims = `${ entry.width } × ${ entry.height }`;

		if ( 'none' === match.kind ) {
			entry.status.textContent = format(
				t( 'bulkNone' ),
				dims,
				openSizes( targets ).join( ', ' )
			);
			entry.row.classList.add( 'is-error' );
			return;
		}

		if ( 'taken' === match.kind ) {
			entry.status.textContent = format( t( 'bulkTaken' ), dims );
			entry.row.classList.add( 'is-error' );
			return;
		}

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

		const url = form.querySelector< HTMLInputElement >(
			'input[name="click_url"]'
		);

		if ( ! url || '' === url.value.trim() || ! url.checkValidity() ) {
			// Stops everything: every file would be refused for the same reason.
			entries.forEach( ( entry ) => {
				entry.pending = [];
			} );
			say( t( 'bulkNeedsUrl' ) ?? '', 'error' );
			document.getElementById( 'aggr-campaign-link' )?.focus();
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

		const settle = ( ok: boolean, message: string, redirect = '' ) => {
			round.busy = false;
			window.removeEventListener( 'beforeunload', guard );
			bar.remove();
			next.status.textContent = message;
			next.row.classList.toggle( 'is-error', ! ok );

			if ( ok ) {
				round.sent++;
				round.landed = redirect || round.landed;
			} else if ( '' !== redirect ) {
				round.refused = round.refused || redirect;
			} else {
				round.failed++;
			}

			pump();
		};

		const handle = sendBody( actionOf( form ), body, {
			progress: ( percent ) => {
				bar.value = percent;
			},
			done: ( _landedAt, text ) => {
				let answer: { ok?: boolean; redirect?: string } = {};

				try {
					answer = JSON.parse( text );
				} catch {
					// Not the JSON a scripted post is answered with: nothing
					// here can say what happened, so the page will.
				}

				settle(
					true === answer.ok,
					( true === answer.ok
						? t( 'bulkSent' )
						: t( 'bulkRefused' ) ) ?? '',
					typeof answer.redirect === 'string' ? answer.redirect : ''
				);
			},
			fail: ( reason ) => {
				settle(
					false,
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
			settle( false, t( 'uploadFailed' ) ?? '' );
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

		if (
			round.busy ||
			asking ||
			( round.sent === 0 && '' === round.refused )
		) {
			return;
		}

		/*
		 * A file that never reached the server has no page to show its
		 * reason on. Moving on would take the row saying so off the screen,
		 * so the list stays, and choosing that file again is the retry.
		 */
		if ( round.failed > 0 ) {
			say( t( 'bulkPartial' ) ?? '', 'error' );
			return;
		}

		const target = '' !== round.refused ? round.refused : round.landed;

		if ( round.sent > 0 ) {
			say( format( t( 'bulkDone' ), String( round.sent ) ) );
		}

		if ( '' === target || ! navigateSameOrigin( target ) ) {
			window.location.reload();
		}
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
				} );
			} catch {
				line.textContent = t( 'bulkUnreadable' ) ?? '';
				row.classList.add( 'is-error' );
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
