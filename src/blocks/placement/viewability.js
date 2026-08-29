/**
 * Reports one viewable event for a fill that was actually on screen.
 *
 * The threshold is the server's — half the pixels for one continuous second by
 * default — and arrives with the fill. This module observes and reports; it does
 * not decide what qualifies.
 *
 * Nothing here may keep an ad from rendering. Every failure path is a silent
 * no-op, because an unmeasured impression is not a lost one.
 */

/**
 * Watches one filled slot and beacons once when it has been seen.
 *
 * @param {Element}  element        The painted creative's container.
 * @param {Object}   options        Threshold and beacon details.
 * @param {number}   options.ratio  Visible fraction required, 0-1.
 * @param {number}   options.dwellMs Continuous milliseconds required.
 * @param {string}   options.beacon Beacon URL.
 * @param {string}   options.token  The fill token this reports against.
 * @return {Function|null} A teardown, or null when measurement is unavailable.
 */
export const observeViewability = ( element, options ) => {
	const { ratio, dwellMs, beacon, token } = options ?? {};

	if ( ! element || ! beacon || ! token ) {
		return null;
	}

	// Old browsers simply do not measure. Stated rather than incidental: this
	// is the majority behaviour on some traffic and must never throw.
	if (
		typeof window === 'undefined' ||
		typeof window.IntersectionObserver !== 'function'
	) {
		return null;
	}

	let timer = null;
	let done = false;
	let observer = null;

	const stopTimer = () => {
		if ( timer !== null ) {
			window.clearTimeout( timer );
			timer = null;
		}
	};

	const teardown = () => {
		stopTimer();
		document.removeEventListener( 'visibilitychange', onVisibilityChange );
		observer?.disconnect();
		observer = null;
	};

	const report = () => {
		if ( done ) {
			return;
		}

		done = true;

		// Reported, never awaited. The ad is already on screen and nothing
		// about it depends on this landing.
		window.navigator.sendBeacon?.(
			beacon,
			new URLSearchParams( { token, event: 'viewable' } )
		);

		teardown();
	};

	/**
	 * A hidden tab is not a view, so the dwell clock only runs while visible.
	 *
	 * One timeout armed on entry rather than a per-frame loop: the browser
	 * already knows how to wait a second, and a page with twenty slots should
	 * not hold twenty animation callbacks to find out.
	 */
	const startTimer = () => {
		if ( timer !== null || done ) {
			return;
		}

		timer = window.setTimeout( report, dwellMs );
	};

	const onVisibilityChange = () => {
		if ( document.visibilityState === 'hidden' ) {
			// Reset rather than pause: the standard asks for a *continuous*
			// second, and resuming a part-served timer would count a second
			// split across a tab switch.
			stopTimer();
		}
	};

	const onIntersect = ( entries ) => {
		for ( const entry of entries ) {
			const visibleEnough =
				entry.isIntersecting &&
				entry.intersectionRatio >= ratio &&
				document.visibilityState !== 'hidden';

			if ( visibleEnough ) {
				startTimer();
			} else {
				stopTimer();
			}
		}
	};

	try {
		observer = new window.IntersectionObserver( onIntersect, {
			// The observer fires when the ad crosses the threshold in either
			// direction, which is what lets the dwell clock reset on exit.
			threshold: [ 0, ratio, 1 ].filter(
				( value, index, all ) =>
					all.indexOf( value ) === index && value <= 1
			),
		} );

		document.addEventListener( 'visibilitychange', onVisibilityChange );
		observer.observe( element );
	} catch {
		teardown();

		return null;
	}

	return teardown;
};
