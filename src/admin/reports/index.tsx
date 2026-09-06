/**
 * Utilisation tables on the reports screen.
 *
 * **Only the two utilisation tables.** The headline tiles, the no-fill reason
 * breakdown, the window filter and the CSV export stay server-rendered, so the
 * numbers a person opens this page for are still readable with JavaScript off.
 * Reports is the screen somebody opens when something is already wrong, and
 * making all of it depend on a bundle is the wrong trade for that screen.
 *
 * These two are here because sorting is the question they exist to answer:
 * "which placements are underselling" is a sort by fill rate, and a static
 * table cannot answer it.
 */

import type { ReactElement } from 'react';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { createRoot, useMemo, useState } from '@wordpress/element';

import './style.css';

import type {
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import { EMPTY, type GroupRow, type Payload, type PlacementRow } from './types';

/** Strings come from PHP; Script Modules cannot carry translations below 7.0. */
let strings: Record< string, string > = {};

const t = ( key: string ): string => strings[ key ] ?? key;

/**
 * A rate as a percentage, or an em dash when there is no denominator.
 *
 * The em dash is the whole point. `null` means nothing was requested, and a
 * placement nobody asked for did not fail to fill — rendering "0%" for it
 * reports a problem that does not exist.
 */
const rate = ( value: number | null ): string =>
	null === value ? '—' : `${ ( value * 100 ).toFixed( 1 ) }%`;

const count = ( value: number ): string => value.toLocaleString();

const baseView: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	filters: [],
	layout: {},
};

function Placements( { rows }: { rows: PlacementRow[] } ): ReactElement {
	const [ view, setView ] = useState< DataView >( {
		...baseView,
		// Worst utilisation first: the reason to open this table is to find
		// what is not selling, not to admire what is.
		sort: { field: 'fill_rate', direction: 'asc' },
		titleField: 'name',
		fields: [ 'groups', 'requests', 'fills', 'fill_rate' ],
	} );

	const groups = useMemo(
		() =>
			Array.from(
				new Set( rows.flatMap( ( row ) => row.groups ) )
			).sort(),
		[ rows ]
	);

	const fields: DataField< PlacementRow >[] = useMemo(
		() => [
			{ id: 'name', label: t( 'placement' ), enableGlobalSearch: true },
			{
				id: 'groups',
				label: t( 'groups' ),
				enableGlobalSearch: true,
				elements: groups.map( ( slug ) => ( {
					value: slug,
					label: slug,
				} ) ),
				filterBy: { operators: [ 'contains' ] },
				getValue: ( { item }: { item: PlacementRow } ) =>
					item.groups.join( ' ' ),
				render: ( { item }: { item: PlacementRow } ) => (
					<>
						{ 0 === item.groups.length
							? '—'
							: item.groups.join( ', ' ) }
					</>
				),
			},
			{
				id: 'requests',
				label: t( 'requests' ),
				type: 'integer',
				render: ( { item }: { item: PlacementRow } ) => (
					<>{ count( item.requests ) }</>
				),
			},
			{
				id: 'fills',
				label: t( 'fills' ),
				type: 'integer',
				render: ( { item }: { item: PlacementRow } ) => (
					<>{ count( item.fills ) }</>
				),
			},
			{
				/*
				 * Sorted numerically on the raw rate, rendered as a percentage.
				 * Sorting the formatted string would order 9.0% after 80.0%,
				 * which is the one thing this column exists to get right.
				 */
				id: 'fill_rate',
				label: t( 'utilisation' ),
				type: 'number',
				render: ( { item }: { item: PlacementRow } ) => (
					<>{ rate( item.fill_rate ) }</>
				),
			},
		],
		[ groups ]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);

	return (
		<div className="aggr-reports-utilisation">
			<DataViews< PlacementRow >
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				actions={ [] }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ false }
			/>
		</div>
	);
}

function Groups( { rows }: { rows: GroupRow[] } ): ReactElement | null {
	const [ view, setView ] = useState< DataView >( {
		...baseView,
		sort: { field: 'fill_rate', direction: 'asc' },
		titleField: 'slug',
		fields: [ 'placements', 'requests', 'fills', 'fill_rate' ],
	} );

	const fields: DataField< GroupRow >[] = useMemo(
		() => [
			{ id: 'slug', label: t( 'group' ), enableGlobalSearch: true },
			{
				id: 'placements',
				label: t( 'placementCount' ),
				type: 'integer',
				render: ( { item }: { item: GroupRow } ) => (
					<>{ count( item.placements ) }</>
				),
			},
			{
				id: 'requests',
				label: t( 'requests' ),
				type: 'integer',
				render: ( { item }: { item: GroupRow } ) => (
					<>{ count( item.requests ) }</>
				),
			},
			{
				id: 'fills',
				label: t( 'fills' ),
				type: 'integer',
				render: ( { item }: { item: GroupRow } ) => (
					<>{ count( item.fills ) }</>
				),
			},
			{
				id: 'fill_rate',
				label: t( 'utilisation' ),
				type: 'number',
				render: ( { item }: { item: GroupRow } ) => (
					<>{ rate( item.fill_rate ) }</>
				),
			},
		],
		[]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);

	// No groups is not an empty table, it is a screen with nothing to say.
	if ( 0 === rows.length ) {
		return null;
	}

	return (
		<div className="aggr-reports-utilisation">
			<h2>{ t( 'byGroup' ) }</h2>
			<DataViews< GroupRow >
				data={ data }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				paginationInfo={ paginationInfo }
				defaultLayouts={ { table: {} } }
				actions={ [] }
				getItemId={ ( item ) => item.slug }
				isLoading={ false }
			/>
		</div>
	);
}

function Screen( { payload }: { payload: Payload } ): ReactElement {
	const view = payload.view ?? EMPTY.view;

	return (
		<>
			<Placements rows={ view.placements ?? [] } />
			<Groups rows={ view.groups ?? [] } />
		</>
	);
}

const root = document.getElementById( 'aggr-reports-root' );

if ( root ) {
	const payload = JSON.parse( root.dataset.aggrReports ?? '{}' ) as Payload;

	strings = payload.i18n ?? {};

	createRoot( root ).render( <Screen payload={ payload } /> );
}
