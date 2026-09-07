import { createQueueCache, keyFor } from '../queue-cache';
import type { Queue, Tab } from '../types';

const queue = ( total: number ): Queue => ( {
	rows: [],
	total,
	pages: 1,
	page: 1,
} );

const tabs = (): Tab[] => [ { key: 'pending', label: 'Pending', count: 1 } ];

describe( 'review queue cache', () => {
	it( 'keeps a filter and page apart', () => {
		expect( keyFor( 'pending', 1 ) ).not.toBe( keyFor( 'pending', 2 ) );
		expect( keyFor( 'pending', 1 ) ).not.toBe( keyFor( 'decided', 1 ) );
	} );

	it( 'returns nothing for a page it has never seen', () => {
		const cache = createQueueCache();

		expect( cache.get( 'pending', 1 ) ).toBeUndefined();
	} );

	it( 'returns what it was given, per filter and page', () => {
		const cache = createQueueCache();

		cache.put( 'pending', 1, { queue: queue( 5 ), tabs: tabs() } );
		cache.put( 'decided', 1, { queue: queue( 9 ), tabs: tabs() } );

		expect( cache.get( 'pending', 1 )?.queue.total ).toBe( 5 );
		expect( cache.get( 'decided', 1 )?.queue.total ).toBe( 9 );
		expect( cache.get( 'pending', 2 ) ).toBeUndefined();
	} );

	/**
	 * **The race this pattern is known for.**
	 *
	 * Click one tab then another quickly and both requests are in flight. If
	 * the first answer lands second it would overwrite the second, leaving the
	 * reader on a tab they had navigated away from — with the other tab still
	 * highlighted, which is the part that makes it look like a bug in the
	 * highlighting rather than in the data.
	 */
	it( 'refuses an answer that a later request has already superseded', () => {
		const cache = createQueueCache();

		const first = cache.issue();
		const second = cache.issue();

		// The second request is the one the reader is waiting for.
		expect( cache.isCurrent( second ) ).toBe( true );

		// The first arrives late and must be discarded.
		expect( cache.isCurrent( first ) ).toBe( false );
	} );

	it( 'accepts an answer while it is still the newest', () => {
		const cache = createQueueCache();
		const only = cache.issue();

		expect( cache.isCurrent( only ) ).toBe( true );
	} );

	/**
	 * Order of *arrival* does not matter; order of *issue* does.
	 *
	 * A response is judged by whether anything newer was asked for since, not
	 * by when it happened to come back.
	 */
	it( 'judges by issue order rather than arrival order', () => {
		const cache = createQueueCache();

		const a = cache.issue();
		const b = cache.issue();
		const c = cache.issue();

		expect( cache.isCurrent( c ) ).toBe( true );
		expect( cache.isCurrent( b ) ).toBe( false );
		expect( cache.isCurrent( a ) ).toBe( false );
	} );

	/**
	 * A write makes every remembered answer suspect.
	 *
	 * Approving a campaign changes the counts on every tab, not just the one
	 * being looked at, so the whole cache goes rather than the entry for the
	 * page that happened to be open.
	 */
	it( 'forgets everything when cleared', () => {
		const cache = createQueueCache();

		cache.put( 'pending', 1, { queue: queue( 5 ), tabs: tabs() } );
		cache.put( 'decided', 1, { queue: queue( 9 ), tabs: tabs() } );
		cache.clear();

		expect( cache.get( 'pending', 1 ) ).toBeUndefined();
		expect( cache.get( 'decided', 1 ) ).toBeUndefined();
	} );

	it( 'replaces a remembered page rather than keeping both', () => {
		const cache = createQueueCache();

		cache.put( 'pending', 1, { queue: queue( 5 ), tabs: tabs() } );
		cache.put( 'pending', 1, { queue: queue( 6 ), tabs: tabs() } );

		expect( cache.get( 'pending', 1 )?.queue.total ).toBe( 6 );
	} );
} );
