/**
 * The staff inventory outlook.
 *
 * Sorting is the question this screen exists to answer — "which placements are
 * oversold" and "which have room" are both sorts — so the table is DataViews
 * rather than static markup. The summary cards above it stay plain, because
 * they are the numbers somebody opens the page for.
 */

import type { ReactElement } from 'react';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { createRoot, useMemo, useState } from '@wordpress/element';

import './style.css';

import type {
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { ForecastPayload, ForecastRow, ForecastTotals } from './types';

/** Strings come from PHP; Script Modules cannot carry translations below 7.0. */
let strings: Record< string, string > = {};

const t = ( key: string ): string => strings[ key ] ?? key;

/**
 * A count, or a plain sentence when there is no figure.
 *
 * `null` means the placement has never been forecast. Rendering `0` for it
 * would say it is sold out, which is the distinction the whole phase turns on.
 */
const figure = ( value: number | null ): string =>
	null === value ? t( 'noFigure' ) : value.toLocaleString();

/** One summary card. */
const Card = ( {
	label,
	value,
	alarm = false,
}: {
	label: string;
	value: string;
	alarm?: boolean;
} ): ReactElement => (
	<div className="aggr-forecast__card">
		<p className="aggr-forecast__label">{ label }</p>
		<p
			className={
				alarm
					? 'aggr-forecast__figure aggr-forecast__figure--alarm'
					: 'aggr-forecast__figure'
			}
		>
			{ value }
		</p>
	</div>
);

const Outlook = ( {
	rows,
	totals,
	window: range,
}: {
	rows: ForecastRow[];
	totals: ForecastTotals;
	window: { from: string; to: string };
} ): ReactElement => {
	const [ view, setView ] = useState< DataView >( {
		type: 'table',
		page: 1,
		perPage: 25,
		fields: [
			'forecast',
			'committed',
			'remaining',
			'status',
			'confidence',
		],
		titleField: 'name',
	} );

	const fields = useMemo< DataField< ForecastRow >[] >(
		() => [
			{
				id: 'name',
				label: t( 'placement' ),
				enableSorting: true,
				getValue: ( { item } ) => item.name,
			},
			{
				id: 'forecast',
				label: t( 'forecast' ),
				enableSorting: true,
				// Sorted on the number, rendered as a sentence when absent.
				getValue: ( { item } ) => item.forecast ?? -1,
				render: ( { item } ) => (
					<span
						className={
							null === item.forecast
								? 'aggr-forecast__none'
								: undefined
						}
					>
						{ figure( item.forecast ) }
					</span>
				),
			},
			{
				id: 'committed',
				label: t( 'committed' ),
				enableSorting: true,
				getValue: ( { item } ) => item.committed,
				render: ( { item } ) => (
					<>{ item.committed.toLocaleString() }</>
				),
			},
			{
				id: 'remaining',
				label: t( 'remaining' ),
				enableSorting: true,
				getValue: ( { item } ) => item.remaining ?? -1,
				render: ( { item } ) => <>{ figure( item.remaining ) }</>,
			},
			{
				/*
				 * The server has always sent a verdict per row and nothing
				 * rendered it, so the three labels for it shipped to every
				 * staff member unread while the summary above the table
				 * counted oversold placements the rows could not show.
				 */
				id: 'status',
				label: t( 'status' ),
				enableSorting: true,
				getValue: ( { item } ) => item.verdict,
				render: ( { item } ) => (
					<span
						className={
							'oversell' === item.verdict
								? 'aggr-forecast__oversold'
								: undefined
						}
					>
						{ t( item.verdict ) }
					</span>
				),
			},
			{
				id: 'confidence',
				label: t( 'confidence' ),
				enableSorting: true,
				getValue: ( { item } ) => item.confidence,
				render: ( { item } ) => <>{ t( item.confidence ) }</>,
			},
		],
		[]
	);

	const { data, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
	);

	return (
		<>
			<p className="aggr-forecast__window">
				{ t( 'window' ) }: { range.from } – { range.to }
			</p>

			<div className="aggr-forecast__cards">
				<Card
					label={ t( 'placements' ) }
					value={ totals.placements.toLocaleString() }
				/>
				<Card
					label={ t( 'forecast' ) }
					value={ totals.forecast.toLocaleString() }
				/>
				<Card
					label={ t( 'committed' ) }
					value={ totals.committed.toLocaleString() }
				/>
				<Card
					label={ t( 'oversold' ) }
					value={ totals.oversold.toLocaleString() }
					alarm={ totals.oversold > 0 }
				/>
				<Card
					label={ t( 'unforecast' ) }
					value={ totals.unforecast.toLocaleString() }
				/>
			</div>

			{ /*
			 * Focusable, because a region that scrolls has to be reachable by
			 * keyboard — axe reports `scrollable-region-focusable` otherwise.
			 */ }
			<div className="aggr-forecast__table" tabIndex={ 0 }>
				<DataViews< ForecastRow >
					data={ data }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					paginationInfo={ paginationInfo }
					defaultLayouts={ { table: {} } }
					getItemId={ ( item ) => String( item.id ) }
					search={ false }
				/>
			</div>
		</>
	);
};

const mount = document.getElementById( 'aggr-forecast-root' );

if ( mount ) {
	const raw = mount.getAttribute( 'data-aggr-forecast' );

	if ( raw ) {
		const payload: ForecastPayload = JSON.parse( raw );

		strings = payload.i18n ?? {};

		createRoot( mount ).render(
			<Outlook
				rows={ payload.view.rows }
				totals={ payload.view.totals }
				window={ payload.view.window }
			/>
		);
	}
}
