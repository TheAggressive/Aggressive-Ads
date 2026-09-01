/**
 * Server-to-server credentials, on the screen that already owns conversions.
 *
 * Until this existed the credential half of P12 was reachable only over REST —
 * `POST /aggr/v1/conversion-credentials` with a cookie nonce — so turning on an
 * advertiser's server integration took a request to a developer rather than a
 * button. The routes are unchanged and remain the only writer; nothing here
 * decides anything `Conversion_Credential_Manager` decides.
 *
 * **The secret is rendered once and never stored.** It exists in this component
 * for as long as the modal that created it is open and nowhere else: not in the
 * list, not in a refetch, not in the audit row. That is a property of the
 * server — no read path back to it exists — and this file must not become the
 * place that quietly re-adds one, so the token is never written anywhere but
 * state.
 *
 * **Issuing is two steps in one modal**, and the second step is the point. The
 * first version dropped the secret into a notice above the form, which put the
 * one thing a person must copy *behind* the thing they had just finished using
 * and left it on screen for whoever walked past afterwards. Now the modal
 * changes to show it, says plainly that it will not be shown again, and the
 * only way out is to acknowledge it.
 *
 * The list is a DataViews table, like the organization roster, and the sorting
 * is the reason rather than the consistency. A credential list is read during an
 * incident — what is live, what has never been used, what was cut off and when —
 * and every one of those is a sort or a filter over a column.
 */

import {
	Button,
	Flex,
	Modal,
	Notice,
	SelectControl,
	TextControl,
	__experimentalVStack as VStack,
} from '@wordpress/components';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { useCallback, useEffect, useMemo, useState } from '@wordpress/element';

import apiFetch from '@wordpress/api-fetch';

import type {
	Action,
	Field as DataField,
	View as DataView,
} from '@wordpress/dataviews';

import type { Advertiser, Credential, Strings } from './types';

type Props = {
	path: string;
	advertisers: Advertiser[];
	i18n: Strings;
};

/**
 * Newest first, because the credential somebody is looking for is almost always
 * the one just issued or the one just revoked.
 */
const DEFAULT_VIEW: DataView = {
	type: 'table',
	search: '',
	page: 1,
	perPage: 25,
	sort: { field: 'created_at', direction: 'desc' },
	filters: [],
	titleField: 'label',
	fields: [ 'org_name', 'created_at', 'last_used_at', 'state' ],
	layout: {},
};

/** The issue form, and then the one moment its secret exists. */
function CredentialModal( {
	i18n,
	advertisers,
	issuing,
	error,
	token,
	onIssue,
	onClose,
}: {
	i18n: Strings;
	advertisers: Advertiser[];
	issuing: boolean;
	error: string;
	token: string;
	onIssue: ( orgId: number, label: string ) => void;
	onClose: () => void;
} ) {
	const [ label, setLabel ] = useState( '' );
	const [ orgId, setOrgId ] = useState( advertisers[ 0 ]?.id ?? 0 );
	const [ copied, setCopied ] = useState( false );

	const copy = async () => {
		try {
			await window.navigator.clipboard.writeText( token );
			setCopied( true );
		} catch {
			// A browser without clipboard access, or a page served over http.
			// The secret is on screen to be selected either way, so this is a
			// button that quietly does not confirm rather than an error worth
			// interrupting an issue for.
			setCopied( false );
		}
	};

	if ( '' !== token ) {
		return (
			<Modal
				title={ i18n.credentialIssued }
				className="aggr-conversion-modal"
				onRequestClose={ onClose }
				isDismissible={ false }
			>
				<VStack spacing={ 4 }>
					<Notice status="warning" isDismissible={ false }>
						{ i18n.issuedOnce }
					</Notice>

					<code className="aggr-conversion-secret">{ token }</code>

					<Flex justify="flex-end" gap={ 2 }>
						<Button
							variant="secondary"
							onClick={ () => void copy() }
						>
							{ copied ? i18n.copied : i18n.copy }
						</Button>
						<Button variant="primary" onClick={ onClose }>
							{ i18n.done }
						</Button>
					</Flex>
				</VStack>
			</Modal>
		);
	}

	return (
		<Modal
			title={ i18n.newCredential }
			className="aggr-conversion-modal"
			onRequestClose={ onClose }
		>
			<VStack spacing={ 4 }>
				{ '' !== error ? (
					<Notice status="error" isDismissible={ false }>
						{ error }
					</Notice>
				) : null }

				<SelectControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ i18n.advertiser }
					help={ i18n.advertiserHelp }
					value={ String( orgId ) }
					options={ advertisers.map( ( advertiser ) => ( {
						label: advertiser.name,
						value: String( advertiser.id ),
					} ) ) }
					onChange={ ( value ) => setOrgId( Number( value ) || 0 ) }
				/>

				<TextControl
					__nextHasNoMarginBottom
					__next40pxDefaultSize
					label={ i18n.label }
					help={ i18n.labelHelp }
					value={ label }
					onChange={ setLabel }
				/>

				<Flex justify="flex-end" gap={ 2 }>
					<Button variant="tertiary" onClick={ onClose }>
						{ i18n.cancel }
					</Button>
					<Button
						variant="primary"
						onClick={ () => onIssue( orgId, label ) }
						isBusy={ issuing }
						disabled={
							issuing || '' === label.trim() || orgId <= 0
						}
					>
						{ i18n.issue }
					</Button>
				</Flex>
			</VStack>
		</Modal>
	);
}

export function Credentials( { path, advertisers, i18n }: Props ) {
	const [ credentials, setCredentials ] = useState< Credential[] >( [] );
	const [ error, setError ] = useState( '' );
	const [ formError, setFormError ] = useState( '' );
	const [ token, setToken ] = useState( '' );
	const [ issuing, setIssuing ] = useState( false );
	const [ loading, setLoading ] = useState( true );
	const [ open, setOpen ] = useState( false );
	const [ view, setView ] = useState< DataView >( DEFAULT_VIEW );

	const load = useCallback( async () => {
		const result = await apiFetch< { credentials: Credential[] } >( {
			path,
		} );

		setCredentials( result.credentials ?? [] );
	}, [ path ] );

	useEffect( () => {
		load()
			.catch( () => setError( i18n.loadFailed ) )
			.finally( () => setLoading( false ) );
	}, [ load, i18n.loadFailed ] );

	const issue = async ( orgId: number, label: string ) => {
		setIssuing( true );
		setFormError( '' );

		try {
			const result = await apiFetch< { id: number; token: string } >( {
				path,
				method: 'POST',
				data: { org_id: orgId, label },
			} );

			setToken( result.token );

			// The create response carries the id and the secret and nothing
			// else — the row a person reads, with its scope name and its times
			// in the site's timezone, is composed by the index route. Refetching
			// is what keeps those two from being written twice.
			await load();
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message;
			setFormError( message || i18n.credentialFailed );
		} finally {
			setIssuing( false );
		}
	};

	const revoke = async ( credential: Credential ) => {
		if ( ! window.confirm( i18n.revokeConfirm ) ) {
			return;
		}

		setError( '' );

		try {
			await apiFetch( {
				path: `${ path }/${ credential.id }`,
				method: 'DELETE',
			} );

			await load();
		} catch ( failure ) {
			const message = ( failure as { message?: string } )?.message;
			setError( message || i18n.credentialFailed );
		}
	};

	const fields: DataField< Credential >[] = useMemo(
		() => [
			{
				id: 'label',
				label: i18n.label,
				type: 'text',
				enableGlobalSearch: true,
			},
			{
				id: 'org_name',
				label: i18n.advertiser,
				type: 'text',
				enableGlobalSearch: true,
				// An organization that has since been deleted has no name, and
				// its id is what is left to recognise it by. That row is the one
				// most worth revoking, so it must not render as a blank cell.
				getValue: ( { item }: { item: Credential } ) =>
					'' !== item.org_name ? item.org_name : `#${ item.org_id }`,
			},
			{
				/*
				 * Sorted on the stored timestamp and rendered from the string
				 * the server formatted. Sorting the formatted value would order
				 * August before July, and formatting in the browser would
				 * disagree with the audit log this list is read beside.
				 */
				id: 'created_at',
				label: i18n.issued,
				type: 'integer',
				getValue: ( { item }: { item: Credential } ) =>
					item.created_at_ts,
				render: ( { item }: { item: Credential } ) => item.created_at,
			},
			{
				id: 'last_used_at',
				label: i18n.lastUsed,
				type: 'integer',
				getValue: ( { item }: { item: Credential } ) =>
					item.last_used_at_ts,
				render: ( { item }: { item: Credential } ) =>
					'' !== item.last_used_at ? item.last_used_at : i18n.never,
			},
			{
				id: 'state',
				label: i18n.status,
				// Elements give the filter its options and the cell its label in
				// one declaration, so the two cannot come to disagree.
				elements: [
					{ value: 'live', label: i18n.live },
					{ value: 'revoked', label: i18n.revoked },
				],
				// `is` only, for the reason the organization roster records:
				// with two mutually exclusive states, "is not live" says nothing
				// "is revoked" does not.
				filterBy: { operators: [ 'is' ] },
				getValue: ( { item }: { item: Credential } ) =>
					item.live ? 'live' : 'revoked',
				render: ( { item }: { item: Credential } ) =>
					item.live
						? i18n.live
						: `${ i18n.revoked } · ${ item.revoked_at }`,
			},
		],
		[ i18n ]
	);

	/*
	 * A callback rather than a `RenderModal`, and deliberately. DataViews
	 * freezes the action object when a modal opens — which is why the
	 * organization roster's modals are module-level components reading a
	 * context — machinery worth building for an action that collects input, and
	 * not for one that asks a single irreversible yes-or-no question.
	 */
	const actions: Action< Credential >[] = useMemo(
		() => [
			{
				id: 'revoke',
				label: i18n.revoke,
				isDestructive: true,
				supportsBulk: false,
				isEligible: ( item: Credential ) => item.live,
				callback: ( items: Credential[] ) => {
					const item = items[ 0 ];

					if ( item ) {
						void revoke( item );
					}
				},
			},
		],
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ i18n, path ]
	);

	const { data: rows, paginationInfo } = useMemo(
		() => filterSortAndPaginate( credentials, view, fields ),
		[ credentials, view, fields ]
	);

	return (
		<section className="aggr-section">
			<h2>{ i18n.credentials }</h2>
			<p>{ i18n.credentialsHelp }</p>

			{ '' !== error ? (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) : null }

			<DataViews< Credential >
				data={ rows }
				fields={ fields }
				view={ view }
				onChangeView={ setView }
				actions={ actions }
				paginationInfo={ paginationInfo }
				getItemId={ ( item ) => String( item.id ) }
				isLoading={ loading }
				defaultLayouts={ { table: {} } }
				searchLabel={ i18n.searchCredentials }
				header={
					<Button
						variant="primary"
						disabled={ 0 === advertisers.length }
						onClick={ () => {
							setFormError( '' );
							setToken( '' );
							setOpen( true );
						} }
					>
						{ i18n.newCredential }
					</Button>
				}
				empty={
					<p>
						{ 0 === advertisers.length
							? i18n.noAdvertisers
							: i18n.credentialsNone }
					</p>
				}
			/>

			{ open ? (
				<CredentialModal
					i18n={ i18n }
					advertisers={ advertisers }
					issuing={ issuing }
					error={ formError }
					token={ token }
					onIssue={ ( orgId, label ) => void issue( orgId, label ) }
					onClose={ () => {
						// The secret is dropped on the way out rather than kept
						// for a reopened modal. There is no read path back to
						// it, and holding one in memory would be the beginning
						// of one.
						setToken( '' );
						setOpen( false );
					} }
				/>
			) : null }
		</section>
	);
}
