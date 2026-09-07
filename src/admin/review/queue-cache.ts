/**
 * What the review queue remembers, and which answer it is allowed to believe.
 *
 * Every tab click costs a full WordPress bootstrap — measured at 300ms warm and
 * 1.3s cold on a local site, against a query handler that takes five. The wait
 * is the platform, not the work, so the fix is not to make it faster but to
 * stop it happening in front of the reader: show what was last known
 * immediately, and correct it when the server answers.
 *
 * That trade is only honest for a *shared* queue if the correction actually
 * arrives. Two reviewers work the same list; one claims a campaign and the
 * other must stop seeing it as unassigned. So nothing here caches instead of
 * fetching — it caches *while* fetching.
 */

import type { Queue, Tab } from './types';

/** One remembered answer. */
export type QueueEntry = {
	queue: Queue;
	tabs: Tab[];
};

/**
 * The identity of a request, so two of them cannot be confused.
 *
 * Filter and page together: the same filter on page two is a different answer
 * from page one, and reusing one for the other shows rows the reader did not
 * ask for.
 */
export const keyFor = ( filter: string, page: number ): string =>
	`${ filter }:${ page }`;

export type QueueCache = {
	get: ( filter: string, page: number ) => QueueEntry | undefined;
	put: ( filter: string, page: number, entry: QueueEntry ) => void;
	/** Issues a ticket for a request about to be sent. */
	issue: () => number;
	/**
	 * Whether a response may be applied.
	 *
	 * **This is the race the pattern is known for.** Click "pending" then
	 * "decided" quickly and the two requests are in flight together; if
	 * pending's answer lands second it would overwrite decided's, and the
	 * reader would be looking at a tab they had already navigated away from
	 * with the other tab still highlighted. Only the most recently issued
	 * request may write.
	 */
	isCurrent: ( ticket: number ) => boolean;
	/** Forgets everything, for when a write has made every answer suspect. */
	clear: () => void;
};

/**
 * Creates a cache with its own request ordering.
 *
 * @return {QueueCache} A cache instance.
 */
export function createQueueCache(): QueueCache {
	const entries = new Map< string, QueueEntry >();
	let issued = 0;

	return {
		get: ( filter, page ) => entries.get( keyFor( filter, page ) ),
		put: ( filter, page, entry ) => {
			entries.set( keyFor( filter, page ), entry );
		},
		issue: () => {
			issued += 1;

			return issued;
		},
		isCurrent: ( ticket ) => ticket === issued,
		clear: () => {
			entries.clear();
		},
	};
}
