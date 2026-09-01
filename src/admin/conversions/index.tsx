/**
 * The conversion definitions screen.
 *
 * Core's component set, like Settings and Packages — this screen is ordinary
 * WordPress admin furniture, not the plugin's own design system.
 *
 * Every write goes to `REST\Conversion_Definitions_Controller`, which is thin
 * over `Conversion_Definition_Manager`. There is no second rule set in this
 * file, and there must not be: the manager is what the integration tests
 * exercise, so anything decided here would be untested by construction.
 *
 * **The form is a modal opened from the table's own toolbar**, rather than a
 * card standing permanently above the list. A screen whose first element is an
 * empty form opens on work nobody asked to do, and it pushed the thing a person
 * came to read below the fold. This is DataViews' `header` slot, which is where
 * core puts a primary action.
 */

import {
	Button,
	Flex,
	FlexBlock,
	Modal,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { createRoot } from '@wordpress/element';
import { useEffect, useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { Credentials } from './credentials';
import './style.css';

import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { Advertiser, Definition, Payload, Strings } from './types';

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

type Draft = ReturnType< typeof emptyDraft >;

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

/**
 * The create form.
 *
 * A module-level component taking its state as props, like the roster's modals.
 * Nothing here is a DataViews action, so the frozen-action hazard that file
 * documents does not apply — but a form defined inside the screen's render
 * would still remount on every keystroke elsewhere, so the shape is the same.
 */
function DefinitionModal( {
	i18n,
	windows,
	currencies,
	defaultCurrency,
	advertisers,
	saving,
	error,
	onCancel,
	onCreate,
}: {
	i18n: Strings;
	windows: Array< { label: string; value: string } >;
	currencies: Array< { label: string; value: string } >;
	defaultCurrency: string;
	advertisers: Advertiser[];
	saving: boolean;
	error: string;
	onCancel: () => void;
	onCreate: ( draft: Draft ) => void;
} ) {
	const [ draft, setDraft ] = useState< Draft >( emptyDraft );

	const scoped = draft.org_id > 0;
	const amount = microsToAmount( draft.default_value_micros );

	return (
		<Modal
			title={ i18n.newDefinition }
			className="aggr-conversion-modal"
			onRequestClose={ onCancel }
		>
			<VStack spacing={ 4 }>
				{ '' !== error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : null }

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ i18n.name }
					help={ i18n.nameHelp }
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

				{ /*
				   Value and currency are one fact about money, so they are one
				   row. Splitting them into two full-width fields is what let a
				   value be saved with no currency and refused on the server for
				   a reason the form could have prevented.
				*/ }
				<Flex align="flex-start" gap={ 3 }>
					<FlexBlock>
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ i18n.value }
							help={ i18n.valueHelp }
							value={ amount }
							onChange={ ( next ) => {
								const micros = amountToMicros( next );

								setDraft( {
									...draft,
									default_value_micros: micros,

									/*
									 * A value cleared takes its currency with
									 * it, so a definition worth nothing cannot
									 * keep a stale denomination — and stating
									 * one reaches for the site's own, so the
									 * common case is already answered by the
									 * time the field becomes available.
									 */
									currency:
										0 === micros
											? ''
											: draft.currency || defaultCurrency,
								} );
							} }
						/>
					</FlexBlock>
					<FlexBlock>
						{ /*
						   A select, not three characters to type. The column is
						   `char(3)` and the validator is `[A-Z]{3}`, so a text
						   field made "usd", "US$" and a typo all possible and
						   only the last of those is caught by anything. It is
						   required only when a value is set, so it is
						   unavailable until one is — the rule is enforced by the
						   control rather than explained after a failed save.
						*/ }
						<SelectControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ i18n.currency }
							help={
								'' === amount
									? i18n.currencyDisabledHelp
									: i18n.currencyHelp
							}
							disabled={ '' === amount }
							value={ draft.currency }
							options={ currencies }
							onChange={ ( currency ) =>
								setDraft( { ...draft, currency } )
							}
						/>
					</FlexBlock>
				</Flex>

				<ToggleControl
					__nextHasNoMarginBottom
					label={ i18n.orgScoped }
					help={
						0 === advertisers.length
							? i18n.noAdvertisers
							: i18n.orgScopedHelp
					}
					disabled={ 0 === advertisers.length }
					checked={ scoped }
					onChange={ ( on ) =>
						setDraft( {
							...draft,
							org_id: on ? advertisers[ 0 ]?.id ?? 0 : 0,
						} )
					}
				/>

				{ scoped ? (
					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.advertiser }
						// A select, not the post id this used to ask a person
						// to type. Nobody knows an organization by its id, and
						// a mistyped one is a definition credited to the wrong
						// advertiser that looks entirely correct.
						value={ String( draft.org_id ) }
						options={ advertisers.map( ( advertiser ) => ( {
							label: advertiser.name,
							value: String( advertiser.id ),
						} ) ) }
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

				<Flex justify="flex-end" gap={ 2 }>
					<Button variant="tertiary" onClick={ onCancel }>
						{ i18n.cancel }
					</Button>
					<Button
						variant="primary"
						onClick={ () => onCreate( draft ) }
						isBusy={ saving }
						disabled={ saving || '' === draft.name.trim() }
					>
						{ i18n.create }
					</Button>
				</Flex>
			</VStack>
		</Modal>
	);
}

function Screen( { payload }: { payload: Payload } ) {
	const {
		restPath,
		credentialsPath,
		windows,
		currencies,
		defaultCurrency,
		advertisers,
		i18n,
	} = payload;

	const [ definitions, setDefinitions ] = useState< Definition[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ formError, setFormError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );

	useEffect( () => {
		apiFetch< { definitions: Definition[] } >( { path: restPath } )
			.then( ( result ) => setDefinitions( result.definitions ?? [] ) )
			.catch( () => setError( i18n.loadFailed ) )
			.finally( () => setLoading( false ) );
	}, [ restPath, i18n.loadFailed ] );

	const create = async ( draft: Draft ) => {
		setSaving( true );
		setFormError( '' );

		try {
			const result = await apiFetch< { definition: Definition } >( {
				path: restPath,
				method: 'POST',
				data: draft,
			} );

			setDefinitions( [ result.definition, ...definitions ] );
			setCreating( false );
		} catch ( failure ) {
			// The manager's message is already translated and already written
			// for the person reading it, so it travels unchanged — and it stays
			// inside the modal, beside the field that has to change.
			const message = ( failure as { message?: string } )?.message;
			setFormError( message || i18n.saveFailed );
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
		// `archive` closes over `definitions` only to replace the row it
		// changed; listing it here would rebuild the menu on every load.
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

			<section className="aggr-section">
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
					header={
						<Button
							variant="primary"
							onClick={ () => {
								setFormError( '' );
								setCreating( true );
							} }
						>
							{ i18n.newDefinition }
						</Button>
					}
					empty={ <p>{ i18n.none }</p> }
				/>
			</section>

			<Credentials
				path={ credentialsPath }
				advertisers={ advertisers }
				i18n={ i18n }
			/>

			{ creating ? (
				<DefinitionModal
					i18n={ i18n }
					windows={ windows }
					currencies={ currencies }
					defaultCurrency={ defaultCurrency }
					advertisers={ advertisers }
					saving={ saving }
					error={ formError }
					onCancel={ () => setCreating( false ) }
					onCreate={ ( draft ) => void create( draft ) }
				/>
			) : null }
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
