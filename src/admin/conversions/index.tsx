/**
 * The conversion definitions screen.
 *
 * Core's component set, like Settings and Packages — this screen is ordinary
 * WordPress admin furniture, not the plugin's own design system, so there is
 * nothing here for `admin.css` to fight.
 *
 * Every write goes to `REST\Conversion_Definitions_Controller`, which is thin
 * over `Conversion_Definition_Manager`. There is no second rule set in this
 * file, and there must not be: the manager is what the integration tests
 * exercise, so anything decided here would be untested by construction.
 */

import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { createRoot } from '@wordpress/element';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { Credentials } from './credentials';

import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { Definition, Payload } from './types';

/** A blank definition, in the shape the REST route accepts. */
const emptyDraft = () => ( {
	name: '',
	org_id: 0,
	window_seconds: 2592000,
	default_value_micros: 0,
	currency: '',
	allow_s2s: false,
	status: 'active',
} );

/**
 * Micros in, a decimal string out.
 *
 * Value is stored in millionths of a currency unit because a per-click value is
 * routinely smaller than a cent. Editors think in currency, so the conversion
 * happens at the edge rather than anywhere a total could be computed from it.
 */
const microsToAmount = ( micros: number ): string =>
	micros > 0 ? ( micros / 1000000 ).toFixed( 2 ) : '';

const amountToMicros = ( amount: string ): number => {
	const parsed = Number.parseFloat( amount );

	return Number.isFinite( parsed ) && parsed > 0
		? Math.round( parsed * 1000000 )
		: 0;
};

/**
 * Newest first: a definition is created and then immediately looked for.
 *
 * `accepts_reports` is the status column rather than `status`, because that is
 * the field the rest of the plugin decides on — an archived definition and one
 * whose window has closed are both "not accepting", and a table sorted on the
 * stored slug would separate them.
 */
const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	filters: [],
	titleField: 'name',
	fields: [ 'public_key', 'window_seconds', 'allow_s2s', 'state' ],
	layout: {},
};

function Screen( { payload }: { payload: Payload } ) {
	const { restPath, credentialsPath, windows, advertisers, i18n } = payload;

	const [ definitions, setDefinitions ] = useState< Definition[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ draft, setDraft ] = useState( emptyDraft );
	const [ saving, setSaving ] = useState( false );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );

	useEffect( () => {
		apiFetch< { definitions: Definition[] } >( { path: restPath } )
			.then( ( result ) => setDefinitions( result.definitions ?? [] ) )
			.catch( () => setError( i18n.loadFailed ) )
			.finally( () => setLoading( false ) );
	}, [ restPath, i18n.loadFailed ] );

	const create = async () => {
		setSaving( true );
		setError( '' );

		try {
			const result = await apiFetch< { definition: Definition } >( {
				path: restPath,
				method: 'POST',
				data: draft,
			} );

			setDefinitions( [ result.definition, ...definitions ] );
			setDraft( emptyDraft() );
		} catch ( failure ) {
			// The manager's message is already translated and already written
			// for the person reading it, so it travels unchanged.
			const message = ( failure as { message?: string } )?.message;
			setError( message || i18n.saveFailed );
		} finally {
			setSaving( false );
		}
	};

	const archive = async ( definition: Definition ) => {
		setError( '' );

		try {
			const result = await apiFetch< { definition: Definition } >( {
				path: `${ restPath }/${ definition.id }`,
				method: 'PATCH',
				data: {
					name: definition.name,
					org_id: definition.org_id,
					window_seconds: definition.window_seconds,
					default_value_micros: definition.default_value_micros,
					currency: definition.currency,
					allow_s2s: definition.allow_s2s,
					status: 'archived',
					revision: definition.revision,
				},
			} );

			setDefinitions(
				definitions.map( ( row ) =>
					row.id === result.definition.id ? result.definition : row
				)
			);
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message;
			setError( message || i18n.saveFailed );
		}
	};

	const fields: DataField< Definition >[] = useMemo(
		() => [
			{
				id: 'name',
				label: i18n.name,
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'public_key',
				label: i18n.snippetKey,
				type: 'text',
				enableSorting: false,
				enableGlobalSearch: true,
				render: ( { item }: { item: Definition } ) => (
					<code>{ item.public_key }</code>
				),
			},
			{
				// Sorted on the stored seconds and rendered in days, so seven
				// days does not sort after thirty the way "7" sorts after "30".
				id: 'window_seconds',
				label: i18n.window,
				type: 'integer',
				getValue: ( { item }: { item: Definition } ) =>
					item.window_seconds,
				render: ( { item }: { item: Definition } ) =>
					`${ Math.round( item.window_seconds / 86400 ) } ${
						i18n.days
					}`,
			},
			{
				id: 'allow_s2s',
				label: i18n.serverReports,
				elements: [
					{ value: 'yes', label: i18n.yes },
					{ value: 'no', label: i18n.no },
				],
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Definition } ) =>
					item.allow_s2s ? 'yes' : 'no',
			},
			{
				id: 'state',
				label: i18n.status,
				elements: [
					{ value: 'active', label: i18n.active },
					{ value: 'archived', label: i18n.archived },
				],
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Definition } ) =>
					item.accepts_reports ? 'active' : 'archived',
			},
		],
		[ i18n ]
	);

	const actions: Action< Definition >[] = useMemo(
		() => [
			{
				id: 'archive',
				label: i18n.archive,
				isDestructive: true,
				supportsBulk: false,
				isEligible: ( item: Definition ) => item.accepts_reports,
				callback: ( items: Definition[] ) => {
					const item = items[ 0 ];

					if ( item ) {
						void archive( item );
					}
				},
			},
		],
		// `archive` is recreated every render and closes over `definitions`
		// only to replace the row it changed; listing it here would rebuild the
		// action menu on every keystroke in the form above.
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ i18n, restPath ]
	);

	const { data: rows, paginationInfo } = useMemo(
		() => filterSortAndPaginate( definitions, view, fields ),
		[ definitions, view, fields ]
	);

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<>
			{ '' !== error ? (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) : null }

			<Card>
				<CardHeader>
					<h2>{ i18n.newDefinition }</h2>
				</CardHeader>
				<CardBody>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.name }
						value={ draft.name }
						onChange={ ( name ) => setDraft( { ...draft, name } ) }
					/>

					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.window }
						help={ i18n.windowHelp }
						value={ String( draft.window_seconds ) }
						options={ windows }
						onChange={ ( value ) =>
							setDraft( {
								...draft,
								window_seconds: Number( value ),
							} )
						}
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.value }
						help={ i18n.valueHelp }
						value={ microsToAmount( draft.default_value_micros ) }
						onChange={ ( amount ) =>
							setDraft( {
								...draft,
								default_value_micros: amountToMicros( amount ),
							} )
						}
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.currency }
						help={ i18n.currencyHelp }
						value={ draft.currency }
						maxLength={ 3 }
						onChange={ ( currency ) =>
							setDraft( {
								...draft,
								currency: currency.toUpperCase(),
							} )
						}
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ i18n.orgScoped }
						help={ i18n.orgScopedHelp }
						checked={ draft.org_id > 0 }
						onChange={ ( scoped ) =>
							setDraft( { ...draft, org_id: scoped ? 1 : 0 } )
						}
					/>

					{ draft.org_id > 0 ? (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ i18n.orgId }
							type="number"
							value={ String( draft.org_id ) }
							onChange={ ( value ) =>
								setDraft( {
									...draft,
									org_id: Number( value ) || 0,
								} )
							}
						/>
					) : null }

					<ToggleControl
						__nextHasNoMarginBottom
						label={ i18n.allowS2s }
						help={ i18n.allowS2sHelp }
						checked={ draft.allow_s2s }
						onChange={ ( allowS2s ) =>
							setDraft( { ...draft, allow_s2s: allowS2s } )
						}
					/>

					<Button
						variant="primary"
						onClick={ create }
						isBusy={ saving }
						disabled={ saving || '' === draft.name.trim() }
					>
						{ i18n.create }
					</Button>
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2>{ i18n.existing }</h2>
				</CardHeader>
				<CardBody>
					<DataViews< Definition >
						data={ rows }
						fields={ fields }
						view={ view }
						onChangeView={ setView }
						actions={ actions }
						paginationInfo={ paginationInfo }
						getItemId={ ( item ) => String( item.id ) }
						defaultLayouts={ { table: {} } }
						searchLabel={ i18n.searchDefinitions }
						empty={ <p>{ i18n.none }</p> }
					/>
				</CardBody>
			</Card>

			<Credentials
				path={ credentialsPath }
				advertisers={ advertisers }
				i18n={ i18n }
			/>
		</>
	);
}

const root = document.getElementById( 'aggr-conversions-root' );

if ( root ) {
	const raw = root.dataset.aggrConversions ?? '{}';
	createRoot( root ).render(
		<Screen payload={ JSON.parse( raw ) as Payload } />
	);
}
