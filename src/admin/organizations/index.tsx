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
	createRoot,
	useCallback,
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
import '@wordpress/dataviews/build-style/style.css';
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

/** Reads the state filter DataViews holds, as the REST enum spells it. */
function stateFilter( view: DataView ): string {
	const filter = ( view.filters ?? [] ).find(
		( entry ) => 'state' === entry.field
	);
	const value = filter?.value;

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

	// Rosters, by organization id, for whichever modals have been opened.
	const [ rosters, setRosters ] = useState<
		Record< number, Member[] | undefined >
	>( {} );

	const { error, busy, run, clearError } = useAction< WriteResult >();

	const rows = payload.rows;

	/** The query the current view describes, as the REST route spells it. */
	const query = useMemo(
		() => ( {
			page: view.page ?? 1,
			per_page: view.perPage ?? DEFAULT_VIEW.perPage,
			search: view.search ?? '',
			state: stateFilter( view ),
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
		): Promise< void > => {
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

				if ( result.organization ) {
					setRosters( ( current ) => ( {
						...current,
						[ result.organization?.id ?? 0 ]:
							result.organization?.member_list,
					} ) );
				}
			}
		},
		[ run, clearError, query ]
	);

	/** Fetches one organization's roster the first time a modal needs it. */
	const loadRoster = useCallback(
		( orgId: number ): void => {
			apiFetch< { organization: OrganizationDetail } >( {
				path: `${ data.restPath }/${ orgId }/detail`,
			} )
				.then( ( result ) => {
					setRosters( ( current ) => ( {
						...current,
						[ orgId ]: result.organization.member_list,
					} ) );
				} )
				.catch( () => {
					// The modal keeps its spinner rather than claiming the
					// organization has no members, which is a different fact.
				} );
		},
		[ data.restPath ]
	);

	const change = useCallback(
		( org: Organization, state: string ): Promise< void > =>
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
				getValue: ( { item }: { item: Organization } ) =>
					item.active ? 'active' : 'suspended',
			},
		],
		[]
	);

	const actions: Action< Organization >[] = useMemo(
		() => [
			{
				id: 'members',
				label: t( 'manageMembers' ),
				isPrimary: false,
				RenderModal: ( {
					items,
				}: {
					items: Organization[];
					closeModal?: () => void;
				} ) => {
					const selected = items[ 0 ];

					/*
					 * The live row, not the one DataViews captured when the
					 * modal opened. Every action here rewrites the roster —
					 * a transfer demotes the previous owner, a removal drops
					 * a member — and rendering the captured copy would show
					 * the roster as it was before the change the user just
					 * made.
					 */
					const org = selected
						? rows.find( ( row ) => row.id === selected.id ) ??
						  selected
						: undefined;

					const roster =
						undefined !== org ? rosters[ org.id ] : undefined;

					// Fetching is an effect, not something render does on the
					// way past: a render-phase fetch fires again on every
					// re-render the request itself causes.
					useEffect( () => {
						if ( undefined !== org && undefined === roster ) {
							loadRoster( org.id );
						}
					}, [ org, roster ] );

					if ( undefined === org ) {
						return <></>;
					}

					return (
						<Roster
							members={ roster }
							busy={ busy }
							onTransfer={ ( member ) => {
								void write(
									{
										path: `${ data.restPath }/${ org.id }/owner`,
										method: 'POST',
										data: { user_id: member.id },
									},
									t( 'ownerChanged' )
								);
							} }
							onRemove={ ( member ) => {
								void write(
									{
										path: `${ data.restPath }/${ org.id }/members/${ member.id }`,
										method: 'DELETE',
									},
									t( 'memberRemoved' )
								);
							} }
							onInvite={ ( email ) => {
								void write(
									{
										path: `${ data.restPath }/${ org.id }/members`,
										method: 'POST',
										data: { email },
									},
									t( 'invited' )
								);
							} }
						/>
					);
				},
			},
			{
				id: 'rename',
				label: t( 'rename' ),
				RenderModal: ( {
					items,
					closeModal,
				}: {
					items: Organization[];
					closeModal?: () => void;
				} ) => {
					const org = items[ 0 ];
					const [ name, setName ] = useState( org?.name ?? '' );

					if ( ! org ) {
						return <></>;
					}

					return (
						<VStack spacing={ 4 }>
							<TextControl
								label={ t( 'name' ) }
								value={ name }
								onChange={ setName }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
							<HStack justify="flex-end">
								<Button
									variant="tertiary"
									onClick={ closeModal }
								>
									{ t( 'cancel' ) }
								</Button>
								<Button
									variant="primary"
									__next40pxDefaultSize
									disabled={
										busy || name.trim() === org.name
									}
									onClick={ () => {
										void write(
											{
												path: `${ data.restPath }/${ org.id }`,
												method: 'PATCH',
												data: { name: name.trim() },
											},
											t( 'renamed' )
										);
										closeModal?.();
									} }
								>
									{ t( 'rename' ) }
								</Button>
							</HStack>
						</VStack>
					);
				},
			},
			{
				id: 'suspend',
				label: t( 'suspend' ),
				isDestructive: true,
				isEligible: ( org: Organization ) => org.active,
				/*
				   Suspension confirms; reactivation does not. The asymmetry is
				   the point: one stops live advertising for a paying customer,
				   the other restores it, and only one of those is worth a
				   second thought.
				*/
				RenderModal: ( {
					items,
					closeModal,
				}: {
					items: Organization[];
					closeModal?: () => void;
				} ) => {
					const org = items[ 0 ];

					if ( ! org ) {
						return <></>;
					}

					return (
						<VStack spacing={ 4 }>
							<Text>
								{ t( 'confirmSuspend' ).replace(
									'%s',
									org.name
								) }
							</Text>
							<HStack justify="flex-end">
								<Button
									variant="tertiary"
									onClick={ closeModal }
								>
									{ t( 'cancel' ) }
								</Button>
								<Button
									variant="primary"
									isDestructive
									__next40pxDefaultSize
									onClick={ () => {
										void change( org, 'suspended' );
										closeModal?.();
									} }
								>
									{ t( 'suspend' ) }
								</Button>
							</HStack>
						</VStack>
					);
				},
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
		/*
		 * `busy`, `rows` and `write` are all closed over. Rebuilding the
		 * actions when they change is what keeps a disabled button honest and
		 * what lets the open members modal show the roster it just rewrote —
		 * a stale closure here would silently undo that.
		 */
		[ busy, rows, rosters, data.restPath, write, change, loadRoster ]
	);

	/*
	 * The server counted, so the server's total is the one shown.
	 *
	 * Deriving totalPages from rows.length here would report one page however
	 * many there are, and the pager would look correct while going nowhere.
	 */
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

	return (
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
