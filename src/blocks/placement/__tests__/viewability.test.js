/* global Event, afterEach, beforeEach, describe, expect, it, jest */

import { observeViewability } from '../viewability.js';

/**
 * A fake IntersectionObserver, so the browser's timing is not the thing under
 * test. `fire()` is what the real observer does when an element crosses a
 * threshold; everything else here is bookkeeping.
 */
const installObserver = () => {
	const state = { callback: null, observed: [], disconnected: 0 };

	window.IntersectionObserver = class {
		constructor( callback ) {
			state.callback = callback;
		}

		observe( element ) {
			state.observed.push( element );
		}

		disconnect() {
			state.disconnected += 1;
		}
	};

	state.fire = ( ratio ) =>
		state.callback( [
			{ isIntersecting: ratio > 0, intersectionRatio: ratio },
		] );

	return state;
};

const setVisibility = ( value ) => {
	Object.defineProperty( document, 'visibilityState', {
		configurable: true,
		get: () => value,
	} );
};

const options = ( overrides = {} ) => ( {
	ratio: 0.5,
	dwellMs: 1000,
	beacon: '/aggr/v1/i',
	token: 'abc.def',
	...overrides,
} );

describe( 'viewability observation', () => {
	let element;
	let sendBeacon;

	beforeEach( () => {
		jest.useFakeTimers();
		setVisibility( 'visible' );

		element = document.createElement( 'div' );
		document.body.appendChild( element );

		sendBeacon = jest.fn();
		window.navigator.sendBeacon = sendBeacon;
	} );

	afterEach( () => {
		jest.useRealTimers();
		document.body.replaceChildren();
		delete window.IntersectionObserver;
		delete window.navigator.sendBeacon;
	} );

	it( 'reports once after the threshold is held for the full duration', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 0.5 );
		jest.advanceTimersByTime( 1000 );

		expect( sendBeacon ).toHaveBeenCalledTimes( 1 );

		const [ url, body ] = sendBeacon.mock.calls[ 0 ];

		expect( url ).toBe( '/aggr/v1/i' );
		expect( body.get( 'event' ) ).toBe( 'viewable' );
		expect( body.get( 'token' ) ).toBe( 'abc.def' );
	} );

	it( 'does not report one millisecond early', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 1 );
		jest.advanceTimersByTime( 999 );

		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	it( 'does not report below the ratio, however long it stays', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 0.49 );
		jest.advanceTimersByTime( 60000 );

		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	it( 'restarts the clock when the ad scrolls back out', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 1 );
		jest.advanceTimersByTime( 900 );

		observer.fire( 0 );
		jest.advanceTimersByTime( 900 );

		expect( sendBeacon ).not.toHaveBeenCalled();

		// A second that is continuous this time.
		observer.fire( 1 );
		jest.advanceTimersByTime( 1000 );

		expect( sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	it( 'does not count a second spent in a hidden tab', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 1 );
		jest.advanceTimersByTime( 500 );

		setVisibility( 'hidden' );
		document.dispatchEvent( new Event( 'visibilitychange' ) );
		jest.advanceTimersByTime( 5000 );

		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	/**
	 * Entering view while the tab is already hidden starts no clock.
	 *
	 * Distinct from the test above, which hides the tab *after* entry and so
	 * exercises the `visibilitychange` handler. This one never fires that
	 * event, so only the check inside the intersection callback can stop it — a
	 * sabotage removing that check left every other test green.
	 */
	it( 'does not start counting when it enters view in a hidden tab', () => {
		const observer = installObserver();
		setVisibility( 'hidden' );

		observeViewability( element, options() );

		observer.fire( 1 );
		jest.advanceTimersByTime( 5000 );

		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	it( 'reports at most once and then stops observing', () => {
		const observer = installObserver();
		observeViewability( element, options() );

		observer.fire( 1 );
		jest.advanceTimersByTime( 1000 );

		// The real observer is disconnected by now; the fake still holds the
		// callback, which is what makes this a test of the latch rather than of
		// the observer's own bookkeeping.
		observer.fire( 1 );
		jest.advanceTimersByTime( 5000 );
		observer.fire( 1 );
		jest.advanceTimersByTime( 5000 );

		expect( sendBeacon ).toHaveBeenCalledTimes( 1 );
		expect( observer.disconnected ).toBe( 1 );
	} );

	it( 'honours a threshold other than the default', () => {
		const observer = installObserver();
		observeViewability( element, options( { ratio: 0.3, dwellMs: 2000 } ) );

		observer.fire( 0.3 );
		jest.advanceTimersByTime( 1999 );
		expect( sendBeacon ).not.toHaveBeenCalled();

		jest.advanceTimersByTime( 1 );
		expect( sendBeacon ).toHaveBeenCalledTimes( 1 );
	} );

	/**
	 * The no-op paths. An unmeasured impression is not a lost one, so none of
	 * these may throw — the ad is already on the page by the time any of this
	 * runs.
	 */
	it( 'does nothing without IntersectionObserver', () => {
		delete window.IntersectionObserver;

		expect( observeViewability( element, options() ) ).toBeNull();
		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	it( 'does nothing without a token or a beacon', () => {
		installObserver();

		expect(
			observeViewability( element, options( { token: '' } ) )
		).toBeNull();
		expect(
			observeViewability( element, options( { beacon: '' } ) )
		).toBeNull();
		expect( observeViewability( null, options() ) ).toBeNull();
	} );

	it( 'survives an observer that throws on construction', () => {
		window.IntersectionObserver = class {
			constructor() {
				throw new Error( 'no observers here' );
			}
		};

		expect( () => observeViewability( element, options() ) ).not.toThrow();
		expect( sendBeacon ).not.toHaveBeenCalled();
	} );

	it( 'survives a browser with no sendBeacon', () => {
		const observer = installObserver();
		delete window.navigator.sendBeacon;

		observeViewability( element, options() );
		observer.fire( 1 );

		expect( () => jest.advanceTimersByTime( 1000 ) ).not.toThrow();
	} );
} );
