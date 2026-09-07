/**
 * The review queue as a DataViews table.
 *
 * **Server-paged, and therefore not sorted here.** `Campaign_Repository`
 * states its position plainly — "paged server-side, always … an advertiser
 * with four hundred campaigns is the one that decides whether this page
 * loads" — and the review queue shares that query. Sorting or searching the
 * twenty rows the server sent would order a page rather than a queue, and a
 * control that silently means something narrower than it says is worse than no
 * control.
 *
 * So the pagination is handed to DataViews rather than computed by it, and the
 * sort and search affordances stay off until the server can answer them.
 * Honest sorting is a server change, not a component swap.
 *
 * The status pill is rendered by this plugin's own design system through the
 * field's `render`, which is what keeps the queue looking like the rest of the
 * portal rather than like stock wp-admin.
 */

import type { ReactElement } from 'react';
import { DataViews } from '@wordpress/dataviews';
import { useMemo, useState } from '@wordpress/element';

import { t } from '../shared/save';

import type {
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { Queue, QueueRow } from './types';

export function QueueTable( {
	queue,
	busy,
	onPage,
	onOpen,
}: {
	queue: Queue;
	busy: boolean;
	onPage: ( page: number ) => void;
	onOpen: ( id: number ) => void;
} ): ReactElement {
	const [ view, setView ] = useState< DataView >( {
		type: 'table',
		search: '',
		page: queue.page,
		perPage: queue.rows.length > 0 ? queue.rows.length : 20,
		filters: [],
		titleField: 'title',
		fields: [
			'org_name',
			'placements',
			'pill',
			'submitted_text',
			'reviewer',
			'pending_updates',
		],
		layout: {},
	} );

	const fields: DataField< QueueRow >[] = useMemo(
		() => [
			{
				id: 'title',
				label: t( 'colCampaign' ),
				enableSorting: false,
				render: ( { item }: { item: QueueRow } ) => (
					<button
						type="button"
						className="aggr-linkbutton"
						onClick={ () => onOpen( item.id ) }
					>
						{ item.title }
					</button>
				),
			},
			{
				id: 'org_name',
				label: t( 'colAdvertiser' ),
				enableSorting: false,
			},
			{
				id: 'placements',
				label: t( 'colPlacement' ),
				enableSorting: false,
				render: ( { item }: { item: QueueRow } ) => (
					<>{ item.placements.join( ', ' ) }</>
				),
			},
			{
				/*
				 * The pill, not the word. Status is the column a reviewer scans
				 * down, and this plugin already has a component that makes it
				 * scannable — reusing it through `render` is what keeps the
				 * queue in the portal's design language while the table around
				 * it is core's.
				 */
				id: 'pill',
				label: t( 'colStatus' ),
				enableSorting: false,
				render: ( { item }: { item: QueueRow } ) => (
					<span className={ `aggr-pill aggr-pill--${ item.pill }` }>
						{ item.status_text }
					</span>
				),
			},
			{
				id: 'submitted_text',
				label: t( 'colSubmitted' ),
				enableSorting: false,
				render: ( { item }: { item: QueueRow } ) => (
					<>
						{ '' === item.submitted_text
							? '—'
							: item.submitted_text }
					</>
				),
			},
			{
				id: 'reviewer',
				label: t( 'colReviewer' ),
				enableSorting: false,
				render: ( { item }: { item: QueueRow } ) => (
					<>
						{ '' === item.reviewer
							? t( 'unassigned' )
							: item.reviewer }
					</>
				),
			},
			{
				id: 'pending_updates',
				label: t( 'colUpdates' ),
				enableSorting: false,
			},
		],
		[ onOpen ]
	);

	return (
		<DataViews< QueueRow >
			data={ queue.rows }
			fields={ fields }
			view={ view }
			onChangeView={ ( next: DataView ) => {
				setView( next );

				// Paging is the server's answer, so a page change is a request
				// rather than a slice of what is already here.
				if ( next.page && next.page !== queue.page ) {
					onPage( next.page );
				}
			} }
			paginationInfo={ {
				totalItems: queue.total,
				totalPages: queue.pages,
			} }
			defaultLayouts={ { table: {} } }
			actions={ [] }
			getItemId={ ( item ) => String( item.id ) }
			/*
			 * **Every tab and page change is a REST round trip**, because the
			 * queue is paged and filtered by the server. Hardcoding this false
			 * told DataViews a fetch was never in flight, so the table sat
			 * showing the previous tab's rows with no indication anything was
			 * happening — which reads as lag rather than as loading.
			 */
			isLoading={ busy }
			search={ false }
		/>
	);
}
