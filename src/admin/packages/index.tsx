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
	Card,
	CardBody,
	CardHeader,
	CheckboxControl,
	Notice,
	TextControl,
	ToggleControl,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { SaveError, setStrings, t, useAction } from '../shared/save';

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
	i18n: Record< string, string >;
};

const EMPTY: Bootstrap = {
	view: { default_id: 0, placements: [], rows: [] },
	restPath: '',
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
 * One package's editable form.
 *
 * Held as a draft rather than written through, so an abandoned edit changes
 * nothing. The Save button is the only thing that writes.
 */
function PackageForm( {
	value,
	placements,
	submitLabel,
	onSubmit,
	busy,
}: {
	value: Package;
	placements: Placement[];
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
				<TextControl
					label={ t( 'currency' ) }
					value={ draft.currency }
					maxLength={ 3 }
					onChange={ ( currency: string ) =>
						set( { currency: currency.toUpperCase() } )
					}
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

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ view, setView ] = useState( data.view );
	const [ saved, setSaved ] = useState( '' );
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
		}
	};

	return (
		<VStack spacing={ 5 }>
			<SaveError message={ error } onRetry={ undefined } />

			{ saved ? (
				<Notice
					status="success"
					isDismissible
					onRemove={ () => setSaved( '' ) }
				>
					{ saved }
				</Notice>
			) : null }

			<Card>
				<CardHeader>
					<Heading level={ 3 }>{ t( 'newPackage' ) }</Heading>
				</CardHeader>
				<CardBody>
					<PackageForm
						// Remounting on catalogue length clears the form after a
						// successful create, so the next package starts blank
						// instead of inheriting the last one's fields.
						key={ `new-${ view.rows.length }` }
						value={ BLANK }
						placements={ view.placements }
						submitLabel={ t( 'create' ) }
						busy={ busy }
						onSubmit={ ( draft, amount ) => {
							clearError();
							void write(
								{
									path: `${ data.restPath }/catalogue`,
									method: 'POST',
									data: body( draft, amount ),
								},
								t( 'created' )
							);
						} }
					/>
				</CardBody>
			</Card>

			{ view.rows.map( ( row ) => (
				<Card key={ row.id }>
					<CardHeader>
						<Heading level={ 3 }>
							{ row.name }
							{ row.is_default
								? ` — ${ t( 'defaultTag' ) }`
								: '' }
						</Heading>
					</CardHeader>
					<CardBody>
						<PackageForm
							key={ `${ row.id }-${ row.is_default }-${ row.is_active }` }
							value={ row }
							placements={ view.placements }
							submitLabel={ t( 'save' ) }
							busy={ busy }
							onSubmit={ ( draft, amount ) => {
								clearError();
								void write(
									{
										path: `${ data.restPath }/${ row.id }`,
										method: 'PATCH',
										data: body( draft, amount ),
									},
									t( 'saved' )
								);
							} }
						/>
					</CardBody>
				</Card>
			) ) }
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
