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
import { createRoot, useMemo, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	TextControl,
	__experimentalHStack as HStack,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';
import '@wordpress/dataviews/build-style/style.css';
import './style.css';
import { SaveError, setStrings, t, useAction } from '../shared/save';

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
	member_list: Member[];
	members: number;
	campaigns: number;
};

type ViewPayload = { rows: Organization[] };

type Bootstrap = {
	view: ViewPayload;
	restPath: string;
	i18n: Record< string, string >;
};

const EMPTY: Bootstrap = { view: { rows: [] }, restPath: '', i18n: {} };

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
	org,
	busy,
	onTransfer,
	onRemove,
	onInvite,
}: {
	org: Organization;
	busy: boolean;
	onTransfer: ( member: Member ) => void;
	onRemove: ( member: Member ) => void;
	onInvite: ( email: string ) => void;
} ): ReactElement {
	const [ email, setEmail ] = useState( '' );

	const others = org.member_list.filter( ( member ) => ! member.is_owner );

	return (
		<VStack spacing={ 4 }>
			{ 0 === others.length ? (
				<Text variant="muted">{ t( 'onlyOwner' ) }</Text>
			) : (
				<></>
			) }

			{ org.member_list.map( ( member ) => (
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

const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 20,
	sort: { field: 'name', direction: 'asc' },
	filters: [],
	titleField: 'name',
	fields: [ 'owner_name', 'members', 'campaigns', 'state' ],
	layout: {},
};

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ rows, setRows ] = useState( data.view.rows );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );
	const [ done, setDone ] = useState( '' );
	const { error, busy, run, clearError } = useAction< {
		view: ViewPayload;
	} >();

	const write = async (
		options: Record< string, unknown >,
		message: string
	): Promise< void > => {
		setDone( '' );
		clearError();

		const result = await run( () =>
			apiFetch< { view: ViewPayload } >( options )
		);

		if ( result ) {
			// The server's roster, not a local edit. One change moves more than
			// it names — a transfer demotes the previous owner, a removal can
			// strip a portal role — and only the server knows what else moved.
			setRows( result.view.rows );
			setDone( message );
		}
	};

	const change = ( org: Organization, state: string ): Promise< void > =>
		write(
			{
				path: `${ data.restPath }/${ org.id }/state`,
				method: 'POST',
				data: { state },
			},
			'suspended' === state ? t( 'suspended' ) : t( 'reactivated' )
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
				label: t( 'ownerColumn' ),
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'members',
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

					if ( ! selected ) {
						return <></>;
					}

					/*
					 * The live row, not the one DataViews captured when the
					 * modal opened. Every action here rewrites the roster —
					 * a transfer demotes the previous owner, a removal drops
					 * a member — and rendering the captured copy would show
					 * the roster as it was before the change the user just
					 * made.
					 */
					const org =
						rows.find( ( row ) => row.id === selected.id ) ??
						selected;

					return (
						<Roster
							org={ org }
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
		[ busy, rows, data.restPath ]
	);

	const { data: shown, paginationInfo } = useMemo(
		() => filterSortAndPaginate( rows, view, fields ),
		[ rows, view, fields ]
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

			{ 0 === rows.length ? (
				<p>{ t( 'empty' ) }</p>
			) : (
				<DataViews< Organization >
					data={ shown }
					fields={ fields }
					view={ view }
					onChangeView={ setView }
					actions={ actions }
					paginationInfo={ paginationInfo }
					getItemId={ ( item ) => String( item.id ) }
					isLoading={ busy }
					defaultLayouts={ { table: {} } }
				/>
			) }
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
