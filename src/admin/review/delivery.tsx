/**
 * Delivery policy for a campaign's line item.
 *
 * Priority, pacing and the two caps are ordinary fields. Frequency, hours and
 * targeting are too, for the shapes a publisher actually sets. What the fields
 * cannot show stays as the JSON that was stored, so a save cannot flatten a
 * rule the form did not understand. The server still validates every shape.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { t } from '../shared/save';
import {
	compileFrequency,
	compileSchedule,
	compileTargeting,
	isPositive,
	operatorFor,
	operatorForDimension,
	parseObject,
	policyFacts,
	readFrequency,
	readSchedule,
	readTargeting,
	type Compiled,
	type Dimension,
	type FieldView,
	type FrequencyDraft,
	type FrequencyLevel,
	type FrequencyWindow,
	type ScheduleDraft,
	type TargetingRow,
} from './policy';
import type { LineItem } from './types';

type Props = {
	lineItem: LineItem;
	/** False where the server would refuse a save; the summary still shows. */
	editable: boolean;
	busy: boolean;
	onSave: (
		id: number,
		revision: number,
		fields: Record< string, unknown >
	) => void;
};

const DAYS: Array< [ number, string ] > = [
	[ 1, 'dayMon' ],
	[ 2, 'dayTue' ],
	[ 3, 'dayWed' ],
	[ 4, 'dayThu' ],
	[ 5, 'dayFri' ],
	[ 6, 'daySat' ],
	[ 7, 'daySun' ],
];

export function DeliveryPolicy( {
	lineItem,
	editable,
	busy,
	onSave,
}: Props ): ReactElement {
	const id = lineItem.id;
	const [ priority, setPriority ] = useState( String( lineItem.priority ) );
	const [ pacing, setPacing ] = useState( lineItem.pacing_mode );
	const [ dailyCap, setDailyCap ] = useState( String( lineItem.daily_cap ) );
	const [ lifetimeCap, setLifetimeCap ] = useState(
		String( lineItem.lifetime_cap )
	);
	const [ frequency, setFrequency ] = useState( () =>
		readFrequency( lineItem.frequency_policy )
	);
	const [ schedule, setSchedule ] = useState( () =>
		readSchedule( lineItem.delivery_settings )
	);
	const [ targeting, setTargeting ] = useState( () =>
		readTargeting( lineItem.targeting_rules )
	);
	const [ localError, setLocalError ] = useState< string | null >( null );

	// Closed until somebody means to change something. A refused save keeps
	// it open, because the component only remounts on a stored revision.
	const [ open, setOpen ] = useState( false );

	const submit = (): void => {
		const compiled = {
			frequency_policy: compileView( frequency, compileFrequency ),
			delivery_settings: compileView( schedule, compileSchedule ),
			targeting_rules: compileView( targeting, compileTargeting ),
		};
		const bad = Object.values( compiled ).find( ( result ) => ! result.ok );

		if ( bad && ! bad.ok ) {
			setLocalError( t( bad.error ) );
			return;
		}

		setLocalError( null );
		onSave( lineItem.id, lineItem.revision, {
			priority: Number( priority ),
			pacing_mode: pacing,
			daily_cap: Number( dailyCap ),
			lifetime_cap: Number( lifetimeCap ),
			frequency_policy: valueOf( compiled.frequency_policy ),
			delivery_settings: valueOf( compiled.delivery_settings ),
			targeting_rules: valueOf( compiled.targeting_rules ),
		} );
	};

	return (
		<section
			className="aggr-panel"
			aria-labelledby={ `aggr-delivery-${ id }` }
		>
			<div className="aggr-policy-head">
				<h2 id={ `aggr-delivery-${ id }` } className="aggr-panel__head">
					{ t( 'deliveryPolicy' ) }
				</h2>
				{ editable ? (
					<button
						type="button"
						className="aggr-button aggr-button--secondary aggr-button--small"
						aria-expanded={ open }
						aria-controls={ `aggr-delivery-form-${ id }` }
						onClick={ () => setOpen( ! open ) }
					>
						{ open ? t( 'deliveryClose' ) : t( 'deliveryEdit' ) }
					</button>
				) : null }
			</div>

			{ /*
			 * What is serving, in one line. The form is most of a screen tall
			 * and on most campaigns every field is at its default, so it opens
			 * when somebody means to change something rather than standing open
			 * for everybody who came to read.
			 */ }
			<ul className="aggr-policy-summary">
				{ summaryOf( lineItem ).map( ( fact ) => (
					<li key={ fact }>{ fact }</li>
				) ) }
			</ul>

			{ open && editable ? (
				<div id={ `aggr-delivery-form-${ id }` }>
					<p className="aggr-form__help aggr-policy-lede">
						{ t( 'deliveryPolicyLede' ) }
					</p>
					<div className="aggr-form aggr-form--measure">
						<label htmlFor={ `aggr-priority-${ id }` }>
							{ t( 'priority' ) }
						</label>
						<input
							id={ `aggr-priority-${ id }` }
							type="number"
							min={ 1 }
							value={ priority }
							aria-describedby={ `aggr-priority-help-${ id }` }
							onChange={ ( event ) =>
								setPriority( event.target.value )
							}
						/>
						<p
							id={ `aggr-priority-help-${ id }` }
							className="aggr-form__help"
						>
							{ t( 'priorityHelp' ) }
						</p>

						<label htmlFor={ `aggr-pacing-${ id }` }>
							{ t( 'pacingMode' ) }
						</label>
						<select
							id={ `aggr-pacing-${ id }` }
							value={ pacing }
							aria-describedby={ `aggr-pacing-help-${ id }` }
							onChange={ ( event ) =>
								setPacing( event.target.value )
							}
						>
							<option value="even">{ t( 'pacingEven' ) }</option>
							<option value="asap">{ t( 'pacingAsap' ) }</option>
						</select>
						<p
							id={ `aggr-pacing-help-${ id }` }
							className="aggr-form__help"
						>
							{ t( 'pacingHelp' ) }
						</p>

						<div className="aggr-policy__caps">
							<div className="aggr-policy__cap">
								<label htmlFor={ `aggr-daily-cap-${ id }` }>
									{ t( 'dailyCap' ) }
								</label>
								<input
									id={ `aggr-daily-cap-${ id }` }
									type="number"
									min={ 0 }
									value={ dailyCap }
									aria-describedby={ `aggr-cap-help-${ id }` }
									onChange={ ( event ) =>
										setDailyCap( event.target.value )
									}
								/>
							</div>
							<div className="aggr-policy__cap">
								<label htmlFor={ `aggr-lifetime-cap-${ id }` }>
									{ t( 'lifetimeCap' ) }
								</label>
								<input
									id={ `aggr-lifetime-cap-${ id }` }
									type="number"
									min={ 0 }
									value={ lifetimeCap }
									aria-describedby={ `aggr-cap-help-${ id }` }
									onChange={ ( event ) =>
										setLifetimeCap( event.target.value )
									}
								/>
							</div>
						</div>
						<p
							id={ `aggr-cap-help-${ id }` }
							className="aggr-form__help"
						>
							{ t( 'capHelp' ) }
						</p>

						<FrequencyFields
							itemId={ id }
							view={ frequency }
							onChange={ setFrequency }
						/>
						<ScheduleFields
							itemId={ id }
							view={ schedule }
							onChange={ setSchedule }
						/>
						<TargetingFields
							itemId={ id }
							view={ targeting }
							onChange={ setTargeting }
						/>

						{ localError && (
							<p className="aggr-form__error" role="alert">
								{ localError }
							</p>
						) }

						<button
							type="button"
							className="aggr-button aggr-button--secondary"
							disabled={ busy }
							onClick={ submit }
						>
							{ t( 'saveDeliveryPolicy' ) }
						</button>
					</div>
				</div>
			) : null }
		</section>
	);
}

/**
 * The saved policy as a handful of short phrases.
 *
 * Built from the stored line item, so it describes what is serving rather
 * than an unsaved edit, and a rule the fields cannot show is called custom
 * rather than described more simply than it is (`policyFacts`).
 */
function summaryOf( item: LineItem ): string[] {
	const facts = policyFacts( item );
	const number = ( value: number ): string => value.toLocaleString();
	const parts = [
		t( 'summaryPriority' ).replace( '%s', number( facts.priority ) ),
		'asap' === facts.pacing ? t( 'pacingAsap' ) : t( 'pacingEven' ),
	];

	if ( facts.dailyCap > 0 ) {
		parts.push(
			t( 'summaryDailyCap' ).replace( '%s', number( facts.dailyCap ) )
		);
	}

	if ( facts.lifetimeCap > 0 ) {
		parts.push(
			t( 'summaryLifetimeCap' ).replace(
				'%s',
				number( facts.lifetimeCap )
			)
		);
	}

	if ( facts.dailyCap <= 0 && facts.lifetimeCap <= 0 ) {
		parts.push( t( 'summaryNoCaps' ) );
	}

	const windows: Record< FrequencyWindow, string > = {
		session: 'frequencyWindowSession',
		hour: 'frequencyWindowHour',
		day: 'frequencyWindowDay',
	};

	if ( 'limit' === facts.frequency.kind ) {
		parts.push(
			t( 'summaryFrequency' )
				.replace( '%1$s', number( facts.frequency.max ) )
				.replace( '%2$s', t( windows[ facts.frequency.window ] ) )
		);
	} else {
		parts.push(
			'custom' === facts.frequency.kind
				? t( 'summaryCustom' )
				: t( 'summaryNoFrequency' )
		);
	}

	parts.push(
		{
			any: t( 'summaryAnyTime' ),
			limited: t( 'summaryHours' ),
			custom: t( 'summaryCustom' ),
		}[ facts.hours ]
	);

	if ( 'conditions' === facts.targeting.kind ) {
		parts.push(
			( 1 === facts.targeting.count
				? t( 'summaryConditionOne' )
				: t( 'summaryConditionMany' )
			).replace( '%d', String( facts.targeting.count ) )
		);
	} else {
		parts.push(
			'custom' === facts.targeting.kind
				? t( 'summaryCustom' )
				: t( 'summaryEveryone' )
		);
	}

	return parts;
}

function FrequencyFields( {
	itemId,
	view,
	onChange,
}: {
	itemId: number;
	view: FieldView< FrequencyDraft >;
	onChange: ( next: FieldView< FrequencyDraft > ) => void;
} ): ReactElement {
	if ( 'raw' === view.mode ) {
		return (
			<RawField
				id={ `aggr-frequency-${ itemId }` }
				label={ t( 'frequencyPolicy' ) }
				value={ view.text }
				onChange={ ( text ) => onChange( { mode: 'raw', text } ) }
			/>
		);
	}

	const draft = view.value;
	const set = ( next: Partial< FrequencyDraft > ): void => {
		onChange( { mode: 'form', value: { ...draft, ...next } } );
	};

	return (
		<fieldset className="aggr-policy">
			<legend className="aggr-policy__legend">
				{ t( 'frequencyPolicy' ) }
			</legend>
			<label
				className="aggr-policy__check"
				htmlFor={ `aggr-frequency-on-${ itemId }` }
			>
				<input
					id={ `aggr-frequency-on-${ itemId }` }
					type="checkbox"
					checked={ draft.enabled }
					onChange={ ( event ) =>
						set( { enabled: event.target.checked } )
					}
				/>
				{ t( 'frequencyLimit' ) }
			</label>
			{ draft.enabled && (
				<>
					<div className="aggr-policy__row">
						<label htmlFor={ `aggr-frequency-max-${ itemId }` }>
							{ t( 'frequencyAtMost' ) }
						</label>
						<input
							id={ `aggr-frequency-max-${ itemId }` }
							type="number"
							min={ 1 }
							value={ draft.max }
							onChange={ ( event ) =>
								set( { max: event.target.value } )
							}
						/>
						<span id={ `aggr-frequency-per-${ itemId }` }>
							{ t( 'frequencyTimes' ) }
						</span>
						<select
							id={ `aggr-frequency-window-${ itemId }` }
							aria-labelledby={ `aggr-frequency-per-${ itemId }` }
							value={ draft.window }
							onChange={ ( event ) =>
								set( {
									window: event.target
										.value as FrequencyWindow,
								} )
							}
						>
							<option value="session">
								{ t( 'frequencyWindowSession' ) }
							</option>
							<option value="hour">
								{ t( 'frequencyWindowHour' ) }
							</option>
							<option value="day">
								{ t( 'frequencyWindowDay' ) }
							</option>
						</select>
					</div>
					<div className="aggr-policy__row">
						<label htmlFor={ `aggr-frequency-level-${ itemId }` }>
							{ t( 'frequencyCounted' ) }
						</label>
						<select
							id={ `aggr-frequency-level-${ itemId }` }
							value={ draft.level }
							onChange={ ( event ) =>
								set( {
									level: event.target.value as FrequencyLevel,
								} )
							}
						>
							<option value="campaign">
								{ t( 'frequencyLevelCampaign' ) }
							</option>
							<option value="line_item">
								{ t( 'frequencyLevelLineItem' ) }
							</option>
							<option value="creative">
								{ t( 'frequencyLevelCreative' ) }
							</option>
						</select>
					</div>
				</>
			) }
			<p className="aggr-form__help">{ t( 'frequencyHelp' ) }</p>
		</fieldset>
	);
}

function ScheduleFields( {
	itemId,
	view,
	onChange,
}: {
	itemId: number;
	view: FieldView< ScheduleDraft >;
	onChange: ( next: FieldView< ScheduleDraft > ) => void;
} ): ReactElement {
	if ( 'raw' === view.mode ) {
		return (
			<RawField
				id={ `aggr-hours-${ itemId }` }
				label={ t( 'deliverySettings' ) }
				value={ view.text }
				onChange={ ( text ) => onChange( { mode: 'raw', text } ) }
			/>
		);
	}

	const draft = view.value;
	const set = ( next: Partial< ScheduleDraft > ): void => {
		onChange( { mode: 'form', value: { ...draft, ...next } } );
	};

	return (
		<fieldset className="aggr-policy">
			<legend className="aggr-policy__legend">
				{ t( 'deliverySettings' ) }
			</legend>
			<label
				className="aggr-policy__check"
				htmlFor={ `aggr-hours-on-${ itemId }` }
			>
				<input
					id={ `aggr-hours-on-${ itemId }` }
					type="checkbox"
					checked={ draft.limited }
					onChange={ ( event ) =>
						set( { limited: event.target.checked } )
					}
				/>
				{ t( 'hoursLimit' ) }
			</label>
			{ draft.limited && (
				<>
					<div
						className="aggr-days"
						role="group"
						aria-label={ t( 'deliverySettings' ) }
					>
						{ DAYS.map( ( [ day, label ] ) => (
							<label
								key={ day }
								htmlFor={ `aggr-day-${ itemId }-${ day }` }
							>
								<input
									id={ `aggr-day-${ itemId }-${ day }` }
									type="checkbox"
									checked={ draft.days.includes( day ) }
									onChange={ () =>
										set( {
											days: draft.days.includes( day )
												? draft.days.filter(
														( item ) => item !== day
												  )
												: [ ...draft.days, day ],
										} )
									}
								/>
								{ t( label ) }
							</label>
						) ) }
					</div>
					<div className="aggr-policy__row">
						<label htmlFor={ `aggr-hours-start-${ itemId }` }>
							{ t( 'hoursFrom' ) }
						</label>
						<input
							id={ `aggr-hours-start-${ itemId }` }
							type="time"
							value={ draft.start }
							onChange={ ( event ) =>
								set( { start: event.target.value } )
							}
						/>
						<label htmlFor={ `aggr-hours-end-${ itemId }` }>
							{ t( 'hoursUntil' ) }
						</label>
						<input
							id={ `aggr-hours-end-${ itemId }` }
							type="time"
							value={ draft.end }
							onChange={ ( event ) =>
								set( { end: event.target.value } )
							}
						/>
					</div>
					<label htmlFor={ `aggr-timezone-${ itemId }` }>
						{ t( 'hoursTimezone' ) }
					</label>
					<input
						id={ `aggr-timezone-${ itemId }` }
						type="text"
						value={ draft.timezone }
						placeholder="America/Los_Angeles"
						onChange={ ( event ) =>
							set( { timezone: event.target.value } )
						}
					/>
				</>
			) }
			<p className="aggr-form__help">{ t( 'deliverySettingsHelp' ) }</p>
		</fieldset>
	);
}

function TargetingFields( {
	itemId,
	view,
	onChange,
}: {
	itemId: number;
	view: FieldView< TargetingRow[] >;
	onChange: ( next: FieldView< TargetingRow[] > ) => void;
} ): ReactElement {
	if ( 'raw' === view.mode ) {
		return (
			<RawField
				id={ `aggr-targeting-${ itemId }` }
				label={ t( 'targetingRules' ) }
				value={ view.text }
				onChange={ ( text ) => onChange( { mode: 'raw', text } ) }
			/>
		);
	}

	const rows = view.value;
	const setRow = ( index: number, next: TargetingRow ): void => {
		onChange( {
			mode: 'form',
			value: rows.map( ( row, i ) => ( i === index ? next : row ) ),
		} );
	};

	return (
		<fieldset className="aggr-policy">
			<legend className="aggr-policy__legend">
				{ t( 'targetingRules' ) }
			</legend>
			{ rows.map( ( row, index ) => (
				<div className="aggr-policy__row" key={ index }>
					<label
						className="screen-reader-text"
						htmlFor={ `aggr-target-dim-${ itemId }-${ index }` }
					>
						{ t( 'targetingRules' ) }
					</label>
					<select
						id={ `aggr-target-dim-${ itemId }-${ index }` }
						value={ row.dimension }
						onChange={ ( event ) => {
							const dimension = event.target.value as Dimension;
							setRow( index, {
								...row,
								dimension,
								operator: operatorForDimension(
									dimension,
									row.operator
								),
							} );
						} }
					>
						<option value="post_type">
							{ t( 'dimensionPageType' ) }
						</option>
						<option value="categories">
							{ t( 'dimensionCategory' ) }
						</option>
						<option value="terms">{ t( 'dimensionTerm' ) }</option>
						<option value="size">{ t( 'dimensionSize' ) }</option>
					</select>
					<label
						className="screen-reader-text"
						htmlFor={ `aggr-target-op-${ itemId }-${ index }` }
					>
						{ t( 'matchIs' ) }
					</label>
					<select
						id={ `aggr-target-op-${ itemId }-${ index }` }
						value={ isPositive( row.operator ) ? 'yes' : 'no' }
						onChange={ ( event ) =>
							setRow( index, {
								...row,
								operator: operatorFor(
									row.dimension,
									'yes' === event.target.value
								),
							} )
						}
					>
						<option value="yes">
							{ listFact( row.dimension )
								? t( 'matchIncludes' )
								: t( 'matchIs' ) }
						</option>
						<option value="no">
							{ listFact( row.dimension )
								? t( 'matchExcludes' )
								: t( 'matchIsNot' ) }
						</option>
					</select>
					<label
						className="screen-reader-text"
						htmlFor={ `aggr-target-value-${ itemId }-${ index }` }
					>
						{ t( 'targetingHelp' ) }
					</label>
					<input
						id={ `aggr-target-value-${ itemId }-${ index }` }
						type="text"
						value={ row.value }
						onChange={ ( event ) =>
							setRow( index, {
								...row,
								value: event.target.value,
							} )
						}
					/>
					<button
						type="button"
						className="aggr-button aggr-button--secondary"
						onClick={ () =>
							onChange( {
								mode: 'form',
								value: rows.filter( ( _, i ) => i !== index ),
							} )
						}
					>
						{ t( 'targetingRemove' ) }
					</button>
				</div>
			) ) }
			<button
				type="button"
				className="aggr-button aggr-button--secondary"
				onClick={ () =>
					onChange( {
						mode: 'form',
						value: [
							...rows,
							{
								dimension: 'post_type',
								operator: 'eq',
								value: '',
							},
						],
					} )
				}
			>
				{ t( 'targetingAdd' ) }
			</button>
			<p className="aggr-form__help">{ t( 'targetingHelp' ) }</p>
		</fieldset>
	);
}

function RawField( {
	id,
	label,
	value,
	onChange,
}: {
	id: string;
	label: string;
	value: string;
	onChange: ( text: string ) => void;
} ): ReactElement {
	return (
		<div className="aggr-policy">
			<label htmlFor={ id }>{ label }</label>
			<textarea
				id={ id }
				rows={ 6 }
				spellCheck={ false }
				value={ value }
				aria-describedby={ `${ id }-help` }
				onChange={ ( event ) => onChange( event.target.value ) }
			/>
			<p id={ `${ id }-help` } className="aggr-form__help">
				{ t( 'policyRaw' ) }
			</p>
		</div>
	);
}

function compileView< T >(
	view: FieldView< T >,
	compile: ( value: T ) => Compiled
): Compiled {
	if ( 'raw' === view.mode ) {
		return parseObject( view.text );
	}

	return compile( view.value );
}

function valueOf( result: Compiled ): Record< string, unknown > {
	return result.ok ? result.value : {};
}

function listFact( dimension: Dimension ): boolean {
	return 'categories' === dimension || 'terms' === dimension;
}
