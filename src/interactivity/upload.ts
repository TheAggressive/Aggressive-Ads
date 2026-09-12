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
}

interface UploadContext {
	uploadId?: string;
}

const initializedIds = new Set< string >();
const submitted = new Set< string >();

function announcerFor( id: string ): HTMLElement | null {
	return document.getElementById( `aggr-upload-status-${ id }` );
}

function announce( id: string, message: string ): void {
	const announcer = announcerFor( id );
	if ( announcer ) {
		announcer.textContent = message;
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
		announce( id, state.i18n.needsUrl ?? '' );
		revealButton( id );
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
 */
function revealButton( id: string ): void {
	const button = submitButtonFor( id );
	if ( button ) {
		button.hidden = false;
	}
}

function messageFor(
	code: 'type' | 'size' | 'pixels' | 'dimensions' | 'empty'
): string {
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

	const expected = parsePixelSize( current.expectedSize );
	if ( expected === null ) {
		announce( id, messageFor( 'dimensions' ) );
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
		announce( id, messageFor( 'type' ) );
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
		announce( id, messageFor( result.code ) );

		/*
		 * The file is gone and the automatic path cannot run without one, so
		 * the control has to come back. Leaving it hidden strands somebody
		 * whose image was refused: no button, and nothing they can do to the
		 * destination field will send a creative that no longer exists.
		 */
		revealButton( id );
		return;
	}

	const transfer = new DataTransfer();
	transfer.items.add( file );
	input.files = transfer.files;
	announce( id, state.i18n.ready ?? '' );
	maybeSubmit( id );
}

const { state } = store( 'aggr/upload', {
	state: {
		uploads: {} as Record< string, UploadState >,
		// Empty by design: the server hydrates it and this object is merged
		// over the server's. See the note in autosave.ts.
		i18n: {} as Partial< Record< string, string > >,
	},
	actions: {
		init() {
			const { uploadId } = getContext< UploadContext >();
			if ( ! uploadId || initializedIds.has( uploadId ) ) {
				return;
			}
			initializedIds.add( uploadId );

			const zone = document.querySelector(
				`[data-aggr-upload="${ CSS.escape( uploadId ) }"]`
			);
			const input = inputFor( uploadId );
			if ( ! ( zone instanceof HTMLElement ) || ! input ) {
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

			/*
			 * The button is hidden only once this listener is attached, so a
			 * browser without this module still gets the ordinary form it has
			 * always had. It comes back if an automatic attempt cannot finish.
			 */
			const url = urlInputFor( uploadId );
			const button = submitButtonFor( uploadId );

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
				button.hidden = true;
			}
		},
	},
} );

export { state };
