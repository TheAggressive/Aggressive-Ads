/**
 * The campaign's audit trail, read as a timeline.
 *
 * It was a flat list of sentences, each with its date, actor and a raw outcome
 * slug, and the scheduler's refusals made it mostly noise: the same "This
 * campaign has not reached its end date." once an hour, every hour, burying
 * the approval somebody came to find. This keeps every entry the server sent
 * and changes only how they are read:
 *
 * - **Days head their entries**, so each entry shows only its time.
 * - **A status change is drawn as its two pills**, the same pills the queue
 *   uses, with the sentence kept for screen readers.
 * - **Identical consecutive entries collapse** into one line with a count and
 *   the span they cover. Consecutive only: the same message either side of a
 *   decision is two facts, not one.
 * - **A refusal or a failure says so in words**, on a pill; success says
 *   nothing, because it is what every other entry is.
 * - **The newest few are shown**, and the rest are one button away rather than
 *   a page-length scroll.
 *
 * The server formats every date and time in the site's timezone. Nothing here
 * builds a date from a timestamp, because the browser's zone is not the site's.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { t } from '../shared/save';
import { initialsOf } from '../shared/initials';
import type { AuditEvent } from './types';
import { byDay, collapse, type Entry } from './trail';

/** How many collapsed entries show before "Show all". */
const VISIBLE = 8;

function stamp( event: AuditEvent ): string | undefined {
	return event.created_at > 0
		? new Date( event.created_at * 1000 ).toISOString()
		: undefined;
}

function Actor( { event }: { event: AuditEvent } ): ReactElement {
	const name = '' === event.actor ? t( 'unknownUser' ) : event.actor;

	return (
		<span className="aggr-trail-actor">
			{ event.system ? (
				// The product itself, which is what "System" is.
				<span className="aggr-trail-actor__mark" aria-hidden="true" />
			) : (
				<span
					className="aggr-initials aggr-initials--person aggr-trail-actor__initials"
					aria-hidden="true"
				>
					{ initialsOf( name ) }
				</span>
			) }
			{ name }
		</span>
	);
}

function Row( { entry }: { entry: Entry } ): ReactElement {
	const event = entry.first;
	const refused = '' !== event.outcome_label;
	const moved = '' !== event.from_label && '' !== event.to_label;

	return (
		<li
			className={
				refused
					? 'aggr-trail__item aggr-trail__item--refused'
					: 'aggr-trail__item'
			}
		>
			<span
				className={
					moved && ! refused
						? 'aggr-trail__dot aggr-trail__dot--move'
						: 'aggr-trail__dot'
				}
				aria-hidden="true"
			/>
			<div className="aggr-trail__body">
				{ moved ? (
					<p className="aggr-trail__move">
						{ /*
						 * The pills are the picture; the sentence is the
						 * reading. A refused move also keeps its sentence on
						 * screen, because that is the reason it was refused.
						 */ }
						<span aria-hidden="true">
							<span
								className={ `aggr-pill aggr-pill--${ event.from_pill }` }
							>
								{ event.from_label }
							</span>
							<span className="aggr-trail__arrow">→</span>
							<span
								className={ `aggr-pill aggr-pill--${ event.to_pill }` }
							>
								{ event.to_label }
							</span>
						</span>
						{ refused ? null : (
							<span className="screen-reader-text">
								{ event.message }
							</span>
						) }
					</p>
				) : null }
				{ ! moved || refused ? (
					<p className="aggr-trail__message">{ event.message }</p>
				) : null }
				<p className="aggr-trail__meta">
					<Actor event={ event } />
					<time dateTime={ stamp( event ) }>{ event.time_text }</time>
					{ entry.count > 1 ? (
						<span className="aggr-trail__repeat">
							{ t( 'activityRepeated' )
								.replace( '%1$d', String( entry.count ) )
								.replace( '%2$s', entry.last.created_text ) }
						</span>
					) : null }
					{ refused ? (
						<span className="aggr-state aggr-state--attention">
							{ event.outcome_label }
						</span>
					) : null }
				</p>
			</div>
		</li>
	);
}

export function Activity( { events }: { events: AuditEvent[] } ): ReactElement {
	const [ all, setAll ] = useState( false );
	const entries = collapse( events );
	const shown = all ? entries : entries.slice( 0, VISIBLE );
	const hidden = entries.length - shown.length;

	return (
		<div className="aggr-trail">
			{ byDay( shown ).map( ( [ day, items ] ) => (
				<section
					className="aggr-trail__day"
					key={ `${ day }-${ items[ 0 ]?.first.created_at ?? 0 }` }
				>
					<h3 className="aggr-trail__date">{ day }</h3>
					<ol className="aggr-trail__list">
						{ items.map( ( entry ) => (
							<Row
								key={ `${ entry.first.created_at }-${ entry.first.message }` }
								entry={ entry }
							/>
						) ) }
					</ol>
				</section>
			) ) }

			{ entries.length > VISIBLE ? (
				<button
					type="button"
					className="aggr-button aggr-button--secondary aggr-button--small aggr-trail__more"
					aria-expanded={ all }
					onClick={ () => setAll( ! all ) }
				>
					{ all
						? t( 'activityShowFewer' )
						: t( 'activityShowAll' ).replace(
								'%d',
								String( hidden )
						  ) }
				</button>
			) : null }
		</div>
	);
}
