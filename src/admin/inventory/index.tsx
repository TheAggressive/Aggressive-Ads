/**
 * The placement catalogue, in core's component set.
 *
 * Nothing autosaves, for the reason Packages does not: a slot slug is what a
 * published page renders an ad into, and "active" decides whether advertisers
 * can buy the slot at all. A half-typed slug is not a state the catalogue
 * should ever briefly hold. Every change is staged locally and written when the
 * person says so.
 *
 * There is no delete, and there must not be one. A placement is referenced by
 * every package that sells it and every campaign that bought one, so removing a
 * row would orphan the snapshot those point at. Deactivating hides it from
 * advertisers and leaves the history intact.
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
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { SaveError, setStrings, t, useAction } from '../shared/save';

const CUSTOM = 'custom';

type Placement = {
	id: number;
	name: string;
	slug: string;
	size: string;
	size_preset: string;
	size_width: number;
	size_height: number;
	active: boolean;
	sort_order: number;
	house_attachment_id: number;
	house_click_url: string;
	house_alt: string;
};

type View = {
	sizes: Record< string, string >;
	rows: Placement[];
};

type Bootstrap = {
	view: View;
	restPath: string;
	i18n: Record< string, string >;
};

const EMPTY: Bootstrap = {
	view: { sizes: {}, rows: [] },
	restPath: '',
	i18n: {},
};

const BLANK: Placement = {
	id: 0,
	name: '',
	slug: '',
	size: '',
	size_preset: '',
	size_width: 0,
	size_height: 0,
	active: true,
	sort_order: 0,
	house_attachment_id: 0,
	house_click_url: '',
	house_alt: '',
};

/** The body the REST route allowlists. */
function body( draft: Placement ): Record< string, unknown > {
	return {
		name: draft.name,
		slug: draft.slug,
		size_preset: draft.size_preset,
		size_width: draft.size_width,
		size_height: draft.size_height,
		sort_order: draft.sort_order,
		is_active: draft.active,
		house_attachment_id: draft.house_attachment_id,
		house_click_url: draft.house_click_url,
		house_alt: draft.house_alt,
	};
}

/**
 * One placement's editable form.
 *
 * Held as a draft rather than written through, so an abandoned edit changes
 * nothing. The Save button is the only thing that writes.
 */
function PlacementForm( {
	value,
	sizes,
	submitLabel,
	onSubmit,
	busy,
}: {
	value: Placement;
	sizes: Record< string, string >;
	submitLabel: string;
	onSubmit: ( draft: Placement ) => void;
	busy: boolean;
} ): ReactElement {
	const [ draft, setDraft ] = useState( value );

	const set = ( patch: Partial< Placement > ): void =>
		setDraft( { ...draft, ...patch } );

	const sizeOptions = [
		{ label: t( 'chooseSize' ), value: '' },
		...Object.entries( sizes ).map( ( [ stored, label ] ) => ( {
			label,
			value: stored,
		} ) ),
		{ label: t( 'customSize' ), value: CUSTOM },
	];

	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ t( 'name' ) }
				value={ draft.name }
				onChange={ ( name: string ) => set( { name } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<TextControl
				label={ t( 'slug' ) }
				help={ t( 'slugHelp' ) }
				value={ draft.slug }
				onChange={ ( slug: string ) => set( { slug } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<SelectControl
				label={ t( 'size' ) }
				value={ draft.size_preset }
				options={ sizeOptions }
				onChange={ ( size_preset: string ) => set( { size_preset } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			{ CUSTOM === draft.size_preset ? (
				<HStack
					justify="flex-start"
					alignment="flex-start"
					spacing={ 3 }
				>
					<TextControl
						label={ t( 'customWidth' ) }
						type="number"
						min={ 1 }
						max={ 10000 }
						value={ String( draft.size_width ) }
						onChange={ ( width: string ) =>
							set( { size_width: Number( width ) || 0 } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ t( 'customHeight' ) }
						type="number"
						min={ 1 }
						max={ 10000 }
						value={ String( draft.size_height ) }
						onChange={ ( height: string ) =>
							set( { size_height: Number( height ) || 0 } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
				</HStack>
			) : (
				<></>
			) }

			<TextControl
				label={ t( 'sortOrder' ) }
				help={ t( 'sortOrderHelp' ) }
				type="number"
				min={ 0 }
				max={ 9999 }
				value={ String( draft.sort_order ) }
				onChange={ ( order: string ) =>
					set( { sort_order: Number( order ) || 0 } )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>

			<ToggleControl
				label={ t( 'active' ) }
				help={ t( 'activeHelp' ) }
				checked={ draft.active }
				__nextHasNoMarginBottom
				onChange={ ( active: boolean ) => set( { active } ) }
			/>

			<fieldset>
				<legend>{ t( 'house' ) }</legend>
				<VStack spacing={ 4 }>
					<TextControl
						label={ t( 'houseAttachment' ) }
						help={ t( 'houseAttachmentHelp' ) }
						type="number"
						min={ 0 }
						value={ String( draft.house_attachment_id ) }
						onChange={ ( id: string ) =>
							set( {
								house_attachment_id: Number( id ) || 0,
							} )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ t( 'houseUrl' ) }
						type="url"
						value={ draft.house_click_url }
						onChange={ ( house_click_url: string ) =>
							set( { house_click_url } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<TextControl
						label={ t( 'houseAlt' ) }
						value={ draft.house_alt }
						onChange={ ( house_alt: string ) =>
							set( { house_alt } )
						}
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>

					{ /*
					 * Not an error — a placement with no house advertisement is
					 * a legitimate configuration, and the default one. It is
					 * said out loud because the consequence is invisible from
					 * this screen: the slot is simply absent on the page, which
					 * looks the same as a slot nobody placed.
					 */ }
					{ 0 === draft.house_attachment_id ? (
						<Notice status="info" isDismissible={ false }>
							{ t( 'houseMissing' ) }
						</Notice>
					) : null }
				</VStack>
			</fieldset>

			<HStack justify="flex-start">
				<Button
					variant="primary"
					__next40pxDefaultSize
					disabled={ busy }
					onClick={ () => onSubmit( draft ) }
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
			// The server's view, not a local guess. Sort order re-sequences the
			// whole list, and only the server knows what else moved.
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
					<Heading level={ 2 }>{ t( 'newPlacement' ) }</Heading>
				</CardHeader>
				<CardBody>
					<PlacementForm
						// Remounting on catalogue length clears the form after a
						// successful create, so the next placement starts blank
						// instead of inheriting the last one's fields.
						key={ `new-${ view.rows.length }` }
						value={ BLANK }
						sizes={ view.sizes }
						submitLabel={ t( 'create' ) }
						busy={ busy }
						onSubmit={ ( draft ) => {
							clearError();
							void write(
								{
									path: `${ data.restPath }/catalogue`,
									method: 'POST',
									data: body( draft ),
								},
								t( 'created' )
							);
						} }
					/>
				</CardBody>
			</Card>

			{ /*
			 * A plain div rather than a components layout primitive: the grid
			 * and its breakpoints live in src/styles/admin-native.css, which is
			 * already enqueued on every Advertising screen. Inline styles could
			 * not express the breakpoints, and a second stylesheet for one rule
			 * would be a second thing to remember to load.
			 */ }
			<div className="aggr-card-grid">
				{ view.rows.map( ( row ) => (
					<Card key={ row.id }>
						<CardHeader>
							<Heading level={ 2 }>
								{ `${ row.name } (${ row.size })` }
								{ row.active ? '' : ` — ${ t( 'inactive' ) }` }
							</Heading>
						</CardHeader>
						<CardBody>
							<PlacementForm
								key={ `${ row.id }-${ row.active }-${ row.size }` }
								value={ row }
								sizes={ view.sizes }
								submitLabel={ t( 'save' ) }
								busy={ busy }
								onSubmit={ ( draft ) => {
									clearError();
									void write(
										{
											path: `${ data.restPath }/${ row.id }`,
											method: 'PATCH',
											data: body( draft ),
										},
										t( 'saved' )
									);
								} }
							/>
						</CardBody>
					</Card>
				) ) }
			</div>
		</VStack>
	);
}

const root = document.getElementById( 'aggr-inventory-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-inventory' );
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
