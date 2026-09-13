/**
 * Drag-and-drop enhancement for creative file inputs.
 *
 * Namespace: aggr/upload
 * State is keyed per instance at state.uploads[ uploadId ].
 *
 * The native file input remains the only submit path. Dropping a file assigns
 * it to that input after the same size/type/dimension checks the server uses.
 */

import { store, getContext } from '@wordpress/interactivity';
import { checkCreativeFile, parsePixelSize } from '@aggr/logic';

interface UploadState {
	expectedSize: string;
	maxBytes: number;
	maxPixels: number;
	allowedMime: string[];

	// Per placement, because the number inside it is. Every other refusal
	// says the same thing on every slot and lives in the shared i18n map.
	sizeMessage: string;
}

interface UploadContext {
	uploadId?: string;
}

const initializedIds = new Set< string >();

/**
 * Marks a form as wired in the DOM rather than only in this module.
 *
 * The file is evaluated more than once on some pages — two instances, two
 * `initializedIds` sets — so a module-local guard alone would let the same
 * input be bound twice and upload twice.
 */
const READY_ATTRIBUTE = 'data-aggr-upload-ready';
const submitted = new Set< string >();

function announcerFor( id: string ): HTMLElement | null {
	return document.getElementById( `aggr-upload-status-${ id }` );
}

/**
 * Says what happened, to everybody.
 *
 * This paragraph used to be `aggr-sr`, so every refusal below was spoken and
 * never shown: the file input emptied itself, the upload button appeared, and
 * a sighted advertiser had no way to know a check had refused the image.
 *
 * The tone styles the message and nothing else. Which control is at fault is
 * decided at the call site, because this is also how the destination URL
 * reports itself and the file is not what is wrong there.
 */
function announce(
	id: string,
	message: string,
	tone: 'note' | 'error' = 'note'
): void {
	const announcer = announcerFor( id );
	if ( announcer ) {
		announcer.textContent = message;
		announcer.classList.toggle( 'is-error', 'error' === tone );
	}
}

function inputFor( id: string ): HTMLInputElement | null {
	const input = document.getElementById( `aggr-file-${ id }` );
	return input instanceof HTMLInputElement ? input : null;
}

function formFor( id: string ): HTMLFormElement | null {
	const zone = document.querySelector(
		`[data-aggr-upload="${ CSS.escape( id ) }"]`
	);
	return zone instanceof HTMLFormElement ? zone : null;
}

function urlInputFor( id: string ): HTMLInputElement | null {
	const input = document.getElementById( `aggr-click-${ id }` );
	return input instanceof HTMLInputElement ? input : null;
}

function submitButtonFor( id: string ): HTMLButtonElement | null {
	const button = formFor( id )?.querySelector( 'button[type="submit"]' );
	return button instanceof HTMLButtonElement ? button : null;
}

/**
 * Sends the upload as soon as a file and a destination are both good.
 *
 * The pair is what the server accepts: a creative with no destination cannot
 * serve, so uploading on file-select alone would only produce a 422. Waiting
 * for both is what lets the button go away without changing what is valid.
 *
 * `change` rather than `input` on the URL, so this fires when someone has
 * finished typing an address rather than part-way through one.
 */
function maybeSubmit( id: string ): void {
	if ( submitted.has( id ) ) {
		return;
	}

	const form = formFor( id );
	const file = inputFor( id );
	const url = urlInputFor( id );

	if ( ! form || ! file || ! url || ! file.files?.length ) {
		return;
	}

	if ( '' === url.value.trim() ) {
		return;
	}

	// The browser's own URL parsing, not a pattern of ours to keep in step
	// with it. An address it rejects would fail the same check server-side.
	if ( ! url.checkValidity() ) {
		announce( id, state.i18n.needsUrl ?? '', 'error' );
		url.setAttribute( 'aria-invalid', 'true' );
		return;
	}

	url.removeAttribute( 'aria-invalid' );
	submitted.add( id );
	announce( id, state.i18n.uploading ?? '' );
	form.requestSubmit();
}

/**
 * Puts the manual control back when the automatic path cannot finish.
 *
 * Hiding it outright would leave someone whose address was rejected with no
 * way to try again, which is worse than the click the hiding removed.
 *
 * The hiding itself is the template's: the button is rendered `hidden` so it
 * never paints. Doing it here meant doing it after first paint, which showed
 * the button on every load and took it away a frame later.
 */
function revealButton( id: string ): void {
	const button = submitButtonFor( id );
	if ( button ) {
		button.hidden = false;
	}
}

/**
 * The sentence for a refusal code.
 *
 * `size` is the one that cannot come from the shared map: the limit belongs
 * to the placement, and the shared message named the two-megabyte ceiling on
 * every slot, including the ones that refuse at a tenth of it.
 */
function messageFor(
	id: string,
	code: 'type' | 'size' | 'pixels' | 'dimensions' | 'empty'
): string {
	if ( 'size' === code ) {
		const perSlot = state.uploads[ id ]?.sizeMessage;
		if ( perSlot ) {
			return perSlot;
		}
	}

	const copy = state.i18n[ code ];
	return typeof copy === 'string' ? copy : '';
}

function readImageSize(
	file: File
): Promise< { width: number; height: number } > {
	return new Promise( ( resolve, reject ) => {
		const url = URL.createObjectURL( file );
		const image = new Image();
		image.onload = () => {
			URL.revokeObjectURL( url );
			resolve( {
				width: image.naturalWidth,
				height: image.naturalHeight,
			} );
		};
		image.onerror = () => {
			URL.revokeObjectURL( url );
			reject( new Error( 'unreadable' ) );
		};
		image.src = url;
	} );
}

async function applyFile( id: string, file: File ): Promise< void > {
	const current = state.uploads[ id ];
	const input = inputFor( id );
	if ( ! current || ! input ) {
		return;
	}

	// A new file is a new attempt: whatever the last one was marked for no
	// longer describes what is in the field.
	input.removeAttribute( 'aria-invalid' );

	const expected = parsePixelSize( current.expectedSize );
	if ( expected === null ) {
		announce( id, messageFor( id, 'dimensions' ), 'error' );
		revealButton( id );
		return;
	}

	let width = 0;
	let height = 0;
	try {
		const size = await readImageSize( file );
		width = size.width;
		height = size.height;
	} catch {
		announce( id, messageFor( id, 'type' ), 'error' );
		input.setAttribute( 'aria-invalid', 'true' );
		revealButton( id );
		return;
	}

	const result = checkCreativeFile( {
		mime: file.type,
		bytes: file.size,
		width,
		height,
		expectedWidth: expected.width,
		expectedHeight: expected.height,
		maxBytes: current.maxBytes,
		maxPixels: current.maxPixels,
		allowedMime: current.allowedMime,
	} );

	if ( ! result.ok ) {
		input.value = '';
		announce( id, messageFor( id, result.code ), 'error' );
		input.setAttribute( 'aria-invalid', 'true' );

		/*
		 * No button here. Choosing another file runs this whole function
		 * again, so the recovery is the one the person is already making,
		 * and a button that submits an empty file input is not a way out of
		 * anything. Revealing it was how a refused image came to look like a
		 * broken uploader: the button was the only visible thing that
		 * changed, and the reason was in a paragraph nobody could see.
		 */
		return;
	}

	/*
	 * Only a drop needs the file putting into the input; one chosen through
	 * the input is already there.
	 *
	 * **`new DataTransfer()` is not constructible in WebKit.** Running it
	 * unconditionally threw before the announcement below, so choosing a file
	 * in Safari left the status silent and the upload never started — and
	 * because the throw happens inside a promise nobody awaits, it left no
	 * error anywhere either.
	 */
	if ( input.files?.[ 0 ] !== file ) {
		try {
			const transfer = new DataTransfer();

			transfer.items.add( file );
			input.files = transfer.files;
		} catch {
			// A browser that cannot build one cannot accept a drop. The
			// input still works, which is what the hint tells people to use.
			announce( id, messageFor( id, 'empty' ), 'error' );

			return;
		}
	}

	announce( id, state.i18n.ready ?? '' );
	maybeSubmit( id );
}

/**
 * Attaches drag and drop, the file check and the automatic send to one form.
 *
 * @param uploadId The placement id this form uploads to.
 * @param zone     The form element.
 */
function wireUpload( uploadId: string, zone: HTMLElement ): void {
	const input = inputFor( uploadId );

	if ( ! input ) {
		return;
	}

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
		const file = event.dataTransfer?.files[ 0 ];
		if ( file ) {
			void applyFile( uploadId, file );
		}
	} );

	input.addEventListener( 'change', () => {
		const file = input.files?.[ 0 ];
		if ( file ) {
			void applyFile( uploadId, file );
		}
	} );

	const url = urlInputFor( uploadId );
	const button = submitButtonFor( uploadId );

	/*
	 * The automatic path is only switched on where the manual one
	 * exists to fall back to. Every failure below answers with
	 * `revealButton`, and there is nothing to reveal on a form that
	 * has no button in it.
	 */
	if ( url && button ) {
		/*
		 * Commit events, not `input`. A half-typed address is often a
		 * syntactically valid URL — `https://exa` parses — so sending
		 * on every keystroke would upload to whatever someone had got
		 * to so far. `blur` is here as well as `change` because the
		 * two differ: `change` is silent when the value has not been
		 * edited since it was last committed, which is exactly the
		 * case after a failed attempt has been corrected and restored.
		 */
		url.addEventListener( 'change', () => maybeSubmit( uploadId ) );
		url.addEventListener( 'blur', () => maybeSubmit( uploadId ) );
	}
}

/**
 * Wires one upload form, once, whoever asks first.
 *
 * @param uploadId The placement id this form uploads to.
 */
function initUpload( uploadId: string ): void {
	if ( '' === uploadId || initializedIds.has( uploadId ) ) {
		return;
	}

	const zone = document.querySelector(
		`[data-aggr-upload="${ CSS.escape( uploadId ) }"]`
	);

	if (
		! ( zone instanceof HTMLElement ) ||
		zone.hasAttribute( READY_ATTRIBUTE )
	) {
		return;
	}

	initializedIds.add( uploadId );
	zone.setAttribute( READY_ATTRIBUTE, '1' );

	wireUpload( uploadId, zone );
}

/**
 * Wires every upload form on the page without waiting to be asked.
 *
 * **`data-wp-init` is not enough on its own.** The dialog store already says
 * so — it boots its shells the same way — and this module found out the hard
 * way: in WebKit the file is evaluated and `actions.init` is never called, so
 * choosing a creative did nothing at all and nothing said why. Booting here
 * does not depend on the runtime reaching the directive.
 */
function bootAllUploads(): void {
	document
		.querySelectorAll< HTMLElement >( '[data-aggr-upload]' )
		.forEach( ( zone ) => {
			initUpload( zone.getAttribute( 'data-aggr-upload' ) ?? '' );
		} );
}

const { state } = store( 'aggr/upload', {
	state: {
		uploads: {} as Record< string, UploadState >,
		// Empty by design: the server hydrates it and this object is merged
		// over the server's. See the note in autosave.ts.
		i18n: {} as Partial< Record< string, string > >,
	},
	actions: {
		/**
		 * The runtime's entry point, kept for the browsers that call it.
		 */
		init() {
			const { uploadId } = getContext< UploadContext >();

			initUpload( String( uploadId ?? '' ) );
		},
	},
} );

bootAllUploads();

export { state };
