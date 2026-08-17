/**
 * The review queue: tabs, one page of campaigns, and paging.
 *
 * Rendered in the plugin's own design system rather than core's component set,
 * unlike the other converted admin screens. That is deliberate:
 * `src/styles/admin.css` exists for these two screens and is contrast-gated,
 * and swapping it for wp-components would be a decision about the product's
 * visual direction rather than part of moving the writes to REST.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import { t } from '../shared/save';
import type { Queue, Tab } from './types';

/**
 * The tab strip, with the count each filter is currently holding.
 */
function Tabs( {
	tabs,
	active,
	onSelect,
}: {
	tabs: Tab[];
	active: string;
	onSelect: ( key: string ) => void;
} ): ReactElement {
	return (
		<nav className="aggr-tabs" aria-label={ t( 'tabsLabel' ) }>
			{ tabs.map( ( tab ) => (
				<button
					key={ tab.key }
					type="button"
					className="aggr-tab"
					// aria-current, not aria-selected: these are still links
					// between views in the page's own sense, and the markup they
					// replaced used aria-current. A screen reader user who knows
					// this screen should not have to relearn it.
					aria-current={ tab.key === active ? 'page' : undefined }
					onClick={ () => onSelect( tab.key ) }
				>
					{ tab.label }
					<span className="aggr-tab__count">{ tab.count }</span>
				</button>
			) ) }
		</nav>
	);
}

/**
 * Previous/next paging.
 *
 * Deliberately not a numbered list. `paginate_links()` produced one server-side,
 * and reproducing its ellipsis logic in TSX would be a second implementation of
 * something nobody asked to change; two controls and a position read the same
 * to a screen reader and cannot drift.
 */
function Pages( {
	queue,
	onPage,
}: {
	queue: Queue;
	onPage: ( page: number ) => void;
} ): ReactElement | null {
	if ( queue.pages <= 1 ) {
		return null;
	}

	return (
		<nav className="aggr-pagination" aria-label={ t( 'pagesLabel' ) }>
			<button
				type="button"
				className="aggr-button aggr-button--secondary"
				disabled={ queue.page <= 1 }
				onClick={ () => onPage( queue.page - 1 ) }
			>
				{ t( 'previous' ) }
			</button>
			<span aria-live="polite">
				{ t( 'pageOf' )
					.replace( '%1$s', String( queue.page ) )
					.replace( '%2$s', String( queue.pages ) ) }
			</span>
			<button
				type="button"
				className="aggr-button aggr-button--secondary"
				disabled={ queue.page >= queue.pages }
				onClick={ () => onPage( queue.page + 1 ) }
			>
				{ t( 'next' ) }
			</button>
		</nav>
	);
}

export function QueueView( {
	tabs,
	queue,
	filter,
	onFilter,
	onPage,
	onOpen,
}: {
	tabs: Tab[];
	queue: Queue;
	filter: string;
	onFilter: ( key: string ) => void;
	onPage: ( page: number ) => void;
	onOpen: ( id: number ) => void;
} ): ReactElement {
	return (
		<>
			<header className="aggr-pagehead">
				<div>
					<h1 className="aggr-title">{ t( 'queueTitle' ) }</h1>
					<p className="aggr-lede">{ t( 'queueLede' ) }</p>
				</div>
			</header>

			<Tabs tabs={ tabs } active={ filter } onSelect={ onFilter } />

			<section
				className="aggr-panel"
				aria-labelledby="aggr-queue-heading"
			>
				<h2 id="aggr-queue-heading" className="aggr-panel__head">
					{ t( 'campaignsCount' ).replace(
						'%s',
						String( queue.total )
					) }
				</h2>

				{ 0 === queue.rows.length ? (
					<div className="aggr-empty">
						<h3 className="aggr-empty__title">
							{ t( 'queueEmptyTitle' ) }
						</h3>
						<p>{ t( 'queueEmptyBody' ) }</p>
					</div>
				) : (
					<div
						className="aggr-tablewrap"
						role="region"
						aria-label={ t( 'queueTableLabel' ) }
						tabIndex={ 0 }
					>
						<table className="aggr-table aggr-review-table">
							<thead>
								<tr>
									<th scope="col">{ t( 'colCampaign' ) }</th>
									<th scope="col">
										{ t( 'colAdvertiser' ) }
									</th>
									<th scope="col">{ t( 'colPlacement' ) }</th>
									<th scope="col">{ t( 'colStatus' ) }</th>
									<th scope="col">{ t( 'colSubmitted' ) }</th>
									<th scope="col">{ t( 'colReviewer' ) }</th>
									<th scope="col">{ t( 'colUpdates' ) }</th>
								</tr>
							</thead>
							<tbody>
								{ queue.rows.map( ( row ) => (
									<tr key={ row.id }>
										<td className="aggr-table__primary">
											<button
												type="button"
												className="aggr-linkbutton"
												onClick={ () =>
													onOpen( row.id )
												}
											>
												{ row.title }
											</button>
										</td>
										<td>{ row.org_name }</td>
										<td>{ row.placements.join( ', ' ) }</td>
										<td>
											<span
												className={ `aggr-pill aggr-pill--${ row.pill }` }
											>
												{ row.status_text }
											</span>
										</td>
										<td>
											{ '' === row.submitted_text
												? '—'
												: row.submitted_text }
										</td>
										<td>
											{ '' === row.reviewer
												? t( 'unassigned' )
												: row.reviewer }
										</td>
										<td>{ row.pending_updates }</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>
				) }
			</section>

			<Pages queue={ queue } onPage={ onPage } />
		</>
	);
}
