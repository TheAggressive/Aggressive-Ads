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
import { useState } from '@wordpress/element';
import { Dialog } from './dialog';
import { t } from '../shared/save';
import type { Advertiser, Queue, Tab } from './types';

/**
 * Creating a campaign for an advertiser.
 *
 * The advertiser is chosen here and nowhere else. Every other campaign write
 * in the plugin reads the organization off an object that already has one, so
 * this is the single point where organization identity comes from input — the
 * reason the server re-checks the capability and the organization rather than
 * trusting that this dialog was only shown to staff.
 */
function CreateDialog( {
	open,
	advertisers,
	busy,
	onClose,
	onCreate,
}: {
	open: boolean;
	advertisers: Advertiser[];
	busy: boolean;
	onClose: () => void;
	onCreate: ( orgId: number, title: string ) => void;
} ): ReactElement {
	const [ orgId, setOrgId ] = useState( 0 );
	const [ title, setTitle ] = useState( '' );

	return (
		<Dialog
			open={ open }
			title={ t( 'createForAdvertiser' ) }
			labelId="aggr-create-campaign-title"
			onClose={ onClose }
		>
			{ 0 === advertisers.length ? (
				<p>{ t( 'noAdvertisers' ) }</p>
			) : (
				<div className="aggr-form">
					<p className="aggr-field">
						<label htmlFor="aggr-create-org">
							{ t( 'advertiserLabel' ) }
						</label>
						<select
							id="aggr-create-org"
							value={ orgId }
							disabled={ busy }
							onChange={ ( event ) =>
								setOrgId( Number( event.target.value ) )
							}
						>
							<option value={ 0 }>
								{ t( 'advertiserChoose' ) }
							</option>
							{ advertisers.map( ( advertiser ) => (
								<option
									key={ advertiser.id }
									value={ advertiser.id }
								>
									{ advertiser.name }
								</option>
							) ) }
						</select>
					</p>

					<p className="aggr-field">
						<label htmlFor="aggr-create-title">
							{ t( 'campaignNameLabel' ) }
						</label>
						<input
							id="aggr-create-title"
							type="text"
							value={ title }
							disabled={ busy }
							onChange={ ( event ) =>
								setTitle( event.target.value )
							}
						/>
						<span className="aggr-hint">
							{ t( 'campaignNameHint' ) }
						</span>
					</p>

					<div className="aggr-overlay__actions">
						<button
							type="button"
							className="aggr-button aggr-button--secondary"
							onClick={ onClose }
							disabled={ busy }
						>
							{ t( 'cancel' ) }
						</button>
						<button
							type="button"
							className="aggr-button aggr-button--positive"
							disabled={ busy || 0 === orgId }
							onClick={ () => onCreate( orgId, title ) }
						>
							{ t( 'createAndOpen' ) }
						</button>
					</div>
				</div>
			) }
		</Dialog>
	);
}

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
	advertisers,
	busy,
	onCreate,
}: {
	tabs: Tab[];
	queue: Queue;
	filter: string;
	onFilter: ( key: string ) => void;
	onPage: ( page: number ) => void;
	onOpen: ( id: number ) => void;
	advertisers: Advertiser[];
	busy: boolean;
	onCreate: ( orgId: number, title: string ) => void;
} ): ReactElement {
	const [ creating, setCreating ] = useState( false );

	return (
		<>
			<header className="aggr-pagehead">
				<div>
					<h1 className="aggr-title">{ t( 'queueTitle' ) }</h1>
					<p className="aggr-lede">{ t( 'queueLede' ) }</p>
				</div>

				<div className="aggr-pagehead__actions">
					<button
						type="button"
						className="aggr-button aggr-button--positive"
						onClick={ () => setCreating( true ) }
					>
						{ t( 'createCampaign' ) }
					</button>
				</div>
			</header>

			<CreateDialog
				open={ creating }
				advertisers={ advertisers }
				busy={ busy }
				onClose={ () => setCreating( false ) }
				onCreate={ onCreate }
			/>

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
