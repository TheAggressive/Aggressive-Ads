/**
 * The Settings screen in core's component set, saving as you change it.
 *
 * Every user-visible string arrives from PHP in the bootstrap payload rather
 * than being translated here. `wp i18n make-pot` does not parse .tsx, so an
 * __() call in this file would compile, run, and produce no catalog entry —
 * correct-looking code that no translator can ever see. Hydrating server-side
 * is the same convention the portal's Interactivity stores use.
 *
 * `@wordpress/components` is a devDependency for its types only. wp-scripts'
 * dependency extraction externalises it, so the shipped bundle contains none of
 * it and the browser uses the copy WordPress already loads.
 *
 * Rendered only when the URL carries `?aggr_preview=1`, beside the native
 * screen rather than replacing it. Without JavaScript this mount point renders
 * nothing, which is exactly why the native screen is still the default: it is
 * the no-JS path, not a fallback that needs building later.
 */

import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import { createRoot, useCallback, useState } from '@wordpress/element';
import {
	Button,
	Card,
	CardBody,
	CardHeader,
	ColorIndicator,
	ColorPicker,
	Dropdown,
	Notice,
	SelectControl,
	TextControl,
	ToggleControl,
	__experimentalHeading as Heading,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import {
	AFTER_DRAGGING,
	AFTER_TYPING,
	AT_ONCE,
	SaveError,
	SaveStatus,
	setStrings,
	t,
	useAutosave,
} from '../shared/save';

type Toggle = {
	key: string;
	label: string;
	help: string;
	enabled: boolean;
};

type Colour = { key: string; label: string; value: string };

type Person = {
	id: number;
	name: string;
	email: string;
	roles: string;
	is_admin: boolean;
};

type Brand = {
	productName: string;
	tagline: string;
	supportEmail: string;
	logoUrl: string;
	colours: Colour[];
};

/**
 * The editable document, held whole in one place.
 *
 * Numeric fields are strings because that is what a text input holds mid-edit;
 * a half-typed "3" is not the number 3, and pretending otherwise means the
 * field fights the person typing in it. PHP decides what is numeric.
 */
type Doc = {
	modules: Toggle[];
	liveEdits: Toggle[];
	brand: Brand;
	delivery: { fillTtl: string; housePolicy: string };
	tracking: { retentionDays: string };
};

type Bootstrap = {
	modules: Toggle[];
	liveEdits: Toggle[];
	brand: Brand;
	delivery: {
		fillTtl: number;
		housePolicy: string;
		houseOptions: { value: string; label: string }[];
	};
	tracking: { retentionDays: number };
	roster: Person[];
	i18n: Record< string, string >;
	restPath: string;
};

const EMPTY: Bootstrap = {
	modules: [],
	liveEdits: [],
	brand: {
		productName: '',
		tagline: '',
		supportEmail: '',
		logoUrl: '',
		colours: [],
	},
	delivery: { fillTtl: 30, housePolicy: '', houseOptions: [] },
	tracking: { retentionDays: 90 },
	roster: [],
	i18n: {},
	restPath: '',
};

/** The document in the field names PHP allowlists. */
function payload( doc: Doc ): Record< string, unknown > {
	const flags = ( items: Toggle[] ): Record< string, boolean > =>
		Object.fromEntries(
			items.map( ( item ) => [ item.key, item.enabled ] )
		);

	return {
		modules: flags( doc.modules ),
		live_edits: flags( doc.liveEdits ),
		brand: {
			product_name: doc.brand.productName,
			tagline: doc.brand.tagline,
			support_email: doc.brand.supportEmail,
			logo_url: doc.brand.logoUrl,
			...Object.fromEntries(
				doc.brand.colours.map( ( colour ) => [
					colour.key,
					colour.value,
				] )
			),
		},
		delivery: {
			fill_ttl: doc.delivery.fillTtl,
			house_policy: doc.delivery.housePolicy,
		},
		tracking: { retention_days: doc.tracking.retentionDays },
	};
}

function Section( {
	title,
	help,
	children,
}: {
	title: string;
	help?: string;
	children: ReactElement | ReactElement[];
} ): ReactElement {
	return (
		<Card>
			<CardHeader>
				<Heading level={ 3 }>{ title }</Heading>
			</CardHeader>
			<CardBody>
				<VStack spacing={ 4 }>
					{ help ? <p>{ help }</p> : <></> }
					{ children }
				</VStack>
			</CardBody>
		</Card>
	);
}

function Toggles( {
	items,
	onChange,
}: {
	items: Toggle[];
	onChange: ( next: Toggle[] ) => void;
} ): ReactElement {
	return (
		<VStack spacing={ 3 }>
			{ items.map( ( item, index ) => (
				<ToggleControl
					key={ item.key }
					label={ item.label }
					help={ item.help || undefined }
					checked={ item.enabled }
					__nextHasNoMarginBottom
					onChange={ ( enabled: boolean ) => {
						const next = [ ...items ];
						next[ index ] = { ...item, enabled };
						onChange( next );
					} }
				/>
			) ) }
		</VStack>
	);
}

function BrandFields( {
	brand,
	onText,
	onColour,
}: {
	brand: Brand;
	onText: ( patch: Partial< Brand > ) => void;
	onColour: ( colours: Colour[] ) => void;
} ): ReactElement {
	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ t( 'productName' ) }
				value={ brand.productName }
				onChange={ ( productName: string ) =>
					onText( { productName } )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ t( 'tagline' ) }
				help={ t( 'taglineHelp' ) }
				value={ brand.tagline }
				onChange={ ( tagline: string ) => onText( { tagline } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ t( 'supportEmail' ) }
				help={ t( 'supportHelp' ) }
				type="email"
				value={ brand.supportEmail }
				onChange={ ( supportEmail: string ) =>
					onText( { supportEmail } )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ t( 'logoUrl' ) }
				type="url"
				value={ brand.logoUrl }
				onChange={ ( logoUrl: string ) => onText( { logoUrl } ) }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<VStack spacing={ 2 }>
				{ brand.colours.map( ( colour, index ) => (
					<HStack
						key={ colour.key }
						justify="flex-start"
						spacing={ 3 }
					>
						<Dropdown
							popoverProps={ { placement: 'bottom-start' } }
							renderToggle={ ( { isOpen, onToggle } ) => (
								<Button
									variant="tertiary"
									onClick={ onToggle }
									aria-expanded={ isOpen }
									// The swatch alone would identify the
									// control by colour only, which is exactly
									// what a colour-blind reader cannot use.
									aria-label={ colour.label }
								>
									<HStack spacing={ 2 }>
										<ColorIndicator
											colorValue={ colour.value }
										/>
										<span>{ colour.label }</span>
										<code>{ colour.value }</code>
									</HStack>
								</Button>
							) }
							renderContent={ () => (
								<ColorPicker
									color={ colour.value }
									enableAlpha={ false }
									onChange={ ( value: string ) => {
										const next = [ ...brand.colours ];
										next[ index ] = { ...colour, value };
										onColour( next );
									} }
								/>
							) }
						/>
					</HStack>
				) ) }
			</VStack>
		</VStack>
	);
}

function Delivery( {
	delivery,
	tracking,
	houseOptions,
	onDelivery,
	onTracking,
}: {
	delivery: Doc[ 'delivery' ];
	tracking: Doc[ 'tracking' ];
	houseOptions: { value: string; label: string }[];
	onDelivery: ( patch: Partial< Doc[ 'delivery' ] >, delay: number ) => void;
	onTracking: ( patch: Partial< Doc[ 'tracking' ] > ) => void;
} ): ReactElement {
	return (
		<VStack spacing={ 4 }>
			<TextControl
				label={ t( 'fillTtl' ) }
				type="number"
				value={ delivery.fillTtl }
				onChange={ ( fillTtl: string ) =>
					onDelivery( { fillTtl }, AFTER_TYPING )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<SelectControl
				label={ t( 'houseAds' ) }
				value={ delivery.housePolicy }
				options={ houseOptions }
				onChange={ ( housePolicy: string ) =>
					onDelivery( { housePolicy }, AT_ONCE )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<TextControl
				label={ t( 'retentionDays' ) }
				type="number"
				value={ tracking.retentionDays }
				onChange={ ( retentionDays: string ) =>
					onTracking( { retentionDays } )
				}
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
		</VStack>
	);
}

/**
 * The review roster, which saves on the action rather than on change.
 *
 * Access is not a setting and is deliberately not autosaved. Granting somebody
 * the ability to read every organization's unpublished work is a decision, not
 * an adjustment, and a decision should happen when it is asked for — not a
 * second after the last keystroke of a half-typed username that might resolve
 * to somebody else entirely.
 *
 * Every response carries the roster back, so the list is the server's answer
 * rather than a guess about what the server did.
 */
function Access( {
	initial,
	path,
}: {
	initial: Person[];
	path: string;
} ): ReactElement {
	const [ roster, setRoster ] = useState( initial );
	const [ identifier, setIdentifier ] = useState( '' );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState( '' );

	const call = ( options: Record< string, unknown > ): void => {
		setBusy( true );
		setError( '' );

		apiFetch< { roster: Person[] } >( options )
			.then( ( result ) => {
				setRoster( result.roster );
				setIdentifier( '' );
			} )
			.catch( ( reason: unknown ) => {
				setError(
					reason &&
						typeof reason === 'object' &&
						'message' in reason &&
						typeof reason.message === 'string'
						? reason.message
						: t( 'saveFailed' )
				);
			} )
			.finally( () => setBusy( false ) );
	};

	return (
		<VStack spacing={ 4 }>
			{ error ? (
				<Notice status="error" isDismissible={ false }>
					{ error }
				</Notice>
			) : null }

			{ roster.length === 0 ? (
				<p>{ t( 'accessEmpty' ) }</p>
			) : (
				<VStack spacing={ 3 }>
					{ roster.map( ( person ) => (
						<HStack key={ person.id } justify="space-between">
							<VStack spacing={ 0 }>
								<strong>{ person.name }</strong>
								<span>{ person.email }</span>
							</VStack>
							{ person.is_admin ? (
								<span>{ t( 'alwaysAdmin' ) }</span>
							) : (
								<HStack
									justify="flex-end"
									spacing={ 3 }
									expanded={ false }
								>
									<span>{ person.roles }</span>
									<Button
										variant="tertiary"
										isDestructive
										disabled={ busy }
										// The row shows a name; the button
										// alone would just say "Remove" to a
										// screen reader moving control to
										// control.
										aria-label={ `${ t( 'remove' ) }: ${
											person.name
										}` }
										onClick={ () =>
											call( {
												path: `${ path }/${ person.id }`,
												method: 'DELETE',
											} )
										}
									>
										{ t( 'remove' ) }
									</Button>
								</HStack>
							) }
						</HStack>
					) ) }
				</VStack>
			) }

			<VStack spacing={ 2 }>
				<HStack justify="flex-start" alignment="flex-end" spacing={ 3 }>
					<TextControl
						label={ t( 'addReviewer' ) }
						value={ identifier }
						autoComplete="off"
						onChange={ setIdentifier }
						__nextHasNoMarginBottom
						__next40pxDefaultSize
					/>
					<Button
						variant="secondary"
						__next40pxDefaultSize
						disabled={ busy || '' === identifier.trim() }
						onClick={ () =>
							call( {
								path,
								method: 'POST',
								data: { user: identifier.trim() },
							} )
						}
					>
						{ t( 'add' ) }
					</Button>
				</HStack>
				<Text variant="muted">{ t( 'addReviewerHint' ) }</Text>
			</VStack>
		</VStack>
	);
}

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ doc, setDoc ] = useState< Doc >( {
		modules: data.modules,
		liveEdits: data.liveEdits,
		brand: data.brand,
		delivery: {
			fillTtl: String( data.delivery.fillTtl ),
			housePolicy: data.delivery.housePolicy,
		},
		tracking: { retentionDays: String( data.tracking.retentionDays ) },
	} );

	const { status, error, schedule, retry } = useAutosave< Doc >( ( next ) =>
		apiFetch( {
			path: data.restPath,
			method: 'POST',
			data: payload( next ),
		} )
	);

	// One writer for the whole document, so there is exactly one place a change
	// becomes both new state and a scheduled save. Splitting those two steps is
	// how a field ends up editable but never stored.
	const edit = useCallback(
		( patch: ( current: Doc ) => Doc, delay: number ) => {
			setDoc( ( current ) => {
				const next = patch( current );
				schedule( next, delay );

				return next;
			} );
		},
		[ schedule ]
	);

	return (
		<VStack spacing={ 5 }>
			<SaveError message={ error } onRetry={ retry } />

			<SaveStatus status={ status } />

			<Section title={ t( 'modules' ) } help={ t( 'modulesHelp' ) }>
				<Toggles
					items={ doc.modules }
					onChange={ ( modules ) =>
						edit(
							( current ) => ( { ...current, modules } ),
							AT_ONCE
						)
					}
				/>
			</Section>

			<Section title={ t( 'liveEdits' ) } help={ t( 'liveEditsHelp' ) }>
				<Toggles
					items={ doc.liveEdits }
					onChange={ ( liveEdits ) =>
						edit(
							( current ) => ( { ...current, liveEdits } ),
							AT_ONCE
						)
					}
				/>
			</Section>

			<Section title={ t( 'brand' ) } help={ t( 'brandHelp' ) }>
				<BrandFields
					brand={ doc.brand }
					onText={ ( patch ) =>
						edit(
							( current ) => ( {
								...current,
								brand: { ...current.brand, ...patch },
							} ),
							AFTER_TYPING
						)
					}
					onColour={ ( colours ) =>
						edit(
							( current ) => ( {
								...current,
								brand: { ...current.brand, colours },
							} ),
							AFTER_DRAGGING
						)
					}
				/>
			</Section>

			<Section title={ t( 'delivery' ) } help={ t( 'deliveryHelp' ) }>
				<Delivery
					delivery={ doc.delivery }
					tracking={ doc.tracking }
					houseOptions={ data.delivery.houseOptions }
					onDelivery={ ( patch, delay ) =>
						edit(
							( current ) => ( {
								...current,
								delivery: { ...current.delivery, ...patch },
							} ),
							delay
						)
					}
					onTracking={ ( patch ) =>
						edit(
							( current ) => ( {
								...current,
								tracking: { ...current.tracking, ...patch },
							} ),
							AFTER_TYPING
						)
					}
				/>
			</Section>

			<Section title={ t( 'access' ) } help={ t( 'accessHelp' ) }>
				<Access
					initial={ data.roster }
					path={ `${ data.restPath }/reviewers` }
				/>
			</Section>
		</VStack>
	);
}

const root = document.getElementById( 'aggr-settings-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-settings' );
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
