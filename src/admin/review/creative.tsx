/**
 * One creative under review, and the approve-or-refuse control it carries.
 *
 * Split out of `campaign.tsx` when that file crossed the length gate. The seam
 * is responsibility: this is everything about one piece of artwork and the
 * decision on it, which the campaign screen places but does not need to know
 * the inside of.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { Button } from '@wordpress/components';
import { DecisionHistory } from './history';
import { DevicePreview } from './preview';
import { t } from '../shared/save';
import type { Bootstrap, Creative, CreativeUpdate } from './types';

/** One creative, previewed through the authenticated file route. */
export function CreativeCard( {
	creative,
	preview,
	children,
	onPublish,
	onReject,
	busy,
}: {
	creative: Creative | CreativeUpdate;
	preview: Bootstrap[ 'preview' ];
	children?: ReactElement | null;
	onPublish?: ( id: number ) => void;
	onReject?: ( id: number, notes: string ) => void;
	busy?: boolean;
} ): ReactElement {
	const [ notes, setNotes ] = useState( '' );
	const update = 'current_url' in creative ? creative : null;

	/*
	 * A text-only revision is the same artwork with different words, and the
	 * server has verified that from the two checksums rather than taking the
	 * request's word for it. Saying so is what makes approving one at a glance
	 * a reasonable thing to do — and showing "uploaded size" twice for an image
	 * that did not change is noise that hides the one line that did.
	 */
	const textOnly = update?.text_only === true;
	const urlChanged = update ? update.current_url !== update.click_url : false;
	const altChanged = update ? update.current_alt !== update.alt_text : false;

	/*
	 * A creative added to a campaign that is already running never met the
	 * transition that publishes artwork, so it needs a decision here. Until
	 * this control existed there was nowhere to make it: no queue counter, no
	 * route, and a creative that could never serve.
	 */
	const awaiting = onPublish && true === ( creative as Creative ).awaiting;

	return (
		<article className="aggr-creative">
			<div className="aggr-creative__preview">
				{ /*
				 * No file, nothing to frame: the preview route can only refuse,
				 * and approval would be refused for the same reason. Said in
				 * words where the artwork would have been.
				 */ }
				{ creative.file_missing ? (
					<div className="aggr-creative__missing" role="note">
						<p className="aggr-creative__missing-title">
							{ t( 'artworkMissingTitle' ) }
						</p>
						<p>{ t( 'artworkMissing' ) }</p>
					</div>
				) : (
					<DevicePreview
						src={ creative.preview_frame }
						placement={ creative.placement }
						widths={ preview.widths }
						sandbox={ preview.sandbox }
					/>
				) }
			</div>
			<div className="aggr-creative__body">
				<h3>{ creative.placement }</h3>
				{ textOnly && (
					<p className="aggr-creative__badge">
						{ t( 'artworkUnchanged' ) }
					</p>
				) }
				<dl>
					{ ! textOnly && (
						<>
							<div>
								<dt>{ t( 'requiredSize' ) }</dt>
								<dd>{ creative.size }</dd>
							</div>
							<div>
								<dt>{ t( 'uploadedSize' ) }</dt>
								<dd>{ creative.dimensions }</dd>
							</div>
						</>
					) }
					{ update ? (
						<>
							{ ( ! textOnly || urlChanged ) && (
								<>
									<div>
										<dt>{ t( 'currentDestination' ) }</dt>
										<dd className="aggr-table__url">
											{ update.current_url }
										</dd>
									</div>
									<div>
										<dt>{ t( 'proposedDestination' ) }</dt>
										<dd className="aggr-table__url">
											{ update.click_url }
										</dd>
									</div>
								</>
							) }
							{ ( ! textOnly || altChanged ) && (
								<>
									<div>
										<dt>{ t( 'currentAlt' ) }</dt>
										<dd>{ update.current_alt }</dd>
									</div>
									<div>
										<dt>{ t( 'proposedAlt' ) }</dt>
										<dd>{ update.alt_text }</dd>
									</div>
								</>
							) }
						</>
					) : (
						<>
							<div>
								<dt>{ t( 'altText' ) }</dt>
								<dd>{ creative.alt_text }</dd>
							</div>
							<div>
								<dt>{ t( 'destination' ) }</dt>
								<dd className="aggr-table__url">
									{ /* Opened in a new tab and un-refereed:
									     this is an advertiser-supplied URL a
									     reviewer is deliberately visiting. */ }
									<a
										href={ creative.click_url }
										target="_blank"
										rel="noopener noreferrer"
									>
										{ creative.click_url }
									</a>
								</dd>
							</div>
						</>
					) }
				</dl>
				<DecisionHistory decisions={ creative.decisions } />
				{ awaiting ? (
					<div className="aggr-form aggr-creative__decision">
						<p className="aggr-hint">
							{ t( 'publishCreativeHint' ) }
						</p>

						<div className="aggr-form__actions">
							<Button
								variant="primary"
								__next40pxDefaultSize
								disabled={ busy }
								onClick={ () => onPublish?.( creative.id ) }
							>
								{ t( 'publishCreative' ) }
							</Button>
						</div>

						<label htmlFor={ `aggr-reject-${ creative.id }` }>
							{ t( 'rejectCreativeReason' ) }
						</label>
						<textarea
							id={ `aggr-reject-${ creative.id }` }
							rows={ 3 }
							maxLength={ 2000 }
							value={ notes }
							onChange={ ( event ) =>
								setNotes( event.target.value )
							}
						/>

						{ /*
						 * Disabled until there is a reason, the same rule the
						 * replacement rejection uses. An advertiser told only
						 * "no" learns nothing, and silence is the behaviour
						 * this whole decision exists to replace.
						 */ }
						<Button
							variant="secondary"
							isDestructive
							__next40pxDefaultSize
							disabled={ busy || '' === notes.trim() }
							onClick={ () => onReject?.( creative.id, notes ) }
						>
							{ t( 'rejectCreative' ) }
						</Button>
					</div>
				) : null }

				{ children ?? null }
			</div>
		</article>
	);
}

/**
 * A decision with a reason, where the reason is compulsory to refuse.
 *
 * The rejection note is required by the workflow, not by this form: leaving the
 * button enabled and letting the server say so would mean the reviewer types
 * nothing, clicks, and reads an error. Disabling it says the same thing before
 * the click.
 */
export function Decision( {
	label,
	rejectLabel,
	noteLabel,
	busy,
	onDecide,
}: {
	label: string;
	rejectLabel: string;
	noteLabel: string;
	busy: boolean;
	onDecide: ( decision: string, notes: string ) => void;
} ): ReactElement {
	const [ notes, setNotes ] = useState( '' );
	const id = `aggr-decision-${ label.replace( /\W+/g, '-' ) }`;

	return (
		<div className="aggr-form">
			<div className="aggr-form__actions">
				<Button
					variant="primary"
					__next40pxDefaultSize
					disabled={ busy }
					onClick={ () => onDecide( 'approve', notes ) }
				>
					{ label }
				</Button>
			</div>
			<label htmlFor={ id }>{ noteLabel }</label>
			<textarea
				id={ id }
				rows={ 4 }
				maxLength={ 2000 }
				value={ notes }
				onChange={ ( event ) => setNotes( event.target.value ) }
			/>
			<Button
				variant="secondary"
				isDestructive
				__next40pxDefaultSize
				disabled={ busy || '' === notes.trim() }
				onClick={ () => onDecide( 'reject', notes ) }
			>
				{ rejectLabel }
			</Button>
		</div>
	);
}
