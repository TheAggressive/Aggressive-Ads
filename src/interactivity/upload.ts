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
			resolve( { width: image.naturalWidth, height: image.naturalHeight } );
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
		return;
	}

	const transfer = new DataTransfer();
	transfer.items.add( file );
	input.files = transfer.files;
	announce( id, state.i18n.ready );
}

const { state } = store( 'aggr/upload', {
	state: {
		uploads: {} as Record< string, UploadState >,
		i18n: {
			ready: '',
			empty: '',
			type: '',
			size: '',
			pixels: '',
			dimensions: '',
		},
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
		},
	},
} );

export { state };
