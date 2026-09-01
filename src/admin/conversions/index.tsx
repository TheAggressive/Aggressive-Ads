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
	TextControl,
	ToggleControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { createRoot } from '@wordpress/element';
import { useMemo, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import { Credentials } from './credentials';
import './style.css';

import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import { amountToMicros, microsToAmount } from './money';

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
 * An option list that always contains the value it is asked to show.
 *
 * A select whose current value is absent from its options renders as something
 * else and saves that instead — so editing a definition whose window or
 * currency was set over REST, outside what this screen offers, would silently
 * change it on the way past. The stored value is added rather than the offer
 * widened: it is legal because it is already stored, not because the screen
 * would propose it.
 */
function withCurrent(
	options: Array< { label: string; value: string } >,
	value: string,
	label: string
): Array< { label: string; value: string } > {
	if (
		'' === value ||
		options.some( ( option ) => option.value === value )
	) {
		return options;
	}

	return [ ...options, { label, value } ];
}

/**
 * The definition form, for a new one and for an existing one.
 *
 * A module-level component taking its state as props, like the roster's modals.
 * Nothing here is a DataViews action, so the frozen-action hazard that file
 * documents does not apply — but a form defined inside the screen's render
 * would still remount on every keystroke elsewhere, so the shape is the same.
 *
 * **Editing exists because archiving is not a correction.** A definition's
 * `public_key` is what an advertiser has pasted onto their page, and it is
 * minted once; without an edit, fixing a mistyped name or a wrong window meant
 * archive-and-recreate, which hands back a different key and takes the
 * advertiser's page down until somebody re-pastes it. The update route has
 * never carried `public_key`, so editing cannot rotate it.
 */
function DefinitionModal( {
	i18n,
	windows,
	currencies,
	defaultCurrency,
	advertisers,
	editing,
	saving,
	error,
	onCancel,
	onSubmit,
}: {
	i18n: Strings;
	windows: Array< { label: string; value: string } >;
	currencies: Array< { label: string; value: string } >;
	defaultCurrency: string;
	advertisers: Advertiser[];
	editing: Definition | null;
	saving: boolean;
	error: string;
	onCancel: () => void;
	onSubmit: ( draft: Draft ) => void;
} ) {
	const [ draft, setDraft ] = useState< Draft >( () =>
		null === editing
			? emptyDraft()
			: {
					name: editing.name,
					org_id: editing.org_id,
					window_seconds: editing.window_seconds,
					default_value_micros: editing.default_value_micros,
					currency: editing.currency,
					allow_s2s: editing.allow_s2s,
					status: editing.status,
			  }
	);

	/*
	 * The literal text, not a number formatted back out of the draft.
	 *
	 * Deriving the field's value from `default_value_micros` rewrote the box
	 * under the caret on every keystroke: "4" became "4.00", the next key
	 * landed after the decimals, and typing 4, 9, ., 9, 0 stored 4.02. The
	 * packages screen already holds its price this way, and this form should
	 * never have differed from it.
	 */
	const [ amount, setAmount ] = useState( () =>
		null === editing ? '' : microsToAmount( editing.default_value_micros )
	);

	const scoped = draft.org_id > 0;
	const micros = amountToMicros( amount );
	const priced = micros > 0;

	return (
		<Modal
			title={
				null === editing ? i18n.newDefinition : i18n.editDefinition
			}
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
					options={ withCurrent(
						windows,
						String( draft.window_seconds ),
						`${ Math.round( draft.window_seconds / 86400 ) } ${
							i18n.days
						}`
					) }
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
							inputMode="decimal"
							value={ amount }
							onChange={ ( next ) => {
								setAmount( next );

								// Stating a value reaches for the site's own
								// currency, so the common case is answered by
								// the time the control becomes available.
								if (
									'' === draft.currency &&
									amountToMicros( next ) > 0
								) {
									setDraft( {
										...draft,
										currency: defaultCurrency,
									} );
								}
							} }
							// Tidied once the person has finished, never while
							// they are still typing — that was the defect.
							onBlur={ () =>
								setAmount(
									priced ? microsToAmount( micros ) : ''
								)
							}
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
								priced
									? i18n.currencyHelp
									: i18n.currencyDisabledHelp
							}
							disabled={ ! priced }
							value={ draft.currency }
							options={ withCurrent(
								currencies,
								draft.currency,
								draft.currency
							) }
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
						onClick={ () =>
							onSubmit( {
								...draft,
								default_value_micros: micros,

								// A definition worth nothing carries no
								// denomination, so a currency chosen and then
								// abandoned cannot be saved beside a zero.
								currency: priced ? draft.currency : '',
							} )
						}
						isBusy={ saving }
						disabled={ saving || '' === draft.name.trim() }
					>
						{ null === editing ? i18n.create : i18n.save }
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

	// Seeded from the page, not fetched. See `Payload.definitions`.
	const [ definitions, setDefinitions ] = useState< Definition[] >(
		payload.definitions
	);
	const [ error, setError ] = useState( '' );
	const [ formError, setFormError ] = useState( '' );
	const [ saving, setSaving ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ editing, setEditing ] = useState< Definition | null >( null );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );

	/**
	 * One writer for both, because the difference is a route and a revision.
	 *
	 * The revision is the definition's own, taken from the row that opened the
	 * form. A concurrent change refuses the write and the manager answers with
	 * "somebody else changed this, reload" — which travels to the notice
	 * unchanged, because it is already the right sentence.
	 */
	const submit = async ( draft: Draft ) => {
		setSaving( true );
		setFormError( '' );

		try {
			const result = await apiFetch< { definition: Definition } >(
				null === editing
					? { path: restPath, method: 'POST', data: draft }
					: {
							path: `${ restPath }/${ editing.id }`,
							method: 'PATCH',
							data: { ...draft, revision: editing.revision },
					  }
			);

			setDefinitions(
				null === editing
					? [ result.definition, ...definitions ]
					: definitions.map( ( row ) =>
							row.id === result.definition.id
								? result.definition
								: row
					  )
			);

			setCreating( false );
			setEditing( null );
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
				id: 'edit',
				label: i18n.edit,
				isPrimary: true,
				supportsBulk: false,
				callback: ( items: Definition[] ) => {
					const item = items[ 0 ];

					if ( item ) {
						setFormError( '' );
						setEditing( item );
					}
				},
			},
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
				seeded={ payload.credentials }
				i18n={ i18n }
			/>

			{ creating || null !== editing ? (
				<DefinitionModal
					/*
					 * Keyed on what is being edited so the form is a fresh
					 * mount per definition. Without it, opening a second row
					 * while the first modal's state exists would show the first
					 * one's values under the second one's title.
					 */
					key={ null === editing ? 'new' : editing.id }
					i18n={ i18n }
					windows={ windows }
					currencies={ currencies }
					defaultCurrency={ defaultCurrency }
					advertisers={ advertisers }
					editing={ editing }
					saving={ saving }
					error={ formError }
					onCancel={ () => {
						setCreating( false );
						setEditing( null );
					} }
					onSubmit={ ( draft ) => void submit( draft ) }
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
