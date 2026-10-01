/**
 * The review queue: tabs, one page of campaigns, and paging.
 *
 * **The shell is the plugin's own design system; the table is DataViews.** That
 * split is the decision this file used to say had not been made — swapping the
 * design system was called out here as a visual-direction choice rather than
 * part of moving writes to REST, and it has now been made deliberately.
 *
 * The queue is a list of records, which is the one thing DataViews is for, and
 * it arrives with the search, column and pagination chrome every other staff
 * screen already has. Everything around it — tabs, panel, page head — stays in
 * `src/styles/admin.css`, which is contrast-gated and is what keeps
 * this screen looking like the portal rather than like stock wp-admin. The
 * status pill survives inside the table through the field's `render`. The
 * dialog is core's `Modal`, as on every other staff screen.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import {
	Button,
	Modal,
	SelectControl,
	TextControl,
} from '@wordpress/components';
import { QueueTable } from './queue-table';
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
	advertisers,
	busy,
	onClose,
	onCreate,
}: {
	advertisers: Advertiser[];
	busy: boolean;
	onClose: () => void;
	onCreate: ( orgId: number, title: string ) => void;
} ): ReactElement {
	const [ orgId, setOrgId ] = useState( 0 );
	const [ title, setTitle ] = useState( '' );

	return (
		<Modal
			title={ t( 'createForAdvertiser' ) }
			className="aggr-review-modal"
			focusOnMount="firstContentElement"
			onRequestClose={ onClose }
		>
			{ 0 === advertisers.length ? (
				<p>{ t( 'noAdvertisers' ) }</p>
			) : (
				<div className="aggr-review-modal__fields">
					<SelectControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ t( 'advertiserLabel' ) }
						value={ String( orgId ) }
						disabled={ busy }
						options={ [
							{ value: '0', label: t( 'advertiserChoose' ) },
							...advertisers.map( ( advertiser ) => ( {
								value: String( advertiser.id ),
								label: advertiser.name,
							} ) ),
						] }
						onChange={ ( value ) => setOrgId( Number( value ) ) }
					/>

					<TextControl
						__next40pxDefaultSize
						__nextHasNoMarginBottom
						label={ t( 'campaignNameLabel' ) }
						help={ t( 'campaignNameHint' ) }
						value={ title }
						disabled={ busy }
						onChange={ setTitle }
					/>
				</div>
			) }

			<div className="aggr-review-modal__actions">
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
					disabled={ busy }
				>
					{ t( 'cancel' ) }
				</Button>
				{ 0 < advertisers.length ? (
					<Button
						__next40pxDefaultSize
						variant="primary"
						disabled={ busy || 0 === orgId }
						onClick={ () => onCreate( orgId, title ) }
					>
						{ t( 'createAndOpen' ) }
					</Button>
				) : null }
			</div>
		</Modal>
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
			{ /*
			 * The same header every other Advertising screen gets from
			 * `Screen_Shell`, rendered here because the queue and the campaign
			 * view swap headers without a page load. The classes are the
			 * contract; `admin-native.css` draws them for both.
			 */ }
			<div className="aggr-admin-head">
				<div className="aggr-admin-head__text">
					<p className="aggr-admin-head__eyebrow">
						<span
							className="aggr-admin-head__mark"
							aria-hidden="true"
						></span>
						{ t( 'queueSection' ) }
					</p>
					<h1 className="aggr-admin-head__title">
						{ t( 'queueTitle' ) }
					</h1>
					<p className="aggr-admin-head__purpose">
						{ t( 'queueLede' ) }
					</p>
				</div>

				<div className="aggr-admin-head__actions">
					{ /*
					 * Graphite, the primary colour — not the green positive
					 * button. Green is approval's, the one edge that puts a
					 * campaign in front of the public; creating a draft is not
					 * that, and borrowing its colour dilutes what it means.
					 */ }
					<button
						type="button"
						className="aggr-button"
						onClick={ () => setCreating( true ) }
					>
						{ t( 'createCampaign' ) }
					</button>
				</div>
			</div>

			{ creating ? (
				<CreateDialog
					advertisers={ advertisers }
					busy={ busy }
					onClose={ () => setCreating( false ) }
					onCreate={ onCreate }
				/>
			) : null }

			<Tabs tabs={ tabs } active={ filter } onSelect={ onFilter } />

			<section
				className="aggr-panel aggr-panel--flush"
				aria-labelledby="aggr-queue-heading"
			>
				{ /*
				 * Named for assistive technology and hidden from sight. The count
				 * is already on the selected tab directly above, and printing it
				 * again as a heading put a second "Campaigns (1)" and a band of
				 * empty space between the tabs and the table.
				 */ }
				<h2 id="aggr-queue-heading" className="screen-reader-text">
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
					<QueueTable
						queue={ queue }
						busy={ busy }
						onPage={ onPage }
						onOpen={ onOpen }
					/>
				) }
			</section>
		</>
	);
}
