/**
 * The organization roster, in DataViews.
 *
 * The previous version rendered one expanded Card per organization: name field,
 * full member list and invite box, all open at once. That reads fine at five
 * organizations and stops reading at fifty, because there is no way to find one
 * except scrolling, and every consequential control is on screen at all times.
 *
 * DataViews inverts it. The list is a table you can search, sort and filter, and
 * the writes move behind explicit actions. Suspension keeps its confirmation —
 * it is still the most consequential button on any staff screen, because it
 * stops every campaign an organization is running, and there is no undo that
 * un-shows the ads that stopped serving in the meantime. It is never a toggle.
 *
 * `@wordpress/dataviews` is bundled, not externalised. WordPress 7.1 uses
 * DataViews in the Site Editor but registers no `wp-dataviews` script handle,
 * so externalising it yields a build that succeeds and a screen that throws.
 * See the BUNDLE_NOT_EXTERNAL note in webpack.admin.config.mjs.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import apiFetch from '@wordpress/api-fetch';
import {
	createContext,
	createRoot,
	useCallback,
	useContext,
	useEffect,
	useMemo,
	useState,
} from '@wordpress/element';
import { addQueryArgs } from '@wordpress/url';
import {
	Button,
	Notice,
	Spinner,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { DataViews } from '@wordpress/dataviews';
import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';
import './style.css';
import {
	errorMessage,
	SaveError,
	setStrings,
	t,
	useAction,
} from '../shared/save';

type Member = {
	id: number;
	name: string;
	email: string;
	is_owner: boolean;
};

type Organization = {
	id: number;
	name: string;
	state: string;
	active: boolean;
	owner_id: number;
	owner_name: string;
	members: number;
	campaigns: number;
};

/**
 * One organization with the roster attached.
 *
 * The list does not carry rosters. A roster is a list of people and their
 * email addresses, and the table shows none of them — sending every one of
 * them to paint a page of twenty-five rows put the whole directory into the
 * page source. It arrives from `/detail` when a modal opens.
 */
type OrganizationDetail = Organization & { member_list: Member[] };

type ViewPayload = {
	rows: Organization[];
	total: number;
	page: number;
	perPage: number;
};

type Bootstrap = {
	view: ViewPayload;
	restPath: string;
	i18n: Record< string, string >;
};

const EMPTY: Bootstrap = {
	view: { rows: [], total: 0, page: 1, perPage: 25 },
	restPath: '',
	i18n: {},
};

/**
 * A count with its own noun, chosen server-side.
 *
 * The plural forms come from PHP because that is where the catalog lives, and
 * because "1 campaign / 2 campaigns" is an English rule that does not survive
 * translation. The payload carries both forms and the count picks one.
 */
function counted( count: number, one: string, many: string ): string {
	return ( 1 === count ? one : many ).replace( '%d', String( count ) );
}

/**
 * A control that keeps its width next to a button.
 *
 * HStack shares space between its children, so a bare TextControl beside a
 * short button ends up narrower than one beside a long button — which is how
 * the name field came out too small to read the name it held.
 */
function Field( { children }: { children: ReactElement } ): ReactElement {
	return (
		<div style={ { flex: '1 1 auto', maxWidth: '28rem' } }>
			{ children }
		</div>
	);
}

/**
 * The two corrections staff actually need, inside the members modal.
 *
 * Moving somebody between organizations is remove-here then invite-there. There
 * is no single "move": a portal account belongs to exactly one organization, so
 * the intermediate state is real — the person loses portal access until they
 * accept the new invitation — and a button that hid that would be lying about
 * what it did.
 */
function Roster( {
	members: roster,
	busy,
	onTransfer,
	onRemove,
	onInvite,
}: {
	members: Member[] | undefined;
	busy: boolean;
	onTransfer: ( member: Member ) => void;
	onRemove: ( member: Member ) => void;
	onInvite: ( email: string ) => void;
} ): ReactElement {
	const [ email, setEmail ] = useState( '' );

	if ( undefined === roster ) {
		// Undefined is "not fetched yet", which is not the same fact as an
		// empty roster, and must not render as one.
		return <Spinner />;
	}

	const others = roster.filter( ( member ) => ! member.is_owner );

	return (
		<VStack spacing={ 4 }>
			{ 0 === others.length ? (
				<Text variant="muted">{ t( 'onlyOwner' ) }</Text>
			) : (
				<></>
			) }

			{ roster.map( ( member ) => (
				<HStack key={ member.id } justify="space-between">
					<VStack spacing={ 0 }>
						<span>
							{ member.name }
							{ member.is_owner ? ` — ${ t( 'ownerTag' ) }` : '' }
						</span>
						<span>{ member.email }</span>
					</VStack>
					<HStack justify="flex-end" spacing={ 2 } expanded={ false }>
						{ member.is_owner ? (
							<></>
						) : (
							<>
								<Button
									variant="tertiary"
									disabled={ busy }
									aria-label={ `${ t( 'makeOwner' ) }: ${
										member.name
									}` }
									onClick={ () => onTransfer( member ) }
								>
									{ t( 'makeOwner' ) }
								</Button>
								<Button
									variant="tertiary"
									isDestructive
									disabled={ busy }
									aria-label={ `${ t( 'removeMember' ) }: ${
										member.name
									}` }
									onClick={ () => onRemove( member ) }
								>
									{ t( 'removeMember' ) }
								</Button>
							</>
						) }
					</HStack>
				</HStack>
			) ) }

			<VStack spacing={ 2 }>
				<HStack justify="flex-start" alignment="flex-end" spacing={ 3 }>
					<Field>
						<TextControl
							label={ t( 'inviteMember' ) }
							type="email"
							value={ email }
							autoComplete="off"
							onChange={ setEmail }
							__nextHasNoMarginBottom
							__next40pxDefaultSize
						/>
					</Field>
					<Button
						variant="secondary"
						__next40pxDefaultSize
						disabled={ busy || '' === email.trim() }
						onClick={ () => {
							onInvite( email.trim() );
							setEmail( '' );
						} }
					>
						{ t( 'invite' ) }
					</Button>
				</HStack>
				<Text variant="muted">{ t( 'inviteHelp' ) }</Text>
			</VStack>
		</VStack>
	);
}

/**
 * Sorting is the server's, and the server sorts by name.
 *
 * Owner, member count and campaign count are derived per row rather than
 * stored, so ordering by them server-side would mean assembling every
 * organization before paging — exactly the full scan paging exists to avoid.
 * Marking those columns unsortable is the honest version: the previous build
 * let you sort them, but only within whatever arbitrary 500 rows had been
 * loaded, which looked like sorting and was not.
 */
/**
 * What the action modals need, reached by context rather than by closure.
 *
 * DataViews stores the action *object* when a modal opens
 * (`setActiveModalAction( action )`) and keeps rendering that same object until
 * the modal closes. So a `RenderModal` defined inside a `useMemo` is frozen at
 * the moment of the click: rebuilding the actions array afterwards produces a
 * new object DataViews never looks at, and the modal on screen goes on reading
 * the state as it was when it opened.
 *
 * That is not theoretical — it shipped. "Manage members" fetched its roster,
 * the fetch resolved, the state updated, and the modal spun forever because the
 * copy being rendered had captured an empty roster map and could not be
 * reached by any subsequent render.
 *
 * The fix has two halves, and both are required. Every RenderModal below is a
 * module-level component, so its identity never changes and the object
 * DataViews froze still points at the live component. And everything volatile
 * arrives through this context, which re-renders consumers normally, instead of
 * through a closure that cannot be refreshed.
 */
type Screen = {
	restPath: string;
	busy: boolean;

	/**
	 * The last write failure, or ''.
	 *
	 * Modals need their own copy. `SaveError` renders in the page, and a
	 * DataViews action modal is a portal painted on top of it, so a rejected
	 * removal used to leave the modal open, the roster unchanged, and the
	 * explanation hidden behind the overlay.
	 */
	error: string;

	write: (
		options: Record< string, unknown >,
		message: string
	) => Promise< WriteResult | null >;
};

const ScreenContext = createContext< Screen | null >( null );

/** The screen context, or a throw naming the mistake rather than a null crash. */
function useScreen(): Screen {
	const screen = useContext( ScreenContext );

	if ( null === screen ) {
		throw new Error(
			'Organization modals must render inside ScreenContext.'
		);
	}

	return screen;
}

/** The write failure, rendered where the modal can actually be seen. */
function ModalError(): ReactElement | null {
	const { error } = useScreen();

	if ( '' === error ) {
		return null;
	}

	return (
		<Notice status="error" isDismissible={ false }>
			{ error }
		</Notice>
	);
}

/**
 * The roster modal, which owns the roster it shows.
 *
 * It fetches on mount rather than reading a map held by the screen, because
 * the screen cannot hand anything to a modal DataViews has already frozen.
 */
function MembersModal( {
	items,
}: {
	items: Organization[];
	closeModal?: () => void;
} ): ReactElement {
	const { restPath, busy, write } = useScreen();
	const org = items[ 0 ];
	const orgId = org?.id ?? 0;

	const [ roster, setRoster ] = useState< Member[] | undefined >( undefined );
	const [ failed, setFailed ] = useState( '' );

	useEffect( () => {
		if ( orgId <= 0 ) {
			return;
		}

		let live = true;

		setFailed( '' );

		apiFetch< { organization: OrganizationDetail } >( {
			path: `${ restPath }/${ orgId }/detail`,
		} )
			.then( ( result ) => {
				if ( live ) {
					setRoster( result.organization.member_list );
				}
			} )
			.catch( ( reason: unknown ) => {
				// A spinner that never resolves says nothing. This is the
				// failure the first version of this screen shipped: the catch
				// was empty, so a failed fetch and a slow one looked identical.
				if ( live ) {
					setFailed( errorMessage( reason ) || t( 'loadFailed' ) );
				}
			} );

		return () => {
			live = false;
		};
	}, [ restPath, orgId ] );

	if ( ! org ) {
		return <></>;
	}

	if ( '' !== failed ) {
		return (
			<Notice status="error" isDismissible={ false }>
				{ failed }
			</Notice>
		);
	}

	/** Applies a membership change and adopts the roster the server returns. */
	const apply = async (
		options: Record< string, unknown >,
		message: string
	): Promise< void > => {
		const result = await write( options, message );

		if ( result?.organization ) {
			setRoster( result.organization.member_list );
		}
	};

	return (
		<VStack spacing={ 4 }>
			<ModalError />
			<Roster
				members={ roster }
				busy={ busy }
				onTransfer={ ( member ) => {
					void apply(
						{
							path: `${ restPath }/${ org.id }/owner`,
							method: 'POST',
							data: { user_id: member.id },
						},
						t( 'ownerChanged' )
					);
				} }
				onRemove={ ( member ) => {
					void apply(
						{
							path: `${ restPath }/${ org.id }/members/${ member.id }`,
							method: 'DELETE',
						},
						t( 'memberRemoved' )
					);
				} }
				onInvite={ ( email ) => {
					void apply(
						{
							path: `${ restPath }/${ org.id }/members`,
							method: 'POST',
							data: { email },
						},
						t( 'invited' )
					);
				} }
			/>
		</VStack>
	);
}

/** Renaming, which closes only on success — a refusal must stay readable. */
function RenameModal( {
	items,
	closeModal,
}: {
	items: Organization[];
	closeModal?: () => void;
} ): ReactElement {
	const { restPath, busy, write } = useScreen();
	const org = items[ 0 ];
	const [ name, setName ] = useState( org?.name ?? '' );

	if ( ! org ) {
		return <></>;
	}

	return (
		<VStack spacing={ 4 }>
			<ModalError />
			<TextControl
				label={ t( 'name' ) }
				value={ name }
				onChange={ setName }
				__nextHasNoMarginBottom
				__next40pxDefaultSize
			/>
			<HStack justify="flex-end">
				<Button variant="tertiary" onClick={ closeModal }>
					{ t( 'cancel' ) }
				</Button>
				<Button
					variant="primary"
					__next40pxDefaultSize
					disabled={ busy || name.trim() === org.name }
					onClick={ () => {
						void write(
							{
								path: `${ restPath }/${ org.id }`,
								method: 'PATCH',
								data: { name: name.trim() },
							},
							t( 'renamed' )
						).then( ( result ) => {
							// Only on success. Closing regardless dismissed the
							// dialog over its own error message.
							if ( result ) {
								closeModal?.();
							}
						} );
					} }
				>
					{ t( 'rename' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

/*
 * Suspension confirms; reactivation does not. The asymmetry is the point: one
 * stops live advertising for a paying customer, the other restores it, and only
 * one of those is worth a second thought.
 */
function SuspendModal( {
	items,
	closeModal,
}: {
	items: Organization[];
	closeModal?: () => void;
} ): ReactElement {
	const { restPath, busy, write } = useScreen();
	const org = items[ 0 ];

	if ( ! org ) {
		return <></>;
	}

	return (
		<VStack spacing={ 4 }>
			<ModalError />
			<Text>{ t( 'confirmSuspend' ).replace( '%s', org.name ) }</Text>
			<HStack justify="flex-end">
				<Button variant="tertiary" onClick={ closeModal }>
					{ t( 'cancel' ) }
				</Button>
				<Button
					variant="primary"
					isDestructive
					__next40pxDefaultSize
					disabled={ busy }
					onClick={ () => {
						void write(
							{
								path: `${ restPath }/${ org.id }/state`,
								method: 'POST',
								data: { state: 'suspended' },
							},
							t( 'suspended' )
						).then( ( result ) => {
							if ( result ) {
								closeModal?.();
							}
						} );
					} }
				>
					{ t( 'suspend' ) }
				</Button>
			</HStack>
		</VStack>
	);
}

const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	sort: { field: 'name', direction: 'asc' },
	filters: [],
	titleField: 'name',
	fields: [ 'owner_name', 'members', 'campaigns', 'state' ],
	layout: {},
};

/**
 * Reads the state filter DataViews holds, as the REST enum spells it.
 *
 * The operator is checked, not assumed. A field with elements and no declared
 * operators gets `is` *and* `isNot` from DataViews, and reading only `value`
 * turned "State is not Active" into `filter_state=active` — a filter that
 * returned precisely the rows the user asked to exclude. The field now declares
 * `is` alone, and this refuses to translate anything else, so the two cannot
 * drift back apart silently.
 */
function stateFilter( view: DataView ): string {
	const filter = ( view.filters ?? [] ).find(
		( entry ) => 'state' === entry.field
	);

	if ( ! filter || ( filter.operator && 'is' !== filter.operator ) ) {
		return '';
	}

	const value = filter.value;

	return 'active' === value || 'suspended' === value ? value : '';
}

type WriteResult = {
	view: ViewPayload;
	organization: OrganizationDetail | null;
};

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ payload, setPayload ] = useState< ViewPayload >( data.view );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );
	const [ done, setDone ] = useState( '' );
	const [ loading, setLoading ] = useState( false );
	const [ loadError, setLoadError ] = useState( '' );

	const { error, busy, run, clearError } = useAction< WriteResult >();

	const rows = payload.rows;

	/** The query the current view describes, as the REST route spells it. */
	const query = useMemo(
		() => ( {
			page: view.page ?? 1,
			per_page: view.perPage ?? DEFAULT_VIEW.perPage,
			search: view.search ?? '',
			filter_state: stateFilter( view ),
			// Only the name is sortable, so this is a direction rather than a
			// column. Omitting it left the server answering ascending while the
			// header drew a descending arrow.
			order: 'desc' === view.sort?.direction ? 'desc' : 'asc',
		} ),
		[ view ]
	);

	/*
	 * Paging, search and filtering are the server's now.
	 *
	 * The screen used to receive every organization and sift them in the
	 * browser, which capped silently at 500: past that, searching for an
	 * organization returned nothing, and nothing distinguished that from the
	 * organization not existing.
	 */
	useEffect( () => {
		let live = true;

		setLoading( true );

		apiFetch< { view: ViewPayload } >( {
			path: addQueryArgs( data.restPath, query ),
		} )
			.then( ( result ) => {
				if ( live ) {
					setPayload( result.view );
					setLoadError( '' );
				}
			} )
			.catch( ( reason: unknown ) => {
				// A failed page must not leave the previous page on screen
				// looking like the answer to the query just typed.
				if ( live ) {
					setLoadError( errorMessage( reason ) || t( 'loadFailed' ) );
				}
			} )
			.finally( () => {
				if ( live ) {
					setLoading( false );
				}
			} );

		// The guard against a slow first request overwriting a faster second.
		return () => {
			live = false;
		};
	}, [ query, data.restPath ] );

	const write = useCallback(
		async (
			options: Record< string, unknown >,
			message: string
		): Promise< WriteResult | null > => {
			setDone( '' );
			clearError();

			const result = await run( () =>
				apiFetch< WriteResult >( {
					...options,
					path: addQueryArgs( String( options.path ), query ),
				} )
			);

			if ( result ) {
				// The server's roster, not a local edit. One change moves more than
				// it names — a transfer demotes the previous owner, a removal can
				// strip a portal role — and only the server knows what else moved.
				setPayload( result.view );
				setDone( message );
			}

			return result;
		},
		[ run, clearError, query ]
	);

	const change = useCallback(
		( org: Organization, state: string ): Promise< unknown > =>
			write(
				{
					path: `${ data.restPath }/${ org.id }/state`,
					method: 'POST',
					data: { state },
				},
				'suspended' === state ? t( 'suspended' ) : t( 'reactivated' )
			),
		[ write, data.restPath ]
	);

	const fields: DataField< Organization >[] = useMemo(
		() => [
			{
				id: 'name',
				label: t( 'name' ),
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'owner_name',
				enableSorting: false,
				label: t( 'ownerColumn' ),
				type: 'text',
			},
			{
				id: 'members',
				enableSorting: false,
				label: t( 'membersSection' ),
				type: 'integer',
				render: ( { item }: { item: Organization } ) =>
					counted(
						item.members,
						t( 'memberOne' ),
						t( 'memberMany' )
					),
			},
			{
				id: 'campaigns',
				enableSorting: false,
				label: t( 'campaignsColumn' ),
				type: 'integer',
				render: ( { item }: { item: Organization } ) =>
					counted(
						item.campaigns,
						t( 'campaignOne' ),
						t( 'campaignMany' )
					),
			},
			{
				id: 'state',
				label: t( 'stateColumn' ),
				// Elements give the filter its options and the cell its label
				// in one declaration, so a new state cannot appear in the table
				// without also appearing in the filter.
				elements: [
					{ value: 'active', label: t( 'stateActive' ) },
					{ value: 'suspended', label: t( 'stateSuspended' ) },
				],
				// `is` only. With two mutually exclusive states, "is not
				// Active" says nothing "is Suspended" does not, and offering it
				// means the server has to answer a negation it cannot express.
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Organization } ) =>
					item.active ? 'active' : 'suspended',
			},
		],
		[]
	);

	/*
	 * Every RenderModal here is a module-level component, and that is load
	 * bearing rather than tidy. DataViews freezes the action object when the
	 * modal opens, so a component defined inline would be frozen with it and
	 * could never see another render's state. See ScreenContext above.
	 */
	const actions: Action< Organization >[] = useMemo(
		() => [
			{
				id: 'members',
				label: t( 'manageMembers' ),
				isPrimary: false,
				RenderModal: MembersModal,
			},
			{
				id: 'rename',
				label: t( 'rename' ),
				RenderModal: RenameModal,
			},
			{
				id: 'suspend',
				label: t( 'suspend' ),
				isDestructive: true,
				isEligible: ( org: Organization ) => org.active,
				RenderModal: SuspendModal,
			},
			{
				id: 'reactivate',
				label: t( 'reactivate' ),
				isEligible: ( org: Organization ) => ! org.active,
				callback: ( items: Organization[] ) => {
					const org = items[ 0 ];

					if ( org ) {
						void change( org, 'active' );
					}
				},
			},
		],
		// A `callback` runs from the current render's array, so it is safe to
		// depend on `change`; the modals reach their state through context.
		[ change ]
	);

	const paginationInfo = useMemo(
		() => ( {
			totalItems: payload.total,
			totalPages: Math.max(
				1,
				Math.ceil( payload.total / Math.max( 1, payload.perPage ) )
			),
		} ),
		[ payload.total, payload.perPage ]
	);

	const screen = useMemo(
		() => ( { restPath: data.restPath, busy, error, write } ),
		[ data.restPath, busy, error, write ]
	);

	return (
		<ScreenContext.Provider value={ screen }>
			<VStack spacing={ 4 }>
				<SaveError message={ error } onRetry={ undefined } />

				{ done ? (
					<Notice
						status="success"
						isDismissible
						onRemove={ () => setDone( '' ) }
					>
						{ done }
					</Notice>
				) : null }

				{ loadError ? (
					<Notice status="error" isDismissible={ false }>
						{ loadError }
					</Notice>
				) : null }

				{ /*
			   DataViews owns the empty state from here. It renders its own "no
			   results" inside the table, which keeps the search box and the
			   filters on screen — replacing the whole table with a sentence,
			   as this screen used to, takes away the controls needed to undo
			   the query that emptied it.
			*/ }
				<DataViews< Organization >
					data={ rows }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					getItemId={ ( item ) => String( item.id ) }
					isLoading={ busy || loading }
					defaultLayouts={ { table: {} } }
					searchLabel={ t( 'searchLabel' ) }
					empty={ <p>{ t( 'empty' ) }</p> }
				/>
			</VStack>
		</ScreenContext.Provider>
	);
}

const root = document.getElementById( 'aggr-organizations-root' );

if ( root ) {
	const raw = root.getAttribute( 'data-aggr-organizations' );
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
