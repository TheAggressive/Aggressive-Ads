/**
 * The campaign view's decisions: the bar that offers them and the dialog that
 * asks before the ones that need a reason or cannot be undone.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactElement } from 'react';
import { useState } from '@wordpress/element';
import { Button, Modal, TextareaControl } from '@wordpress/components';
import { t } from '../shared/save';
import { verdictOf } from './readiness';
import type { Campaign, ReviewAction } from './types';

/**
 * The tone a transition is drawn in, as core `Button` props.
 *
 * Approval is the only filled assertion, in the Live green that marks the one
 * edge putting a campaign in front of the public (`.aggr-positive`).
 * Everything else is a step, so it stays secondary — a solid Pause beside Edit
 * read as the thing to click. A destructive step is outlined in red where it
 * is offered and filled only in the dialog that confirms it
 * (`docs/admin-ui.md`, Actions).
 *
 * @param action The transition.
 */
function tone( action: ReviewAction ): {
	variant: 'primary' | 'secondary';
	isDestructive?: boolean;
	className?: string;
} {
	if ( action.destructive ) {
		return { variant: 'secondary', isDestructive: true };
	}

	if ( action.positive ) {
		return { variant: 'primary', className: 'aggr-positive' };
	}

	return { variant: 'secondary' };
}

/**
 * Moves the reader to the checklist and puts focus on its heading, so a
 * keyboard or screen-reader user lands where a sighted one is looking.
 */
function showChecklist(): void {
	const heading = document.getElementById( 'aggr-approval-heading' );

	if ( ! heading ) {
		return;
	}

	const reduced = window.matchMedia(
		'(prefers-reduced-motion: reduce)'
	).matches;

	heading.scrollIntoView( {
		behavior: reduced ? 'auto' : 'smooth',
		block: 'start',
	} );
	heading.focus( { preventScroll: true } );
}

/**
 * Every status decision the server offers, kept in reach on a long page.
 *
 * They lived in the page header, so a reviewer read the artwork, the checklist,
 * the delivery policy and the trail, then scrolled back past all of it to act.
 * The bar sits at the end of the content and sticks to the bottom of the
 * screen (`_action-bar.css`), with one copy of each button.
 *
 * It repeats the status and the checklist's verdict beside the buttons,
 * because that is where the eye is when a decision lands: the flash notice is
 * at the top of the page, off screen by then.
 *
 * @param props          Component props.
 * @param props.campaign The campaign under review.
 * @param props.busy     Whether a write is in flight.
 * @param props.onChoose Called with the action a reviewer picked.
 */
export function DecisionBar( {
	campaign,
	busy,
	onChoose,
}: {
	campaign: Campaign;
	busy: boolean;
	onChoose: ( action: ReviewAction ) => void;
} ): ReactElement | null {
	if ( 0 === campaign.actions.length ) {
		return null;
	}

	const readiness = campaign.readiness;

	return (
		<div
			className="aggr-actionbar"
			role="region"
			aria-label={ t( 'reviewActions' ) }
		>
			<div className="aggr-actionbar__status">
				<span className={ `aggr-pill aggr-pill--${ campaign.pill }` }>
					{ campaign.status_text }
				</span>
				{ readiness ? (
					<span className="aggr-actionbar__reason">
						{ verdictOf( readiness ) }
					</span>
				) : null }
				{ readiness && ! readiness.ready ? (
					<button
						type="button"
						className="aggr-linkbutton"
						onClick={ showChecklist }
					>
						{ t( 'showBlockers' ) }
					</button>
				) : null }
			</div>

			<div className="aggr-actionbar__actions">
				{ campaign.actions.map( ( action ) => (
					<Button
						key={ action.to }
						{ ...tone( action ) }
						__next40pxDefaultSize
						disabled={ busy }
						onClick={ () => onChoose( action ) }
					>
						{ action.label }
					</Button>
				) ) }
			</div>
		</div>
	);
}

/**
 * The question asked before a decision that needs a reason or cannot be undone.
 *
 * Core's `Modal`, like every other staff screen's dialog: it traps focus,
 * closes on Escape, hides the page from assistive technology and hands focus
 * back to the button that opened it. Review had its own dialog on the portal's
 * overlay because the portal's tokens did not reach a dialog rendered outside
 * the screen's wrap; they are declared on `body.aggr-admin-screen` now
 * (`_admin-tokens.css`), so that reason is gone.
 *
 * Two jobs, one dialog. A refusal needs the advertiser-facing reason, and the
 * box is compulsory, so the confirm button stays disabled until there is
 * something in it — GUARD_REVIEW_NOTES would refuse an empty one anyway. A
 * destructive move that needs no reason — Cancel — still needs a second step:
 * it used to go through on one click, and a cancelled campaign has no way back.
 *
 * @param props           Component props.
 * @param props.action    The decision being asked about.
 * @param props.busy      Whether a write is in flight.
 * @param props.onConfirm Called with the target status and the feedback.
 * @param props.onClose   Called when the reviewer backs out.
 */
export function DecisionDialog( {
	action,
	busy,
	onConfirm,
	onClose,
}: {
	action: ReviewAction;
	busy: boolean;
	onConfirm: ( to: string, notes: string ) => void;
	onClose: () => void;
} ): ReactElement {
	const [ notes, setNotes ] = useState( '' );
	const needsNotes = action.needs_notes;

	return (
		<Modal
			title={ action.label }
			className="aggr-review-modal"
			// The feedback box when there is one, so a reviewer can type at
			// once; otherwise "Go back", the safe answer to "cannot be undone".
			focusOnMount="firstContentElement"
			onRequestClose={ onClose }
		>
			{ needsNotes ? (
				<TextareaControl
					__nextHasNoMarginBottom
					label={ t( 'advertiserFeedback' ) }
					rows={ 6 }
					maxLength={ 2000 }
					value={ notes }
					onChange={ setNotes }
				/>
			) : (
				<p className="aggr-confirm-body">{ t( 'confirmFinal' ) }</p>
			) }
			<div className="aggr-review-modal__actions">
				{ /*
				 * "Go back", not "Cancel": beside a button that says "Cancel
				 * campaign", a second "Cancel" is the one word that could mean
				 * either.
				 */ }
				<Button
					__next40pxDefaultSize
					variant="tertiary"
					onClick={ onClose }
				>
					{ t( 'goBack' ) }
				</Button>
				<Button
					__next40pxDefaultSize
					variant="primary"
					isDestructive={ action.destructive }
					disabled={ busy || ( needsNotes && '' === notes.trim() ) }
					onClick={ () => onConfirm( action.to, notes ) }
				>
					{ action.label }
				</Button>
			</div>
		</Modal>
	);
}
