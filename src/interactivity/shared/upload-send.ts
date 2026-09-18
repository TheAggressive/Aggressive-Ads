/**
 * Sending an upload form so its progress can be read.
 *
 * Bundled into `@aggr/upload`, not a module of its own: nothing else sends
 * files, and a separate module would be one more request on the Ads step.
 *
 * **The server's answer is unchanged.** The same fields go to the same
 * `admin-post.php` handler, which redirects exactly as it does for a plain
 * form post — back to the campaign with a notice, or with the refusal. The
 * request follows that redirect, and the page then goes to where it landed,
 * so every success message and every refusal is the server's own, rendered
 * the way it always was. The only thing gained is being able to watch the
 * bytes leave.
 */

/** Why a send did not complete, which decides what the card says. */
export type SendFailure = 'error' | 'cancelled' | 'timeout';

export interface SendHandlers {
	/** Called as bytes are sent, with a whole-number percentage. */
	progress: ( percent: number ) => void;
	/** Called once the server has answered, with the address it redirected to. */
	done: ( landedAt: string ) => void;
	/** Called when the request did not complete, and why. */
	fail: ( reason: SendFailure ) => void;
}

/** A send in flight. */
export interface SendHandle {
	/** Stops it. The handler's `fail` hears `cancelled`. */
	cancel: () => void;
}

/**
 * How long a send may take, start to answer, before it is given up.
 *
 * Generous: the largest file a placement accepts is a few megabytes, which a
 * poor mobile connection sends in well under a minute. What this bounds is a
 * server that never answers, which would otherwise leave a card saying
 * "Uploading" for ever.
 */
export const SEND_TIMEOUT_MS = 120_000;

/**
 * A percentage for a progress event, held to 0–100.
 *
 * Browsers report `total` as zero when the length is not computable, and a
 * division by it would draw a bar at infinity. A request that is on its way
 * with no known size is shown as started, not as finished.
 *
 * @param loaded Bytes sent so far.
 * @param total  Bytes to send, or zero when unknown.
 * @return A whole number from 0 to 100.
 */
export function percentOf( loaded: number, total: number ): number {
	if (
		! Number.isFinite( loaded ) ||
		! Number.isFinite( total ) ||
		total <= 0
	) {
		return 0;
	}

	return Math.max(
		0,
		Math.min( 100, Math.round( ( loaded / total ) * 100 ) )
	);
}

/**
 * Where a form posts to, resolved the way the browser would resolve it.
 *
 * @param form The form.
 * @return An absolute URL.
 */
export function actionOf( form: HTMLFormElement ): string {
	return new URL(
		form.getAttribute( 'action' ) ?? '',
		form.ownerDocument.baseURI
	).href;
}

/**
 * Sends a form as `multipart/form-data`, reporting upload progress.
 *
 * Returns null when this browser cannot, and the caller submits the form the
 * ordinary way. Nothing is sent in that case, so falling back can never send
 * the file twice.
 *
 * A failure is not retried here. By the time a request errors the file may
 * have reached the server, and sending it again could leave two creatives
 * where the advertiser asked for one; the caller puts the button back so a
 * person decides.
 *
 * @param form     The upload form.
 * @param handlers What to do as it goes.
 * @param make     Builds the request; the browser's own by default.
 * @return A handle to cancel it, or null when it could not be started.
 */
export function sendWithProgress(
	form: HTMLFormElement,
	handlers: SendHandlers,
	make: () => XMLHttpRequest = () => new XMLHttpRequest()
): SendHandle | null {
	let request: XMLHttpRequest;

	try {
		request = make();
	} catch {
		return null;
	}

	if ( ! request.upload ) {
		return null;
	}

	// Exactly one ending, whichever of the four events arrives first.
	let ended = false;
	const end = ( reason: SendFailure ) => () => {
		if ( ! ended ) {
			ended = true;
			handlers.fail( reason );
		}
	};

	let last = -1;

	request.upload.addEventListener( 'progress', ( event ) => {
		const percent = event.lengthComputable
			? percentOf( event.loaded, event.total )
			: 0;

		// One call per whole percent, not one per network packet.
		if ( percent !== last ) {
			last = percent;
			handlers.progress( percent );
		}
	} );

	request.addEventListener( 'load', () => {
		if ( ! ended ) {
			ended = true;
			handlers.done( request.responseURL || window.location.href );
		}
	} );

	request.addEventListener( 'error', end( 'error' ) );
	request.addEventListener( 'abort', end( 'cancelled' ) );
	request.addEventListener( 'timeout', end( 'timeout' ) );

	/*
	 * The attribute, never `form.action`. WordPress forms post a field named
	 * `action`, and a named control shadows the form's own property, so
	 * `form.action` is that <input> — the request went to
	 * "[object HTMLInputElement]" and failed before a byte was sent. jsdom
	 * does not shadow, which is how a passing test hid it; the browser check
	 * is what found it.
	 *
	 * Same origin, so the session cookie goes with it exactly as it would
	 * with a form post; nothing is added to what the form already carries.
	 */
	request.open( 'POST', actionOf( form ) );
	request.timeout = SEND_TIMEOUT_MS;
	request.send( new FormData( form ) );

	return {
		cancel: () => request.abort(),
	};
}
