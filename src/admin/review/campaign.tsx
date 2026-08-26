/**
 * One campaign under review: everything staff decide, on one screen.
 *
 * Every decision here posts to a route that re-checks the capability and audits
 * the outcome, and every response carries the refreshed campaign — so the
 * screen renders what the server holds rather than a local guess. Approving a
 * change rewrites fields, busts the fill cache and adds an audit row; only the
 * server knows what else moved.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { Dialog } from './dialog';
import { t } from '../shared/save';
import type { Campaign, Creative, CreativeUpdate, ReviewAction } from './types';
import { requestOf } from './types';

/** One creative, previewed through the authenticated file route. */
function CreativeCard( {
	creative,
	children,
}: {
	creative: Creative | CreativeUpdate;
	children?: ReactElement | null;
} ): ReactElement {
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

	return (
		<article className="aggr-creative">
			<div className="aggr-creative__preview">
				<img
					src={ creative.preview }
					alt={ creative.alt_text }
					loading="lazy"
				/>
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
function Decision( {
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
				<button
					type="button"
					className="aggr-button"
					disabled={ busy }
					onClick={ () => onDecide( 'approve', notes ) }
				>
					{ label }
				</button>
			</div>
			<label htmlFor={ id }>{ noteLabel }</label>
			<textarea
				id={ id }
				rows={ 4 }
				maxLength={ 2000 }
				value={ notes }
				onChange={ ( event ) => setNotes( event.target.value ) }
			/>
			<button
				type="button"
				className="aggr-button aggr-button--danger"
				disabled={ busy || '' === notes.trim() }
				onClick={ () => onDecide( 'reject', notes ) }
			>
				{ rejectLabel }
			</button>
		</div>
	);
}

/** The tone a transition is drawn in. Approval is the only assertion. */
function toneClass( action: ReviewAction ): string {
	if ( action.destructive ) {
		return 'aggr-button aggr-button--danger';
	}

	return action.positive
		? 'aggr-button aggr-button--positive'
		: 'aggr-button';
}

/**
 * The feedback a refusal requires, collected in a dialog.
 *
 * The box is compulsory, so the confirm button stays disabled until there is
 * something in it — GUARD_REVIEW_NOTES would refuse an empty one anyway, and
 * saying so before the click is kinder than saying so after.
 */
function FeedbackDialog( {
	action,
	busy,
	onConfirm,
	onClose,
}: {
	action: ReviewAction | null;
	busy: boolean;
	onConfirm: ( to: string, notes: string ) => void;
	onClose: () => void;
} ): ReactElement {
	const [ notes, setNotes ] = useState( '' );

	return (
		<Dialog
			open={ null !== action }
			title={ action ? action.label : '' }
			labelId="aggr-review-dialog-title"
			onClose={ onClose }
		>
			<div className="aggr-form">
				<label htmlFor="aggr-dialog-feedback">
					{ t( 'advertiserFeedback' ) }
				</label>
				<textarea
					id="aggr-dialog-feedback"
					rows={ 6 }
					maxLength={ 2000 }
					value={ notes }
					onChange={ ( event ) => setNotes( event.target.value ) }
				/>
			</div>
			<div className="aggr-overlay__actions">
				<button
					type="button"
					className="aggr-button aggr-button--secondary"
					onClick={ onClose }
				>
					{ t( 'cancel' ) }
				</button>
				<button
					type="button"
					className={ action ? toneClass( action ) : 'aggr-button' }
					disabled={ busy || '' === notes.trim() }
					onClick={ () => {
						if ( action ) {
							onConfirm( action.to, notes );
						}
					} }
				>
					{ action ? action.label : '' }
				</button>
			</div>
		</Dialog>
	);
}

/** The staff-only notes, saved on a button rather than as you type. */
function InternalNotes( {
	value,
	busy,
	onSave,
}: {
	value: string;
	busy: boolean;
	onSave: ( notes: string ) => void;
} ): ReactElement {
	const [ notes, setNotes ] = useState( value );

	return (
		<section className="aggr-panel" aria-labelledby="aggr-internal-notes">
			<h2 id="aggr-internal-notes" className="aggr-panel__head">
				{ t( 'internalNotes' ) }
			</h2>
			<div className="aggr-form">
				<label htmlFor="aggr-internal-notes-field">
					{ t( 'staffOnly' ) }
				</label>
				<textarea
					id="aggr-internal-notes-field"
					rows={ 7 }
					value={ notes }
					onChange={ ( event ) => setNotes( event.target.value ) }
				/>
				<button
					type="button"
					className="aggr-button aggr-button--secondary"
					disabled={ busy }
					onClick={ () => onSave( notes ) }
				>
					{ t( 'saveInternalNotes' ) }
				</button>
			</div>
		</section>
	);
}

export function CampaignView( {
	campaign,
	busy,
	onBack,
	onEdit,
	onTransition,
	onNotes,
	onChanges,
	onDeclineRequest,
	onReplacement,
}: {
	campaign: Campaign;
	busy: boolean;
	onBack: () => void;
	onEdit: () => void;
	onTransition: ( to: string, notes: string ) => void;
	onNotes: ( notes: string ) => void;
	onChanges: ( decision: string, notes: string ) => void;
	onDeclineRequest: ( notes: string ) => void;
	onReplacement: ( id: number, decision: string, notes: string ) => void;
} ): ReactElement {
	const request = requestOf( campaign );
	const changesPlacements = campaign.pending_edits.some(
		( row ) => 'placement_ids' === row.field
	);

	/*
	 * Every action lives in the header now, including the two that need
	 * advertiser-facing feedback. Those open a dialog carrying the textarea
	 * instead of each printing one down the page — a screen that showed two
	 * identical "Feedback the advertiser will see" boxes stacked above their
	 * own buttons made a refusal look like the primary thing to do here.
	 */
	const [ prompting, setPrompting ] = useState< ReviewAction | null >( null );

	return (
		<>
			{ /*
			 * Everything except the dialog, so the dialog can make it inert
			 * without inerting itself. This replaces the portal-to-<body> a
			 * modal would normally use: every --aggr-* token is declared on
			 * `.aggr-portal`, and in wp-admin that class is on this wrap rather
			 * than on <body>, so a dialog rendered outside it resolves every
			 * token to nothing.
			 */ }
			<div data-aggr-review-content>
				<p className="aggr-breadcrumb">
					<button
						type="button"
						className="aggr-linkbutton"
						onClick={ onBack }
					>
						{ `← ${ t( 'backToQueue' ) }` }
					</button>
				</p>

				{ /*
				 * The pill sits with the heading, not as a third child of
				 * .aggr-pagehead. That container is space-between, so a status left
				 * on its own lands midway across the screen and reads as belonging
				 * to nothing — the same defect fixed on the advertiser's campaign
				 * screen in 6015287. .aggr-pagehead__heading centres the two and
				 * wraps the pill to its own line rather than squeezing it when a
				 * campaign name is long.
				 */ }
				<header className="aggr-pagehead">
					<div>
						<div className="aggr-pagehead__heading">
							<h1 className="aggr-title">{ campaign.title }</h1>
							<span
								className={ `aggr-pill aggr-pill--${ campaign.pill }` }
							>
								{ campaign.status_text }
							</span>
						</div>
						<p className="aggr-lede">{ campaign.org_name }</p>
					</div>

					<div className="aggr-pagehead__actions">
						{ /*
						 * Editing opens the advertiser's own wizard rather than
						 * a second editor here. It is a link, not a button,
						 * because it leaves wp-admin — and it is always
						 * offered, since the whole point of on-behalf editing
						 * is the statuses where the advertiser has no
						 * transition available.
						 */ }
						<button
							type="button"
							className="aggr-button aggr-button--secondary"
							disabled={ busy }
							onClick={ () => onEdit() }
						>
							{ t( 'editCampaign' ) }
						</button>

						{ campaign.actions.map( ( action ) => (
							<button
								key={ action.to }
								type="button"
								className={ toneClass( action ) }
								disabled={ busy }
								onClick={ () =>
									action.needs_notes
										? setPrompting( action )
										: onTransition( action.to, '' )
								}
							>
								{ action.label }
							</button>
						) ) }
					</div>
				</header>

				<section
					className="aggr-panel"
					aria-labelledby="aggr-review-summary"
				>
					<h2 id="aggr-review-summary" className="aggr-panel__head">
						{ t( 'campaignSummary' ) }
					</h2>
					<dl className="aggr-facts">
						{ [
							[ t( 'organization' ), campaign.org_name ],
							[
								t( 'placements' ),
								campaign.placements.join( ', ' ),
							],
							[ t( 'schedule' ), campaign.schedule_text ],
							[
								t( 'reviewer' ),
								'' === campaign.reviewer
									? t( 'unassigned' )
									: campaign.reviewer,
							],
							[
								t( 'submission' ),
								'' === campaign.submitted_text
									? t( 'notSubmitted' )
									: campaign.submitted_text,
							],
							[ t( 'revision' ), String( campaign.revision ) ],
						].map( ( [ term, detail ] ) => (
							<div className="aggr-fact" key={ term }>
								<dt>{ term }</dt>
								<dd>{ detail }</dd>
							</div>
						) ) }
					</dl>
				</section>

				<section
					className="aggr-panel"
					aria-labelledby="aggr-review-line-items"
				>
					<h2
						id="aggr-review-line-items"
						className="aggr-panel__head"
					>
						{ t( 'deliveryStrategy' ) }
					</h2>
					{ campaign.line_items.map( ( item ) => (
						<dl className="aggr-facts" key={ item.id }>
							{ [
								[ t( 'lineItem' ), item.name ],
								[
									t( 'status' ),
									item.status.replaceAll( '_', ' ' ),
								],
								[
									t( 'pricing' ),
									item.pricing_model.toUpperCase(),
								],
								[
									t( 'goal' ),
									item.goal_type.replaceAll( '_', ' ' ),
								],
								[ t( 'pacing' ), item.pacing_mode ],
							].map( ( [ term, detail ] ) => (
								<div className="aggr-fact" key={ term }>
									<dt>{ term }</dt>
									<dd>{ detail }</dd>
								</div>
							) ) }
						</dl>
					) ) }
				</section>

				{ '' === campaign.review_notes ? null : (
					<section
						className="aggr-notice"
						aria-labelledby="aggr-review-feedback"
					>
						<h2
							id="aggr-review-feedback"
							className="aggr-notice__head"
						>
							{ t( 'advertiserFacingFeedback' ) }
						</h2>
						<p>{ campaign.review_notes }</p>
					</section>
				) }

				<section
					className="aggr-panel"
					aria-labelledby="aggr-review-creatives"
				>
					<h2 id="aggr-review-creatives" className="aggr-panel__head">
						{ t( 'creativeReview' ) }
					</h2>
					{ 0 === campaign.creatives.length ? (
						<div className="aggr-empty">
							<h3 className="aggr-empty__title">
								{ t( 'noCreativeTitle' ) }
							</h3>
							<p>{ t( 'noCreativeBody' ) }</p>
						</div>
					) : (
						<div className="aggr-creative-grid">
							{ campaign.creatives.map( ( creative ) => (
								<CreativeCard
									key={ creative.id }
									creative={ creative }
								/>
							) ) }
						</div>
					) }
				</section>

				{ request ? (
					<section
						className="aggr-panel"
						aria-labelledby="aggr-action-request"
					>
						<h2
							id="aggr-action-request"
							className="aggr-panel__head"
						>
							{ t( 'advertiserAsked' ) }
						</h2>
						<p>
							{ t( 'requested' ).replace(
								'%s',
								request.action_label
							) }
						</p>
						{ '' === request.reason ? null : (
							<blockquote>{ request.reason }</blockquote>
						) }
						<p className="aggr-hint">{ t( 'requestHint' ) }</p>
						<DeclineRequest
							busy={ busy }
							onDecline={ onDeclineRequest }
						/>
					</section>
				) : null }

				{ 0 === campaign.pending_edits.length ? null : (
					<section
						className="aggr-panel"
						aria-labelledby="aggr-campaign-changes"
					>
						<h2
							id="aggr-campaign-changes"
							className="aggr-panel__head"
						>
							{ t( 'requestedChanges' ) }
						</h2>
						<p>{ t( 'requestedChangesLede' ) }</p>
						<div
							className="aggr-tablewrap"
							role="region"
							aria-label={ t( 'requestedChanges' ) }
							tabIndex={ 0 }
						>
							<table className="aggr-table">
								<thead>
									<tr>
										<th scope="col">{ t( 'field' ) }</th>
										<th scope="col">
											{ t( 'currently' ) }
										</th>
										<th scope="col">
											{ t( 'requestedCol' ) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ campaign.pending_edits.map( ( row ) => (
										<tr key={ row.field }>
											<td className="aggr-table__primary">
												{ row.label }
											</td>
											<td>{ row.from }</td>
											<td>{ row.to }</td>
										</tr>
									) ) }
								</tbody>
							</table>
						</div>
						{ changesPlacements ? (
							<p className="aggr-hint">
								<strong>{ t( 'placementChangeWarn' ) }</strong>{ ' ' }
								{ t( 'placementChangeBody' ) }
							</p>
						) : null }
						<Decision
							label={ t( 'approveChanges' ) }
							rejectLabel={ t( 'rejectChanges' ) }
							noteLabel={ t( 'rejectionFeedback' ) }
							busy={ busy }
							onDecide={ onChanges }
						/>
					</section>
				) }

				{ 0 === campaign.creative_updates.length ? null : (
					<section
						className="aggr-panel"
						aria-labelledby="aggr-creative-updates"
					>
						<h2
							id="aggr-creative-updates"
							className="aggr-panel__head"
						>
							{ t( 'pendingUpdates' ) }
						</h2>
						<p>{ t( 'pendingUpdatesLede' ) }</p>
						<div className="aggr-creative-grid">
							{ campaign.creative_updates.map( ( update ) => (
								<CreativeCard
									key={ update.id }
									creative={ update }
								>
									<Decision
										label={ t( 'approveReplace' ) }
										rejectLabel={ t( 'rejectUpdate' ) }
										noteLabel={ t( 'rejectionFeedback' ) }
										busy={ busy }
										onDecide={ ( decision, notes ) =>
											onReplacement(
												update.id,
												decision,
												notes
											)
										}
									/>
								</CreativeCard>
							) ) }
						</div>
					</section>
				) }

				{ /*
				 * Internal notes stand alone now. The Review Actions panel beside
				 * it held nothing but the two feedback boxes, and those moved into
				 * the dialog the header buttons open.
				 */ }
				{ 0 === campaign.actions.length ? (
					<section
						className="aggr-panel"
						aria-labelledby="aggr-review-actions"
					>
						<h2
							id="aggr-review-actions"
							className="aggr-panel__head"
						>
							{ t( 'reviewActions' ) }
						</h2>
						<p className="aggr-empty">{ t( 'noActions' ) }</p>
					</section>
				) : null }

				<InternalNotes
					// Remounted when the server's copy changes, so the box shows
					// what was stored rather than what was typed.
					key={ campaign.internal_notes }
					value={ campaign.internal_notes }
					busy={ busy }
					onSave={ onNotes }
				/>

				{ campaign.can_view_audit ? (
					<section
						className="aggr-panel"
						aria-labelledby="aggr-audit-timeline"
					>
						<h2
							id="aggr-audit-timeline"
							className="aggr-panel__head"
						>
							{ t( 'auditTimeline' ) }
						</h2>
						{ 0 === campaign.audit.length ? (
							<p className="aggr-empty">{ t( 'noAudit' ) }</p>
						) : (
							<ol className="aggr-timeline">
								{ campaign.audit.map( ( event, index ) => (
									<li
										className="aggr-timeline__item"
										key={ `${ event.created_at }-${ index }` }
									>
										<div className="aggr-timeline__message">
											{ event.message }
										</div>
										<div className="aggr-timeline__meta">
											{ `${
												'' === event.actor
													? t( 'unknownUser' )
													: event.actor
											} · ${ event.created_text } · ${
												event.outcome
											}` }
										</div>
									</li>
								) ) }
							</ol>
						) }
					</section>
				) : null }
			</div>

			<FeedbackDialog
				// Remounted per action, so the box opens empty rather than
				// carrying what somebody typed into a decision they abandoned.
				key={ prompting ? prompting.to : 'none' }
				action={ prompting }
				busy={ busy }
				onConfirm={ ( to, notes ) => {
					setPrompting( null );
					onTransition( to, notes );
				} }
				onClose={ () => setPrompting( null ) }
			/>
		</>
	);
}

/** Declining a request needs a reason the advertiser will read. */
function DeclineRequest( {
	busy,
	onDecline,
}: {
	busy: boolean;
	onDecline: ( notes: string ) => void;
} ): ReactElement {
	const [ notes, setNotes ] = useState( '' );

	return (
		<div className="aggr-form">
			<label htmlFor="aggr-decline-notes">
				{ t( 'declineExplanation' ) }
			</label>
			<textarea
				id="aggr-decline-notes"
				rows={ 3 }
				maxLength={ 2000 }
				value={ notes }
				onChange={ ( event ) => setNotes( event.target.value ) }
			/>
			<button
				type="button"
				className="aggr-button aggr-button--secondary"
				disabled={ busy }
				onClick={ () => onDecline( notes ) }
			>
				{ t( 'declineRequest' ) }
			</button>
		</div>
	);
}
