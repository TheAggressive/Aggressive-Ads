/**
 * Prepare the closed static profile; failures retain the reviewed raster fallback.
 * No child message grants readiness, measurement or destination authority.
 */

const MAX_ARTIFACT_BYTES = 9437184;
const MAX_DOCUMENT_BYTES = 2097152;

/**
 * Read actual response bytes under the source-artifact ceiling.
 *
 * @param {Response} response Local publication response.
 * @return {Promise<Object>} Decoded inert envelope.
 */
const readEnvelope = async ( response ) => {
	const reader = response.body?.getReader();
	if ( ! reader ) {
		throw new Error( 'unavailable' );
	}
	const chunks = [];
	let length = 0;
	try {
		while ( true ) {
			const { done, value } = await reader.read();
			if ( done ) {
				break;
			}
			length += value.byteLength;
			if ( length > MAX_ARTIFACT_BYTES ) {
				throw new Error( 'oversized' );
			}
			chunks.push( value );
		}
	} finally {
		await reader.cancel();
	}
	const bytes = new Uint8Array( length );
	let offset = 0;
	for ( const chunk of chunks ) {
		bytes.set( chunk, offset );
		offset += chunk.byteLength;
	}
	return JSON.parse(
		new window.TextDecoder( 'utf-8', { fatal: true } ).decode( bytes )
	);
};

/**
 * Authenticate compiled bytes before assigning the sandbox's injection sink.
 *
 * @param {Object} envelope Bounded local source.
 * @param {string} expected Reviewed document checksum.
 * @return {Promise<string>} Exact UTF-8 compiled document.
 */
const documentFrom = async ( envelope, expected ) => {
	if (
		envelope?.version !== 1 ||
		envelope.html5?.profile !== 'static' ||
		envelope.html5?.version !== 1 ||
		typeof envelope.document !== 'string' ||
		envelope.document.length > Math.ceil( MAX_DOCUMENT_BYTES / 3 ) * 4 ||
		! /^[0-9a-f]{64}$/.test( expected ) ||
		! window.crypto?.subtle
	) {
		throw new Error( 'unsupported' );
	}
	const binary = window.atob( envelope.document );
	if ( binary.length > MAX_DOCUMENT_BYTES ) {
		throw new Error( 'oversized' );
	}
	const bytes = Uint8Array.from( binary, ( character ) =>
		character.charCodeAt( 0 )
	);
	const digest = await window.crypto.subtle.digest( 'SHA-256', bytes );
	const actual = Array.from( new Uint8Array( digest ), ( byte ) =>
		byte.toString( 16 ).padStart( 2, '0' )
	).join( '' );
	if ( actual !== expected ) {
		throw new Error( 'changed' );
	}
	return new window.TextDecoder( 'utf-8', { fatal: true } ).decode( bytes );
};

/**
 * Stage an empty-sandbox frame without removing the previous rendered ad.
 * The caller commits this same node in place to avoid reloading the frame.
 *
 * @param {Object}            creative Reviewed descriptor and raster fallback.
 * @param {HTMLAnchorElement} ad       Prepared native click link and image.
 * @param {HTMLElement}       canvas   Existing render surface.
 * @param {number}            timeout  Remaining preparation deadline.
 * @return {Promise<boolean>} Whether a static frame is staged and ready.
 */
export const prepareStaticHtml5 = async ( creative, ad, canvas, timeout ) => {
	if (
		timeout <= 0 ||
		! canvas.isConnected ||
		window.matchMedia?.( '(prefers-reduced-motion: reduce)' ).matches ||
		typeof creative.document !== 'string'
	) {
		return false;
	}
	const controller = new window.AbortController();
	let observer;
	let frame;
	let ready = false;
	let timer;
	try {
		const url = new URL( creative.document, window.location.href );
		if (
			url.origin !== window.location.origin ||
			! /\.json$/.test( url.pathname ) ||
			url.search ||
			url.hash ||
			url.username ||
			url.password
		) {
			return false;
		}
		const aborted = new Promise( ( resolve ) => {
			controller.signal.addEventListener(
				'abort',
				() => resolve( false ),
				{ once: true }
			);
		} );
		timer = window.setTimeout( () => controller.abort(), timeout );
		observer = new window.MutationObserver( () => {
			if ( ! canvas.isConnected ) {
				controller.abort();
			}
		} );
		observer.observe( document.documentElement, {
			childList: true,
			subtree: true,
		} );
		const prepare = async () => {
			const response = await fetch( url.href, {
				credentials: 'omit',
				redirect: 'error',
				referrerPolicy: 'no-referrer',
				signal: controller.signal,
				headers: { Accept: 'application/json' },
			} );
			if ( ! response.ok ) {
				return false;
			}
			const source = await documentFrom(
				await readEnvelope( response ),
				creative.documentHash
			);
			if ( controller.signal.aborted || ! canvas.isConnected ) {
				return false;
			}
			frame = document.createElement( 'iframe' );
			frame.setAttribute( 'sandbox', '' );
			frame.referrerPolicy = 'no-referrer';
			frame.title = creative.alt ?? '';
			frame.tabIndex = -1;
			frame.setAttribute( 'aria-hidden', 'true' );
			frame.style.cssText =
				'border:0;display:block;width:100%;pointer-events:none;';
			frame.style.aspectRatio = `${ creative.width } / ${ creative.height }`;
			const loaded = new Promise( ( resolve ) => {
				frame.addEventListener( 'load', () => resolve( true ), {
					once: true,
					signal: controller.signal,
				} );
				frame.addEventListener( 'error', () => resolve( false ), {
					once: true,
					signal: controller.signal,
				} );
			} );
			ad.style.visibility = 'hidden';
			ad.style.position = 'absolute';
			ad.setAttribute( 'aria-hidden', 'true' );
			ad.tabIndex = -1;
			frame.srcdoc = source;
			ad.appendChild( frame );
			canvas.appendChild( ad );
			return await loaded;
		};
		ready =
			Boolean( await Promise.race( [ prepare(), aborted ] ) ) &&
			! controller.signal.aborted &&
			canvas.isConnected;
		return ready;
	} catch {
		return false;
	} finally {
		window.clearTimeout( timer );
		observer?.disconnect();
		controller.abort();
		if ( ! ready ) {
			frame?.remove();
			ad.remove();
		}
		ad.style.visibility = '';
		ad.style.position = '';
		ad.removeAttribute( 'aria-hidden' );
		ad.removeAttribute( 'tabindex' );
	}
};
