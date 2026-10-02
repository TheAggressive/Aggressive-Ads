/**
 * The audit trail's grouping, apart from its rendering.
 *
 * Pure, so it is tested without a DOM or WordPress: this is the part that
 * decides what a reviewer sees, and a merge that swallowed a decision would
 * hide exactly the entry somebody opened the log to find.
 */

import type { AuditEvent } from './types';

export type Entry = {
	first: AuditEvent;
	last: AuditEvent;
	count: number;
};

/**
 * Consecutive entries that say the same thing, as one.
 *
 * Input is newest first, as the server sends it, so `first` is the most recent
 * occurrence and `last` the earliest.
 */
export function collapse( events: AuditEvent[] ): Entry[] {
	const entries: Entry[] = [];

	for ( const event of events ) {
		const previous = entries[ entries.length - 1 ];

		if (
			previous &&
			previous.first.message === event.message &&
			previous.first.actor === event.actor &&
			previous.first.outcome === event.outcome &&
			previous.first.from_label === event.from_label &&
			previous.first.to_label === event.to_label
		) {
			previous.last = event;
			previous.count += 1;
			continue;
		}

		entries.push( { first: event, last: event, count: 1 } );
	}

	return entries;
}

/** Entries under the day they happened on, in order. */
export function byDay( entries: Entry[] ): Array< [ string, Entry[] ] > {
	const days: Array< [ string, Entry[] ] > = [];

	for ( const entry of entries ) {
		const day = entry.first.day_text;
		const current = days[ days.length - 1 ];

		if ( current && current[ 0 ] === day ) {
			current[ 1 ].push( entry );
		} else {
			days.push( [ day, [ entry ] ] );
		}
	}

	return days;
}
