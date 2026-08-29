/**
 * Delivery policy for a campaign's line item.
 *
 * These fields decide what the engine does with a candidate — priority, caps,
 * pacing, frequency, targeting and dayparts. They were storable only by hand
 * until the line-item route accepted them, so this is the first surface a
 * publisher has for any of it.
 *
 * The three JSON fields are edited as text rather than through a rule builder.
 * The server validates every shape and returns a message naming the problem, so
 * a typo is answered precisely; a builder can come later without changing what
 * is stored.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { t } from '../shared/save';
import type { LineItem } from './types';

type Props = {
	lineItem: LineItem;
	busy: boolean;
	onSave: (
		id: number,
		revision: number,
		fields: Record< string, unknown >
	) => void;
};

/** Renders stored policy as the text a person edits. */
function asText( value: Record< string, unknown > ): string {
	if ( ! value || Object.keys( value ).length === 0 ) {
		return '';
	}

	return JSON.stringify( value, null, 2 );
}

/**
 * Parses one JSON field.
 *
 * An empty box means "no policy" and sends `{}` rather than being skipped, so
 * clearing a rule is possible; anything unparseable is reported here instead of
 * being sent for the server to reject, because a local answer is faster and the
 * server's own validation still has the final say on shape.
 */
function parseField(
	raw: string
): { ok: true; value: Record< string, unknown > } | { ok: false } {
	const trimmed = raw.trim();

	if ( '' === trimmed ) {
		return { ok: true, value: {} };
	}

	try {
		const parsed: unknown = JSON.parse( trimmed );

		if (
			! parsed ||
			'object' !== typeof parsed ||
			Array.isArray( parsed )
		) {
			return { ok: false };
		}

		return { ok: true, value: parsed as Record< string, unknown > };
	} catch {
		return { ok: false };
	}
}

export function DeliveryPolicy( {
	lineItem,
	busy,
	onSave,
}: Props ): ReactElement {
	const [ priority, setPriority ] = useState( String( lineItem.priority ) );
	const [ pacing, setPacing ] = useState( lineItem.pacing_mode );
	const [ dailyCap, setDailyCap ] = useState( String( lineItem.daily_cap ) );
	const [ lifetimeCap, setLifetimeCap ] = useState(
		String( lineItem.lifetime_cap )
	);
	const [ targeting, setTargeting ] = useState(
		asText( lineItem.targeting_rules )
	);
	const [ frequency, setFrequency ] = useState(
		asText( lineItem.frequency_policy )
	);
	const [ settings, setSettings ] = useState(
		asText( lineItem.delivery_settings )
	);
	const [ localError, setLocalError ] = useState< string | null >( null );

	const submit = (): void => {
		const parsed = {
			targeting_rules: parseField( targeting ),
			frequency_policy: parseField( frequency ),
			delivery_settings: parseField( settings ),
		};

		const bad = Object.entries( parsed ).find(
			( [ , result ] ) => ! result.ok
		);

		if ( bad ) {
			setLocalError( t( 'deliveryPolicyNotJson' ) );
			return;
		}

		setLocalError( null );

		onSave( lineItem.id, lineItem.revision, {
			priority: Number( priority ),
			pacing_mode: pacing,
			daily_cap: Number( dailyCap ),
			lifetime_cap: Number( lifetimeCap ),
			targeting_rules: ( parsed.targeting_rules as { value: unknown } )
				.value,
			frequency_policy: ( parsed.frequency_policy as { value: unknown } )
				.value,
			delivery_settings: (
				parsed.delivery_settings as { value: unknown }
			 ).value,
		} );
	};

	return (
		<section className="aggr-panel" aria-labelledby="aggr-delivery-policy">
			<h2 id="aggr-delivery-policy" className="aggr-panel__head">
				{ t( 'deliveryPolicy' ) }
			</h2>
			<div className="aggr-form">
				<label htmlFor="aggr-priority">{ t( 'priority' ) }</label>
				<input
					id="aggr-priority"
					type="number"
					min={ 1 }
					value={ priority }
					onChange={ ( event ) => setPriority( event.target.value ) }
				/>

				<label htmlFor="aggr-pacing">{ t( 'pacingMode' ) }</label>
				<select
					id="aggr-pacing"
					value={ pacing }
					onChange={ ( event ) => setPacing( event.target.value ) }
				>
					<option value="even">{ t( 'pacingEven' ) }</option>
					<option value="asap">{ t( 'pacingAsap' ) }</option>
				</select>

				<label htmlFor="aggr-daily-cap">{ t( 'dailyCap' ) }</label>
				<input
					id="aggr-daily-cap"
					type="number"
					min={ 0 }
					value={ dailyCap }
					onChange={ ( event ) => setDailyCap( event.target.value ) }
				/>

				<label htmlFor="aggr-lifetime-cap">
					{ t( 'lifetimeCap' ) }
				</label>
				<input
					id="aggr-lifetime-cap"
					type="number"
					min={ 0 }
					value={ lifetimeCap }
					onChange={ ( event ) =>
						setLifetimeCap( event.target.value )
					}
				/>

				<label htmlFor="aggr-targeting">
					{ t( 'targetingRules' ) }
				</label>
				<textarea
					id="aggr-targeting"
					rows={ 6 }
					spellCheck={ false }
					value={ targeting }
					onChange={ ( event ) => setTargeting( event.target.value ) }
					aria-describedby="aggr-targeting-help"
				/>
				<p id="aggr-targeting-help" className="aggr-form__help">
					{ t( 'targetingHelp' ) }
				</p>

				<label htmlFor="aggr-frequency">
					{ t( 'frequencyPolicy' ) }
				</label>
				<textarea
					id="aggr-frequency"
					rows={ 5 }
					spellCheck={ false }
					value={ frequency }
					onChange={ ( event ) => setFrequency( event.target.value ) }
					aria-describedby="aggr-frequency-help"
				/>
				<p id="aggr-frequency-help" className="aggr-form__help">
					{ t( 'frequencyHelp' ) }
				</p>

				<label htmlFor="aggr-delivery-settings">
					{ t( 'deliverySettings' ) }
				</label>
				<textarea
					id="aggr-delivery-settings"
					rows={ 5 }
					spellCheck={ false }
					value={ settings }
					onChange={ ( event ) => setSettings( event.target.value ) }
					aria-describedby="aggr-delivery-settings-help"
				/>
				<p id="aggr-delivery-settings-help" className="aggr-form__help">
					{ t( 'deliverySettingsHelp' ) }
				</p>

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
		</section>
	);
}
