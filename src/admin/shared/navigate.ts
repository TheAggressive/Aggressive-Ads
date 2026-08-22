/**
 * Same-origin navigation for addresses that arrive as DOM text.
 *
 * The review screen receives its bootstrap through a `data-aggr-review`
 * attribute and campaign rows through REST. Both are server-generated, but by
 * the time the browser sees them they are DOM text, and assigning DOM text to
 * `location.href` executes it when the value carries a `javascript:` scheme.
 * CodeQL flags exactly that flow (js/xss-through-dom), and it is right to: the
 * guarantee lived in every producer upstream rather than at the point of use.
 *
 * A `javascript:` or `data:` URL parses with an origin of "null", so comparing
 * origins rejects it without this code enumerating dangerous schemes — a
 * denylist of schemes is the version of this that goes stale.
 */

/**
 * Resolves a URL and returns it only when it lands on the current origin.
 *
 * Separate from the navigation below so the decision is a pure function. It is
 * the half that carries the security property, and `window.location` cannot be
 * replaced under jsdom — testing it through the assignment would mean not
 * testing it at all.
 *
 * @param target Absolute or relative URL.
 * @return The resolved href, or null when it is not same-origin.
 */
export function sameOriginUrl( target: string ): string | null {
	let url: URL;

	try {
		url = new URL( target, window.location.origin );
	} catch {
		return null;
	}

	return url.origin === window.location.origin ? url.href : null;
}

/**
 * Navigates, but only to an address on this origin.
 *
 * @param target Absolute or relative URL.
 * @return Whether the navigation was allowed and started.
 */
export function navigateSameOrigin( target: string ): boolean {
	const href = sameOriginUrl( target );

	if ( null === href ) {
		return false;
	}

	window.location.href = href;

	return true;
}
