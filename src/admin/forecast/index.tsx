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
import { State, type Tone } from '../shared/state';
import { Icon, type IconName } from '../shared/icon';
import { sameOriginUrl } from '../shared/navigate';

import type {
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { ForecastPayload, ForecastRow, ForecastTotals } from './types';

/*
 * The server's verdict, drawn as a pill. Oversold is the row somebody has to
 * act on, so it is the one in the danger tone; a placement nobody has measured
 * is neither good nor bad, so it stays neutral rather than borrowing a warning.
 */
const VERDICT_TONE: Record< ForecastRow[ 'verdict' ], Tone > = {
	available: 'live',
	oversell: 'danger',
	unknown: 'neutral',
};

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

/** One summary card, labelled by an icon of the thing it counts. */
const Card = ( {
	label,
	value,
	icon,
	alarm = false,
}: {
	label: string;
	value: string;
	icon: IconName;
	alarm?: boolean;
} ): ReactElement => (
	<div className="aggr-forecast__card">
		<p className="aggr-forecast__label">
			<Icon name={ icon } size={ 16 } />
			{ label }
		</p>
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
	placementsUrl,
}: {
	rows: ForecastRow[];
	totals: ForecastTotals;
	window: { from: string; to: string };
	placementsUrl?: string;
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
				/*
				 * Booked, drawn against what the window is forecast to offer.
				 * The bar is how full the placement is; the number is still
				 * the answer, and the bar is hidden from assistive technology
				 * because it adds nothing the figures do not. No forecast, no
				 * bar: an empty track would say "nothing sold" about a
				 * placement nobody has measured.
				 */
				render: ( { item } ) => (
					<span className="aggr-rate">
						{ null === item.forecast ||
						item.forecast <= 0 ? null : (
							<span
								className={
									'oversell' === item.verdict
										? 'aggr-meter aggr-meter--inline aggr-meter--over'
										: 'aggr-meter aggr-meter--inline'
								}
								aria-hidden="true"
							>
								<span
									className="aggr-meter__fill"
									style={ {
										width: `${
											Math.min(
												1,
												item.committed / item.forecast
											) * 100
										}%`,
									} }
								/>
							</span>
						) }
						{ item.committed.toLocaleString() }
					</span>
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
					<State tone={ VERDICT_TONE[ item.verdict ] }>
						{ t( item.verdict ) }
					</State>
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

	/*
	 * placementsUrl is text from data-aggr-forecast. An href of that text runs
	 * a javascript: scheme on click, so the link exists only for an address on
	 * this origin.
	 */
	const placementsHref = placementsUrl
		? sameOriginUrl( placementsUrl )
		: null;

	return (
		<>
			{ /*
			 * What the figures cover, and the way back to the catalogue they
			 * are about. The window and the inventory kind are fixed by the
			 * server, so they are stated rather than left for the reader to
			 * assume.
			 */ }
			<div className="aggr-forecast__context">
				<p className="aggr-forecast__window">
					{ t( 'window' ) }: { range.from } – { range.to } ·{ ' ' }
					{ t( 'pageOnly' ) }
				</p>
				{ placementsHref ? (
					<a className="aggr-forecast__link" href={ placementsHref }>
						<Icon name="placements" size={ 16 } />
						{ t( 'managePlacements' ) }
					</a>
				) : null }
			</div>

			<div className="aggr-forecast__cards">
				<Card
					label={ t( 'placements' ) }
					icon="placements"
					value={ totals.placements.toLocaleString() }
				/>
				<Card
					label={ t( 'forecast' ) }
					icon="forecast"
					value={ totals.forecast.toLocaleString() }
				/>
				<Card
					label={ t( 'committed' ) }
					icon="booked"
					value={ totals.committed.toLocaleString() }
				/>
				<Card
					label={ t( 'oversold' ) }
					icon="warning"
					value={ totals.oversold.toLocaleString() }
					alarm={ totals.oversold > 0 }
				/>
				<Card
					label={ t( 'unforecast' ) }
					icon="unknown"
					value={ totals.unforecast.toLocaleString() }
				/>
			</div>

			{ /*
			 * Focusable, because a region that scrolls has to be reachable by
			 * keyboard — axe reports `scrollable-region-focusable` otherwise.
			 * Named, because a focus stop that announces nothing is one a screen
			 * reader user has to explore to identify.
			 */ }
			<div
				className="aggr-scroll-region"
				role="region"
				tabIndex={ 0 }
				aria-label={ t( 'region' ) }
			>
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
				placementsUrl={ payload.placementsUrl }
			/>
		);
	}
}
