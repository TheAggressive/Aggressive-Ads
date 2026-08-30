/**
 * The conversion definitions screen.
 *
 * Core's component set, like Settings and Packages — this screen is ordinary
 * WordPress admin furniture, not the plugin's own design system, so there is
 * nothing here for `admin.css` to fight.
 *
 * Every write goes to `REST\Conversion_Definitions_Controller`, which is thin
 * over `Conversion_Definition_Manager`. There is no second rule set in this
 * file, and there must not be: the manager is what the integration tests
 * exercise, so anything decided here would be untested by construction.
 */

import {
	Button,
	Card,
	CardBody,
	CardHeader,
	Notice,
	SelectControl,
	Spinner,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { createRoot } from '@wordpress/element';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

type Definition = {
	id: number;
	org_id: number;
	public_key: string;
	name: string;
	window_seconds: number;
	default_value_micros: number;
	currency: string;
	allow_s2s: boolean;
	status: string;
	accepts_reports: boolean;
	revision: number;
};

/**
 * The screen's strings, named rather than a `Record< string, string >`.
 *
 * With `noUncheckedIndexedAccess` a record lookup is `string | undefined`, and
 * the honest fixes are either a `?? ''` at every use — which renders an
 * unlabelled control that looks merely unfinished — or a `t()` helper, which
 * this codebase already has to guard with `ReviewStringsTest` for exactly that
 * reason. Naming the keys makes the compiler the guard instead, and a string
 * PHP forgets to send is a build failure rather than a blank label.
 */
type Strings = {
	newDefinition: string;
	existing: string;
	none: string;
	name: string;
	window: string;
	windowHelp: string;
	value: string;
	valueHelp: string;
	currency: string;
	currencyHelp: string;
	orgScoped: string;
	orgScopedHelp: string;
	orgId: string;
	snippetKey: string;
	status: string;
	actions: string;
	active: string;
	archived: string;
	archive: string;
	create: string;
	days: string;
	loadFailed: string;
	saveFailed: string;
};

type Payload = {
	restPath: string;
	windows: Array< { label: string; value: string } >;
	i18n: Strings;
};

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

/**
 * Micros in, a decimal string out.
 *
 * Value is stored in millionths of a currency unit because a per-click value is
 * routinely smaller than a cent. Editors think in currency, so the conversion
 * happens at the edge rather than anywhere a total could be computed from it.
 */
const microsToAmount = ( micros: number ): string =>
	micros > 0 ? ( micros / 1000000 ).toFixed( 2 ) : '';

const amountToMicros = ( amount: string ): number => {
	const parsed = Number.parseFloat( amount );

	return Number.isFinite( parsed ) && parsed > 0
		? Math.round( parsed * 1000000 )
		: 0;
};

function Screen( { payload }: { payload: Payload } ) {
	const { restPath, windows, i18n } = payload;

	const [ definitions, setDefinitions ] = useState< Definition[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState( '' );
	const [ draft, setDraft ] = useState( emptyDraft );
	const [ saving, setSaving ] = useState( false );

	useEffect( () => {
		apiFetch< { definitions: Definition[] } >( { path: restPath } )
			.then( ( result ) => setDefinitions( result.definitions ?? [] ) )
			.catch( () => setError( i18n.loadFailed ) )
			.finally( () => setLoading( false ) );
	}, [ restPath, i18n.loadFailed ] );

	const create = async () => {
		setSaving( true );
		setError( '' );

		try {
			const result = await apiFetch< { definition: Definition } >( {
				path: restPath,
				method: 'POST',
				data: draft,
			} );

			setDefinitions( [ result.definition, ...definitions ] );
			setDraft( emptyDraft() );
		} catch ( failure ) {
			// The manager's message is already translated and already written
			// for the person reading it, so it travels unchanged.
			const message = ( failure as { message?: string } )?.message;
			setError( message || i18n.saveFailed );
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

	if ( loading ) {
		return <Spinner />;
	}

	return (
		<>
			{ '' !== error ? (
				<Notice status="error" onRemove={ () => setError( '' ) }>
					{ error }
				</Notice>
			) : null }

			<Card>
				<CardHeader>
					<h2>{ i18n.newDefinition }</h2>
				</CardHeader>
				<CardBody>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.name }
						value={ draft.name }
						onChange={ ( name ) => setDraft( { ...draft, name } ) }
					/>

					<SelectControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.window }
						help={ i18n.windowHelp }
						value={ String( draft.window_seconds ) }
						options={ windows }
						onChange={ ( value ) =>
							setDraft( {
								...draft,
								window_seconds: Number( value ),
							} )
						}
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.value }
						help={ i18n.valueHelp }
						value={ microsToAmount( draft.default_value_micros ) }
						onChange={ ( amount ) =>
							setDraft( {
								...draft,
								default_value_micros: amountToMicros( amount ),
							} )
						}
					/>

					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ i18n.currency }
						help={ i18n.currencyHelp }
						value={ draft.currency }
						maxLength={ 3 }
						onChange={ ( currency ) =>
							setDraft( {
								...draft,
								currency: currency.toUpperCase(),
							} )
						}
					/>

					<ToggleControl
						__nextHasNoMarginBottom
						label={ i18n.orgScoped }
						help={ i18n.orgScopedHelp }
						checked={ draft.org_id > 0 }
						onChange={ ( scoped ) =>
							setDraft( { ...draft, org_id: scoped ? 1 : 0 } )
						}
					/>

					{ draft.org_id > 0 ? (
						<TextControl
							__nextHasNoMarginBottom
							__next40pxDefaultSize
							label={ i18n.orgId }
							type="number"
							value={ String( draft.org_id ) }
							onChange={ ( value ) =>
								setDraft( {
									...draft,
									org_id: Number( value ) || 0,
								} )
							}
						/>
					) : null }

					<Button
						variant="primary"
						onClick={ create }
						isBusy={ saving }
						disabled={ saving || '' === draft.name.trim() }
					>
						{ i18n.create }
					</Button>
				</CardBody>
			</Card>

			<Card style={ { marginTop: '16px' } }>
				<CardHeader>
					<h2>{ i18n.existing }</h2>
				</CardHeader>
				<CardBody>
					{ 0 === definitions.length ? (
						<p>{ i18n.none }</p>
					) : (
						<table className="widefat striped">
							<thead>
								<tr>
									<th scope="col">{ i18n.name }</th>
									<th scope="col">{ i18n.snippetKey }</th>
									<th scope="col">{ i18n.window }</th>
									<th scope="col">{ i18n.status }</th>
									<th scope="col">
										<span className="screen-reader-text">
											{ i18n.actions }
										</span>
									</th>
								</tr>
							</thead>
							<tbody>
								{ definitions.map( ( row ) => (
									<tr key={ row.id }>
										<td>{ row.name }</td>
										<td>
											<code>{ row.public_key }</code>
										</td>
										<td>
											{ Math.round(
												row.window_seconds / 86400
											) }{ ' ' }
											{ i18n.days }
										</td>
										<td>
											{ row.accepts_reports
												? i18n.active
												: i18n.archived }
										</td>
										<td>
											{ row.accepts_reports ? (
												<Button
													variant="link"
													isDestructive
													onClick={ () =>
														archive( row )
													}
												>
													{ i18n.archive }
												</Button>
											) : null }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					) }
				</CardBody>
			</Card>
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
