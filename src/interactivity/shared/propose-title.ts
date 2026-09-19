/**
 * Renaming a running campaign from its heading, as a proposed change.
 *
 * Bundled into `@aggr/save`. Creation renames from the heading and saves at
 * once; a running campaign renames from the same heading, with the same
 * control (`rename-heading.ts`), and the new name joins the proposal the
 * advertiser is assembling — nothing changes on the campaign until the
 * review team accepts it.
 *
 * The write is the edit flow's own form post: the same handler, nonce and
 * rules as "Save and continue", asked for JSON instead of a redirect.
 */

import { navigateSameOrigin } from '../../admin/shared/navigate';
import { enableRename, type RenameOutcome } from './rename-heading';

type Notify = ( message: string, level: 'success' | 'error' ) => void;

interface StageAnswer {
	ok?: boolean;
	notice?: string;
	redirect?: string;
}

/**
 * Shows the staged name in "Your changes" without reloading the step.
 *
 * Text only, built here: the row is the same shape the server draws, so a
 * later page load replaces it with an identical one.
 *
 * @param data The heading's data attributes.
 * @param was  The name before this change.
 * @param next The proposed name.
 */
function showInSummary( data: DOMStringMap, was: string, next: string ): void {
	const card = document.querySelector( '[data-aggr-changes-summary]' );

	if ( ! ( card instanceof HTMLElement ) ) {
		return;
	}

	let rows = card.querySelector< HTMLElement >( '.aggr-summary__rows' );

	if ( ! rows ) {
		rows = document.createElement( 'dl' );
		rows.className = 'aggr-summary__rows';
		card.querySelector( '.aggr-changes__none' )?.replaceWith( rows );
	}

	let row = rows.querySelector< HTMLElement >( '[data-aggr-change="title"]' );

	// The original name, not the last proposal: a second rename is still one change.
	const original =
		row?.querySelector< HTMLElement >( '[data-aggr-change-was]' )?.dataset
			.aggrChangeWas ?? was;

	if ( ! row ) {
		row = document.createElement( 'div' );
		row.dataset.aggrChange = 'title';
		rows.prepend( row );
	}

	const term = document.createElement( 'dt' );
	const value = document.createElement( 'dd' );
	const sub = document.createElement( 'span' );

	term.textContent = data.aggrLabelField ?? '';
	sub.className = 'aggr-summary__sub';
	sub.dataset.aggrChangeWas = original;
	sub.textContent = ( data.aggrLabelWas ?? '%s' ).replace( '%s', original );
	value.append( document.createTextNode( `${ next } ` ), sub );
	row.replaceChildren( term, value );
}

/**
 * Wires the heading of a running campaign's edit flow.
 *
 * @param heading The `<h1>` carrying `data-aggr-propose`.
 * @param notify  How the page reports an outcome.
 * @param fetcher Network access; `fetch` in the browser.
 * @return Whether it attached.
 */
export function initProposedTitle(
	heading: HTMLElement,
	notify: Notify,
	fetcher: typeof window.fetch = ( url, init ) => window.fetch( url, init )
): boolean {
	const data = heading.dataset;

	if ( ! data.aggrPropose || '1' === data.aggrTitleReady ) {
		return false;
	}

	data.aggrTitleReady = '1';

	let refusal = '';

	const save = async ( next: string ): Promise< RenameOutcome > => {
		const body = new FormData();

		body.set( 'action', data.aggrAction ?? '' );
		body.set( 'campaign_id', data.aggrCampaign ?? '' );
		body.set( '_wpnonce', data.aggrNonce ?? '' );
		body.set( 'next_step', data.aggrStep ?? '' );
		body.set( 'title', next );
		body.set( 'aggr_async', '1' );

		try {
			const response = await fetcher( data.aggrPropose ?? '', {
				method: 'POST',
				credentials: 'same-origin',
				headers: { Accept: 'application/json' },
				body,
			} );
			const answer = ( await response.json() ) as StageAnswer;

			refusal = answer.notice ?? '';

			if ( true !== answer.ok ) {
				return 'error';
			}

			/*
			 * Review lists the changes and offers Submit only when there are
			 * some, so on that step the page is drawn again. Elsewhere the step
			 * may hold unsaved fields, and redrawing would throw them away.
			 */
			if ( 'review' === data.aggrStep && answer.redirect ) {
				navigateSameOrigin( answer.redirect );
			}

			return 'saved';
		} catch {
			refusal = '';

			return 'error';
		}
	};

	let before = ( heading.textContent ?? '' ).trim();

	enableRename( heading, {
		hint: data.aggrLabelRename ?? '',
		label: data.aggrLabelName ?? '',
		maxLength: 200,
		save,
		said: ( outcome, name ) => {
			if ( 'saved' === outcome ) {
				showInSummary( data, before, name );
				before = name;
				notify( data.aggrLabelSaved ?? '', 'success' );
			} else if ( 'empty' === outcome ) {
				notify( data.aggrLabelEmpty ?? '', 'error' );
			} else {
				notify(
					'' !== refusal ? refusal : data.aggrLabelError ?? '',
					'error'
				);
			}
		},
	} );

	return true;
}
