/**
 * Saves a portal write without taking the page away.
 *
 * Namespace: aggr/save
 *
 * Progressive enhancement over forms that already work. Every one of these
 * posts to `admin-post.php` and is answered by the same handler either way —
 * the only thing this adds is a marker asking for JSON back, so the nonce,
 * capability and validation gates a form post passes are the gates a scripted
 * save passes. There is no second endpoint to keep in step.
 *
 * **Success is handled here; failure is handed back to the page.** A refused
 * save already has a page that renders it properly: the banner naming the
 * field, the fragment reopening the dialog holding it, the message quoting
 * that placement's own limits. Reproducing any of that in a toast would be a
 * second copy of it, and a toast is the wrong place for something somebody
 * has to read and act on.
 */

import { store } from '@wordpress/interactivity';
import { endpointOf } from '@aggr/helpers';
import { navigateSameOrigin } from '../admin/shared/navigate';

type ToastLevel = 'success' | 'error' | 'warning' | 'info';

interface SaveResponse {
	ok?: boolean;
	notice?: string;
	redirect?: string;
	patch?: Record< string, string >;
}

/**
 * The live region every toast is written into.
 *
 * One host, created once. `role="status"` rather than `alert`: these announce
 * something that already succeeded, and an assertive interruption for "saved"
 * talks over whatever the reader was doing.
 */
function toastHost( level: ToastLevel ): HTMLElement {
	/*
	 * Polite for what reports, assertive for what has to be acted on. The
	 * region carries the role; the notice inside it carries none, because a
	 * live region nested in a live region is announced twice.
	 */
	const wanted =
		'error' === level || 'warning' === level ? 'alert' : 'status';
	const existing = document.querySelector(
		`.aggr-toasts__region[role="${ wanted }"]`
	);

	if ( existing instanceof HTMLElement ) {
		return existing;
	}

	/*
	 * Only reached on a screen whose layout predates the shared host. Built
	 * to the same shape so a notice is announced the same way wherever it is
	 * raised from.
	 */
	let outer = document.querySelector( '.aggr-toasts' );

	if ( ! ( outer instanceof HTMLElement ) ) {
		outer = document.createElement( 'div' );
		outer.className = 'aggr-toasts';
		document.body.appendChild( outer );
	}

	const region = document.createElement( 'div' );

	region.className = 'aggr-toasts__region';
	region.setAttribute( 'role', wanted );
	region.setAttribute(
		'aria-live',
		'alert' === wanted ? 'assertive' : 'polite'
	);
	outer.appendChild( region );

	return region;
}

/**
 * Shows one notice.
 *
 * Only a success clears itself. An error or a warning is something somebody
 * has to act on, and a message on a timer is one they can miss entirely — so
 * those stay until dismissed. That is also why every notice carries a close
 * control rather than relying on the timer to tidy up.
 *
 * @param message The sentence to show.
 * @param level   Which severity to render.
 */
function toast( message: string, level: ToastLevel = 'success' ): void {
	if ( '' === message ) {
		return;
	}

	const host = toastHost( level );
	const item = document.createElement( 'div' );

	item.className = `aggr-toast aggr-toast--${ level }`;
	item.dataset.aggrToast = level;

	const text = document.createElement( 'p' );

	text.className = 'aggr-toast__text';

	/*
	 * The same severity word the server puts on its own notices, so a screen
	 * reader hears a save and a refusal differently whichever side raised it.
	 * Colour cannot carry that, and the two halves saying it differently is
	 * how one of them quietly stops saying it at all.
	 */
	const label = document.createElement( 'span' );

	label.className = 'aggr-sr';
	label.textContent = `${ state.i18n[ `level_${ level }` ] ?? '' }: `;
	text.appendChild( label );
	text.appendChild( document.createTextNode( message ) );
	item.appendChild( text );
	item.appendChild( closeButton() );
	host.appendChild( item );

	if ( 'success' === level ) {
		startDismiss( item );
	}
}

/**
 * The control that lets somebody put a notice away themselves.
 *
 * Built here rather than cloned from the server's markup, so a notice raised
 * by script is dismissable the same way as one that arrived with the page.
 */
function closeButton(): HTMLButtonElement {
	const button = document.createElement( 'button' );

	button.type = 'button';
	button.className = 'aggr-toast__close';
	button.dataset.aggrToastClose = '';
	button.setAttribute( 'aria-label', state.i18n.dismiss ?? 'Dismiss' );

	// The character itself, not an entity through `innerHTML`. Nothing here
	// needs to parse markup, and `check-form-endpoints`' sibling guard is
	// right that a path which can is a path that can inject.
	button.textContent = '\u00d7';

	return button;
}

/**
 * Fades one notice out and removes it.
 *
 * @param item The notice element.
 */
function startDismiss( item: HTMLElement ): void {
	window.setTimeout( () => {
		item.classList.add( 'is-leaving' );
		window.setTimeout( () => item.remove(), 200 );
	}, 4000 );
}

function closeDialogAround( form: HTMLFormElement ): void {
	const shell = form.closest( '.aggr-overlay' );

	if ( ! ( shell instanceof HTMLElement ) ) {
		return;
	}

	/*
	 * Closed by clicking the dialog's own close control rather than by
	 * stripping the class. The dialog module owns the focus trap, the `inert`
	 * on the shell and the focus restore, and reaching past it to hide the
	 * panel would leave every one of those applied to a dialog nobody can see.
	 */
	const closer = shell.querySelector< HTMLElement >(
		'[data-aggr-dialog-close]'
	);

	closer?.click();
}

/**
 * Brings the page up to date from what the server said changed.
 *
 * **The server decides, not the form.** Reading the new value off the form
 * that was just submitted works only while a write changes nothing but its
 * own field, and a share is not like that: it is a percentage of the other
 * creatives on the placement, so saving one restates all of them. Patching
 * just the edited card would leave its neighbours showing numbers that no
 * longer add up, which is worse than not updating at all because it looks
 * settled.
 *
 * Text only, never markup. A response that could insert HTML into the page
 * is a response that can inject; and the dialogs on this screen are printed
 * separately in the footer, so swapping a card's markup would leave every
 * trigger inside it pointing at nothing.
 *
 * @param patch Element selector to its new text content.
 * @return Whether anything was applied.
 */
function applyPatch( patch: Record< string, string > | undefined ): boolean {
	const entries = Object.entries( patch ?? {} );

	if ( 0 === entries.length ) {
		return false;
	}

	entries.forEach( ( [ selector, text ] ) => {
		const node = document.querySelector( selector );

		if ( node instanceof HTMLElement ) {
			node.textContent = text;
		}
	} );

	return true;
}

async function submit( form: HTMLFormElement ): Promise< void > {
	const body = new FormData( form );

	body.set( 'aggr_async', '1' );

	let payload: SaveResponse;

	try {
		const response = await fetch( endpointOf( form ), {
			method: 'POST',
			body,
			credentials: 'same-origin',
		} );

		payload = ( await response.json() ) as SaveResponse;
	} catch {
		/*
		 * The request left and the answer did not come back, so this cannot
		 * tell whether the write landed. Reloading shows whichever it was.
		 *
		 * **Never `form.submit()` here.** That was the original answer and it
		 * re-sends a write that may already have succeeded — harmless for a
		 * destination, a second increment for anything that is not
		 * idempotent, and indistinguishable from never having intercepted at
		 * all, because it navigates to `admin-post.php` exactly as a native
		 * post does.
		 */
		window.location.reload();

		return;
	}

	if ( true !== payload.ok ) {
		/*
		 * Through the same-origin guard, because `redirect` arrives in a
		 * response body. It is ours today; a navigation sink fed from a
		 * response is one injected `javascript:` away from executing, and the
		 * guard that caught this exists because that is not hypothetical.
		 */
		if ( ! navigateSameOrigin( payload.redirect ?? '' ) ) {
			window.location.reload();
		}

		return;
	}

	/*
	 * A success the page cannot show is not a success worth a toast. The
	 * server sends nothing to patch when the write changed more than text —
	 * a creative added or removed, a control whose own meaning flipped — and
	 * a full load is the honest answer there.
	 */
	if ( ! applyPatch( payload.patch ) ) {
		if ( ! navigateSameOrigin( payload.redirect ?? '' ) ) {
			window.location.reload();
		}

		return;
	}

	toast(
		state.i18n[ payload.notice ?? '' ] ?? state.i18n.saved ?? '',
		'success'
	);
	closeDialogAround( form );
}

const { state } = store( 'aggr/save', {
	state: {
		// Empty by design: the server hydrates it and this object is merged
		// over the server's. See the note in autosave.ts.
		i18n: {} as Partial< Record< string, string > >,
	},
} );

/**
 * One listener on the document, not one per form.
 *
 * **Binding each form was wrong in a way that only a browser showed.** The
 * forms live in dialogs printed in `wp_footer`, and a listener attached to a
 * node at module-evaluation time is a bet that the node the browser later
 * submits is the same node. When it is not, the form still carries the marker
 * that says it was bound, the submit is never cancelled, and the browser
 * posts the whole page — which is exactly the failure the browser suite
 * reported while every server-side test passed.
 *
 * Delegation has no such bet in it: whatever form is submitted, this sees it.
 *
 * Capture phase, so nothing between the form and the document can stop the
 * event first.
 */
document.addEventListener(
	'submit',
	( event ) => {
		const target = event.target;

		if ( ! ( target instanceof HTMLFormElement ) ) {
			return;
		}

		if ( null === target.closest( 'form[data-aggr-save]' ) ) {
			return;
		}

		/*
		 * No `reportValidity()` guard. The browser has already run constraint
		 * validation by the time `submit` fires — that is why it does not
		 * fire for an invalid form — so the call was redundant, and its early
		 * return was a path that left the native post to proceed.
		 */
		event.preventDefault();
		void submit( target );
	},
	true
);

/**
 * Marks the forms this will act on, the way `aggr/autosave` marks its own.
 *
 * Observability only: the listener is on the document and does not consult
 * this. It exists so a browser test can tell "the module never ran" from
 * "the module ran and the save still posted the page" — a distinction that
 * cost several runs to make when nothing in the DOM recorded it.
 */
function markForms(): void {
	document
		.querySelectorAll< HTMLFormElement >( 'form[data-aggr-save]' )
		.forEach( ( form ) => {
			form.dataset.aggrSaveReady = '1';
		} );
}

markForms();

export { state };

/*
 * Notices that arrived with the page.
 *
 * They are rendered by PHP so a redirect's message survives with no
 * JavaScript at all — it is already in the live region and already carries a
 * dismiss control. All this adds is the timer, and only for a success:
 * anything a reader has to act on stays until they put it away.
 */
document
	.querySelectorAll< HTMLElement >( '.aggr-toast[data-aggr-toast="success"]' )
	.forEach( startDismiss );

/**
 * Takes a spent notice out of the address bar.
 *
 * The redirect after a write has to carry its message somehow, and a query
 * parameter is the only way to do that without keeping server state — but
 * once the page has rendered it, the message is a lie that the URL keeps
 * telling. Reload and it says the creative was uploaded again; press back and
 * it says so again; send the link to somebody and it tells them.
 *
 * `replaceState` rather than a navigation: nothing reloads, no history entry
 * appears, and the reader keeps their place. The fragment survives, because
 * that is real — it is what reopened the dialog holding a refused field.
 *
 * Only the parameters this plugin adds are touched. `step` is where somebody
 * is in the wizard, not something that happened to them.
 */
function forgetSpentNotice(): void {
	const url = new URL( window.location.href );
	const spent = [
		'aggr_notice',
		'aggr_error',
		'aggr_placement',
		'aggr_creative',
	];

	if ( ! spent.some( ( key ) => url.searchParams.has( key ) ) ) {
		return;
	}

	spent.forEach( ( key ) => url.searchParams.delete( key ) );

	window.history.replaceState( window.history.state, '', url );
}

forgetSpentNotice();

document.addEventListener( 'click', ( event ) => {
	const target = event.target;

	if ( ! ( target instanceof HTMLElement ) ) {
		return;
	}

	const closer = target.closest( '[data-aggr-toast-close]' );

	closer?.closest( '.aggr-toast' )?.remove();
} );
