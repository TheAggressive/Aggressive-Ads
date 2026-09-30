/**
 * The package catalogue, in core's component set.
 *
 * Unlike Settings, nothing here autosaves. A package is a priced offer that
 * advertisers select from: a half-typed name or an in-progress price is not a
 * state the catalogue should ever briefly hold, and "active" is a switch that
 * puts an offer in front of customers. Every change is staged locally and
 * written when the person says so.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useState } from '@wordpress/element';
import {
	Button,
	CheckboxControl,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { SaveError, setStrings, t, useAction } from '../shared/save';
import { Icon, IconChip } from '../shared/icon';
import { State } from '../shared/state';
import './style.css';

type Placement = {
	id: number;
	name: string;
	size: string;
	active: boolean;
};

type Package = {
	id: number;
	name: string;
	placement_ids: number[];
	duration_days: number;
	custom_duration: boolean;
	price_cents: number;
	currency: string;
	is_active: boolean;
	is_default: boolean;
};

type View = {
	default_id: number;
	placements: Placement[];
	rows: Package[];
};

type Bootstrap = {
	view: View;
	restPath: string;
	currencies: Array< { label: string; value: string } >;
	defaultCurrency: string;
	i18n: Record< string, string >;
};

const EMPTY: Bootstrap = {
	view: { default_id: 0, placements: [], rows: [] },
	restPath: '',
	currencies: [],
	defaultCurrency: '',
	i18n: {},
};

const BLANK: Package = {
	id: 0,
	name: '',
	placement_ids: [],
	duration_days: 30,
	custom_duration: false,
	price_cents: 0,
	currency: 'USD',
	is_active: false,
	is_default: false,
};

/**
 * Cents as an editable decimal string.
 *
 * Money is stored and sent as an integer number of cents and only ever becomes
 * a decimal for display. Parsing "12.34" with floating point and multiplying by
 * 100 is how a price becomes 1233 cents, so the split happens on the string.
 */
function toAmount( cents: number ): string {
	return ( Math.round( cents ) / 100 ).toFixed( 2 );
}

function toCents( amount: string ): number {
	const match = /^(-?)(\d*)(?:\.(\d{0,2}))?$/.exec( amount.trim() );

	if ( ! match ) {
		return Number.NaN;
	}

	const whole = Number( match[ 2 ] || '0' );
	const part = Number( ( match[ 3 ] ?? '' ).padEnd( 2, '0' ) || '0' );

	return ( '-' === match[ 1 ] ? -1 : 1 ) * ( whole * 100 + part );
}

/** The body the REST route allowlists. */
function body( draft: Package, amount: string ): Record< string, unknown > {
	return {
		name: draft.name,
		placement_ids: draft.placement_ids,
		duration_days: draft.duration_days,
		custom_duration: draft.custom_duration,
		price_cents: toCents( amount ),
		currency: draft.currency,
		is_active: draft.is_active,
		is_default: draft.is_default,
	};
}

function PlacementPicker( {
	placements,
	selected,
	onChange,
}: {
	placements: Placement[];
	selected: number[];
	onChange: ( ids: number[] ) => void;
} ): ReactElement {
	if ( 0 === placements.length ) {
		return <p>{ t( 'noPlacements' ) }</p>;
	}

	return (
		<fieldset>
			<legend>{ t( 'placements' ) }</legend>
			<VStack spacing={ 1 }>
				{ placements.map( ( placement ) => (
					<CheckboxControl
						key={ placement.id }
						__nextHasNoMarginBottom
						label={
							placement.active
								? `${ placement.name } (${ placement.size })`
								: `${ placement.name } (${
										placement.size
								  }) — ${ t( 'inactive' ) }`
						}
						checked={ selected.includes( placement.id ) }
						onChange={ ( on: boolean ) =>
							onChange(
								on
									? [ ...selected, placement.id ]
									: selected.filter(
											( id ) => id !== placement.id
									  )
							)
						}
					/>
				) ) }
			</VStack>
		</fieldset>
	);
}

/**
 * One package's editable form, inside the editor dialog.
 *
 * Held as a draft rather than written through, so an abandoned edit — a dialog
 * closed without saving — changes nothing. The Save button is the only thing
 * that writes.
 */
function PackageForm( {
	value,
	placements,
	currencies,
	submitLabel,
	onSubmit,
	busy,
}: {
	value: Package;
	placements: Placement[];
	currencies: Array< { label: string; value: string } >;
	submitLabel: string;
	onSubmit: ( draft: Package, amount: string ) => void;
	busy: boolean;
} ): ReactElement {
	const [ draft, setDraft ] = useState( value );
	const [ amount, setAmount ] = useState( toAmount( value.price_cents ) );

	const set = ( patch: Partial< Package > ): void =>
		setDraft( { ...draft, ...patch } );

	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ t( 'name' ) }
				value={ draft.name }
				onChange={ ( name: string ) => set( { name } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<PlacementPicker
				placements={ placements }
				selected={ draft.placement_ids }
				onChange={ ( placement_ids ) => set( { placement_ids } ) }
			/>

			<ToggleControl
				label={ t( 'customDuration' ) }
				help={ t( 'customDurationHelp' ) }
				checked={ draft.custom_duration }
				__nextHasNoMarginBottom
				onChange={ ( custom_duration: boolean ) =>
					set( { custom_duration } )
				}
			/>

			{ draft.custom_duration ? (
				<></>
			) : (
				<TextControl
					label={ t( 'durationDays' ) }
					type="number"
					value={ String( draft.duration_days ) }
					onChange={ ( days: string ) =>
						set( { duration_days: Number( days ) || 0 } )
					}
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			) }

			<HStack justify="flex-start" alignment="flex-start" spacing={ 3 }>
				<TextControl
					label={ t( 'price' ) }
					help={ t( 'priceHelp' ) }
					value={ amount }
					onChange={ setAmount }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
				{ /*
				   A select, not three characters to type. A price is billed
				   from, so "usd", "US$" and a typo all mattering more here than
				   anywhere else — and only the last of the three was ever
				   caught by anything. The stored code is added to the options
				   when it is not one this screen would offer, because a select
				   whose value is absent renders as something else and saves
				   that instead.
				*/ }
				<SelectControl
					label={ t( 'currency' ) }
					value={ draft.currency }
					options={
						'' === draft.currency ||
						currencies.some(
							( option ) => option.value === draft.currency
						)
							? currencies
							: [
									...currencies,
									{
										label: draft.currency,
										value: draft.currency,
									},
							  ]
					}
					onChange={ ( currency: string ) => set( { currency } ) }
					__nextHasNoMarginBottom
					__next40pxDefaultSize
				/>
			</HStack>

			<ToggleControl
				label={ t( 'active' ) }
				help={ t( 'activeHelp' ) }
				checked={ draft.is_active }
				__nextHasNoMarginBottom
				onChange={ ( is_active: boolean ) => set( { is_active } ) }
			/>

			<ToggleControl
				label={ t( 'isDefault' ) }
				help={ t( 'isDefaultHelp' ) }
				checked={ draft.is_default }
				__nextHasNoMarginBottom
				onChange={ ( is_default: boolean ) => set( { is_default } ) }
			/>

			<HStack justify="flex-start">
				<Button
					variant="primary"
					__next40pxDefaultSize
					disabled={ busy }
					onClick={ () => onSubmit( draft, amount ) }
				>
					{ submitLabel }
				</Button>
			</HStack>
		</VStack>
	);
}

/**
 * One package as the product it is: name, price, run length, placements.
 *
 * Every package used to be an always-open form with its own Save button, so
 * finding the price of one meant scrolling past every field of every other,
 * and nothing showed which were on sale or which was the default without
 * reading toggles. A catalogue is read far more often than it is edited, so
 * the card is for reading and the form moves into a dialog.
 *
 * Cards rather than a table because a catalogue is a handful of offers, and
 * the portal already shows advertisers these same packages as cards.
 */
function PackageCard( {
	row,
	placements,
	onEdit,
}: {
	row: Package;
	placements: Placement[];
	onEdit: () => void;
} ): ReactElement {
	const headingId = `aggr-package-${ row.id }`;
	const included = placements.filter( ( placement ) =>
		row.placement_ids.includes( placement.id )
	);

	return (
		<article
			className={
				row.is_active
					? 'aggr-package-card'
					: 'aggr-package-card aggr-package-card--inactive'
			}
			aria-labelledby={ headingId }
		>
			<div className="aggr-package-card__head">
				<h2 id={ headingId }>{ row.name }</h2>
				<span className="aggr-package-card__badges">
					{ row.is_default ? (
						<State tone="attention">{ t( 'defaultBadge' ) }</State>
					) : null }
					{ row.is_active ? (
						<State tone="live">{ t( 'active' ) }</State>
					) : (
						<State tone="neutral">{ t( 'inactiveBadge' ) }</State>
					) }
				</span>
			</div>

			<p className="aggr-package-card__price">
				<span className="aggr-package-card__currency">
					{ row.currency }
				</span>{ ' ' }
				{ toAmount( row.price_cents ) }
			</p>

			<p className="aggr-package-card__meta">
				<Icon name="clock" size={ 14 } />
				{ row.custom_duration
					? t( 'customDuration' )
					: sprintfDays( row.duration_days ) }
			</p>

			{ included.length > 0 ? (
				<ul
					className="aggr-package-card__placements"
					aria-label={ t( 'placements' ) }
				>
					{ included.map( ( placement ) => (
						<li key={ placement.id }>
							{ placement.name }
							<span>{ placement.size }</span>
						</li>
					) ) }
				</ul>
			) : (
				<p className="aggr-package-card__none">
					{ t( 'noPlacementsChosen' ) }
				</p>
			) }

			<div className="aggr-package-card__foot">
				<Button
					variant="secondary"
					onClick={ onEdit }
					// "Edit" alone, moving control to control, does not say
					// which package; the visible word stays first so a voice
					// user can still say what they see.
					aria-label={ `${ t( 'edit' ) }: ${ row.name }` }
				>
					{ t( 'edit' ) }
				</Button>
			</div>
		</article>
	);
}

/** "30 days", in the translator's plural form. */
function sprintfDays( days: number ): string {
	return ( 1 === days ? t( 'dayOne' ) : t( 'dayMany' ) ).replace(
		'%d',
		String( days )
	);
}

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ view, setView ] = useState( data.view );
	const [ saved, setSaved ] = useState( '' );

	// `null` is closed, `0` is a new package, any other id is that package.
	const [ editing, setEditing ] = useState< number | null >( null );
	const { error, busy, run, clearError } = useAction< { view: View } >();

	const write = async (
		options: Record< string, unknown >,
		message: string
	): Promise< void > => {
		setSaved( '' );

		const result = await run( () => apiFetch< { view: View } >( options ) );

		if ( result ) {
			// The server's view, not a local guess. A package can change more
			// than was sent — making one the default demotes another — and only
			// the server knows what else moved.
			setView( result.view );
			setSaved( message );
			setEditing( null );
		}
	};

	const open = ( id: number ): void => {
		clearError();
		setSaved( '' );
		setEditing( id );
	};

	const current =
		null === editing || 0 === editing
			? null
			: view.rows.find( ( row ) => row.id === editing ) ?? null;

	return (
		<VStack spacing={ 5 }>
			{ saved ? (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSaved( '' ) }
				>
					{ saved }
				</Notice>
			) : null }

			<div className="aggr-package-grid">
				{ view.rows.map( ( row ) => (
					<PackageCard
						key={ row.id }
						row={ row }
						placements={ view.placements }
						onEdit={ () => open( row.id ) }
					/>
				) ) }

				<button
					type="button"
					className="aggr-package-new"
					onClick={ () => open( 0 ) }
				>
					<IconChip name="plus" />
					<span>{ t( 'newPackage' ) }</span>
					{ 0 === view.rows.length ? (
						<span className="aggr-package-new__hint">
							{ t( 'emptyCatalogue' ) }
						</span>
					) : null }
				</button>
			</div>

			{ null !== editing ? (
				<Modal
					title={
						null === current
							? t( 'newPackage' )
							: `${ t( 'editPackage' ) }: ${ current.name }`
					}
					className="aggr-package-modal"
					onRequestClose={ () => setEditing( null ) }
				>
					<VStack spacing={ 4 }>
						{ /*
						 * Inside the dialog, because that is where the reader
						 * is: an error printed on the page behind a modal is an
						 * error nobody sees until they close the thing they
						 * were trying to fix.
						 */ }
						<SaveError message={ error } onRetry={ undefined } />
						<PackageForm
							value={
								current ?? {
									...BLANK,
									currency: data.defaultCurrency,
								}
							}
							placements={ view.placements }
							currencies={ data.currencies }
							submitLabel={
								null === current ? t( 'create' ) : t( 'save' )
							}
							busy={ busy }
							onSubmit={ ( draft, amount ) => {
								clearError();
								void write(
									null === current
										? {
												path: `${ data.restPath }/catalogue`,
												method: 'POST',
												data: body( draft, amount ),
										  }
										: {
												path: `${ data.restPath }/${ current.id }`,
												method: 'PATCH',
												data: body( draft, amount ),
										  },
									null === current
										? t( 'created' )
										: t( 'saved' )
								);
							} }
						/>
					</VStack>
				</Modal>
			) : null }
		</VStack>
	);
}

const root = document.getElementById( 'aggr-packages-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-packages' );
	let data: Bootstrap = EMPTY;

	try {
		data = raw ? ( JSON.parse( raw ) as Bootstrap ) : EMPTY;
	} catch {
		// A malformed payload renders an empty screen rather than throwing
		// inside a page the administrator still needs to use.
		data = EMPTY;
	}

	setStrings( data.i18n ?? {} );
	createRoot( root ).render( <App data={ data } /> );
}
