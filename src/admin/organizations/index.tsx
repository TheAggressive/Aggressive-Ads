/**
 * The organization roster, in core's component set.
 *
 * Read-mostly. The only write is suspend or reactivate, and suspension is the
 * most consequential button on any staff screen: it stops every campaign an
 * organization is running. So it confirms first, and it is never a toggle —
 * a switch invites a stray click, and there is no undo that un-shows the ads
 * that stopped serving in the meantime.
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
	CardDivider,
	CardHeader,
	Notice,
	TextControl,
	__experimentalConfirmDialog as ConfirmDialog,
	__experimentalHStack as HStack,
	__experimentalHeading as Heading,
	__experimentalText as Text,
	__experimentalVStack as VStack,
} from '@wordpress/components';
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

type View = { rows: Organization[] };

type Bootstrap = {
	view: View;
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
 * The roster, with the two corrections staff actually need.
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
		<VStack spacing={ 3 }>
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

/**
 * One titled block inside a card.
 *
 * The first version of this screen stacked a name field, a member list and an
 * invite box with nothing between them, and it read as one long form where
 * every control looked equally consequential. Renaming and removing somebody
 * are not equally consequential.
 */
function Section( {
	title,
	children,
}: {
	title: string;
	children: ReactElement | ReactElement[];
} ): ReactElement {
	return (
		<VStack spacing={ 3 }>
			<Heading level={ 4 }>{ title }</Heading>
			{ children }
		</VStack>
	);
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

function Row( {
	org,
	busy,
	onSuspend,
	onReactivate,
	onRename,
	roster,
}: {
	org: Organization;
	busy: boolean;
	onSuspend: () => void;
	onReactivate: () => void;
	onRename: ( name: string ) => void;
	roster: ReactElement;
} ): ReactElement {
	const [ name, setName ] = useState( org.name );
	return (
		<Card>
			<CardHeader>
				<HStack justify="space-between" alignment="center">
					<VStack spacing={ 0 }>
						<Heading level={ 3 }>{ org.name }</Heading>
						<Text variant="muted">
							{ counted(
								org.members,
								t( 'memberOne' ),
								t( 'memberMany' )
							) }
							{ ' \u00b7 ' }
							{ counted(
								org.campaigns,
								t( 'campaignOne' ),
								t( 'campaignMany' )
							) }
							{ ' \u00b7 ' }
							{ org.active
								? t( 'stateActive' )
								: t( 'stateSuspended' ) }
						</Text>
					</VStack>

					{ org.active ? (
						<Button
							variant="secondary"
							isDestructive
							disabled={ busy }
							// The button says "Suspend"; without the name a
							// screen reader moving control to control cannot
							// tell which organization it would suspend.
							aria-label={ `${ t( 'suspend' ) }: ${ org.name }` }
							onClick={ onSuspend }
						>
							{ t( 'suspend' ) }
						</Button>
					) : (
						<Button
							variant="secondary"
							disabled={ busy }
							aria-label={ `${ t( 'reactivate' ) }: ${
								org.name
							}` }
							onClick={ onReactivate }
						>
							{ t( 'reactivate' ) }
						</Button>
					) }
				</HStack>
			</CardHeader>

			<CardBody>
				<Section title={ t( 'detailsSection' ) }>
					<HStack
						justify="flex-start"
						alignment="flex-end"
						spacing={ 3 }
					>
						<Field>
							<TextControl
								label={ t( 'name' ) }
								value={ name }
								onChange={ setName }
								__nextHasNoMarginBottom
								__next40pxDefaultSize
							/>
						</Field>
						<Button
							variant="secondary"
							__next40pxDefaultSize
							disabled={ busy || name.trim() === org.name }
							onClick={ () => onRename( name.trim() ) }
						>
							{ t( 'rename' ) }
						</Button>
					</HStack>
				</Section>
			</CardBody>

			<CardDivider />

			<CardBody>
				<Section title={ t( 'membersSection' ) }>{ roster }</Section>
			</CardBody>
		</Card>
	);
}

function App( { data }: { data: Bootstrap } ): ReactElement {
	const [ view, setView ] = useState( data.view );
	const [ done, setDone ] = useState( '' );
	const [ confirming, setConfirming ] = useState< Organization | null >(
		null
	);
	const { error, busy, run, clearError } = useAction< { view: View } >();

	const write = async (
		options: Record< string, unknown >,
		message: string
	): Promise< void > => {
		setDone( '' );
		clearError();

		const result = await run( () => apiFetch< { view: View } >( options ) );

		if ( result ) {
			// The server's roster, not a local edit. One change moves more than
			// it names — a transfer demotes the previous owner, a removal can
			// strip a portal role — and only the server knows what else moved.
			setView( result.view );
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

			{ 0 === view.rows.length ? (
				<p>{ t( 'empty' ) }</p>
			) : (
				view.rows.map( ( org ) => (
					<Row
						key={ `${ org.id }-${ org.name }-${ org.owner_id }` }
						org={ org }
						busy={ busy }
						onSuspend={ () => setConfirming( org ) }
						onReactivate={ () => void change( org, 'active' ) }
						onRename={ ( name ) =>
							void write(
								{
									path: `${ data.restPath }/${ org.id }`,
									method: 'PATCH',
									data: { name },
								},
								t( 'renamed' )
							)
						}
						roster={
							<Roster
								org={ org }
								busy={ busy }
								onTransfer={ ( member ) =>
									void write(
										{
											path: `${ data.restPath }/${ org.id }/owner`,
											method: 'POST',
											data: { user_id: member.id },
										},
										t( 'ownerChanged' )
									)
								}
								onRemove={ ( member ) =>
									void write(
										{
											path: `${ data.restPath }/${ org.id }/members/${ member.id }`,
											method: 'DELETE',
										},
										t( 'memberRemoved' )
									)
								}
								onInvite={ ( email ) =>
									void write(
										{
											path: `${ data.restPath }/${ org.id }/members`,
											method: 'POST',
											data: { email },
										},
										t( 'invited' )
									)
								}
							/>
						}
					/>
				) )
			) }

			{ /*
			   Suspension confirms; reactivation does not. The asymmetry is the
			   point: one stops live advertising for a paying customer, the other
			   restores it, and only one of those is worth a second thought.
			*/ }
			<ConfirmDialog
				isOpen={ null !== confirming }
				onConfirm={ () => {
					const org = confirming;
					setConfirming( null );

					if ( org ) {
						void change( org, 'suspended' );
					}
				} }
				onCancel={ () => setConfirming( null ) }
				confirmButtonText={ t( 'suspend' ) }
			>
				{ confirming
					? t( 'confirmSuspend' ).replace( '%s', confirming.name )
					: '' }
			</ConfirmDialog>
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
