/**
 * Shared portal dialog store.
 *
 * Namespace: aggr/dialog
 * State is keyed per instance at state.dialogs[ dialogId ].
 *
 * Open/close is imperative (classList + trigger listeners in init), same pattern
 * as the Aggressive Apparel modal. Declarative `data-wp-class` on nested paths
 * like state.dialogs[context.dialogId].isOpen is not reliable enough here, and
 * a preventDefault without a visible open leaves the page looking dead.
 *
 * Contract: docs/accessibility.md — focus trap on the shell, guarded restore
 * after inert lifts, reference-counted scroll lock, inert on .aggr-shell,
 * Escape closes only the top of the stack, reduced-motion collapses exit duration.
 */

import { store, getContext } from '@wordpress/interactivity';
import { lockScroll, unlockScroll } from '@aggr/scroll-lock';
import { canRestoreFocus, setupFocusTrap } from '@aggr/helpers';

const FALLBACK_BUFFER_MS = 50;
const DEFAULT_DURATION_MS = 200;
const PAGE_ROOT_SELECTOR = '.aggr-shell';

interface DialogState {
	isOpen: boolean;
	animationDuration?: number;
}

interface DialogContext {
	dialogId?: string;
}

interface DialogRefs {
	triggerElement: HTMLElement | null;
	focusTrapCleanup: ( () => void ) | null;
	closeTimer: ReturnType< typeof setTimeout > | null;
	transitionEndHandler: ( ( event: TransitionEvent ) => void ) | null;
	isClosing: boolean;
}

const dialogRefs = new Map< string, DialogRefs >();
const dialogStack: string[] = [];
const initializedIds = new Set< string >();
const boundTriggers = new WeakMap< HTMLElement, string >();

let escapeBound = false;
let dialogsStateRef: Record< string, DialogState > | null = null;

function getShell( id: string ): HTMLElement | null {
	return document.querySelector(
		`.aggr-overlay[data-dialog-id="${ CSS.escape( id ) }"]`
	);
}

function getPanel( id: string ): HTMLElement | null {
	return getShell( id )?.querySelector( '.aggr-overlay__panel' ) ?? null;
}

function getAnnouncer( id: string ): HTMLElement | null {
	return (
		getShell( id )?.querySelector( '.aggr-overlay__announcer' ) ?? null
	);
}

function syncTriggers( id: string, isOpen: boolean ): void {
	const expanded = isOpen ? 'true' : 'false';
	document
		.querySelectorAll( `[aria-controls="${ CSS.escape( id ) }"]` )
		.forEach( ( el ) => {
			el.setAttribute( 'aria-expanded', expanded );
		} );
}

function removeFromStack( id: string ): void {
	let index = dialogStack.lastIndexOf( id );
	while ( index !== -1 ) {
		dialogStack.splice( index, 1 );
		index = dialogStack.lastIndexOf( id );
	}
}

function syncBackgroundInert(): void {
	const root = document.querySelector( PAGE_ROOT_SELECTOR );
	if ( ! ( root instanceof HTMLElement ) ) {
		return;
	}

	root.inert = dialogStack.length > 0;
}

function ensureDialogState( id: string ): Record< string, DialogState > {
	if ( ! state.dialogs || typeof state.dialogs !== 'object' ) {
		state.dialogs = {};
	}

	if ( ! state.dialogs[ id ] ) {
		state.dialogs[ id ] = {
			isOpen: false,
			animationDuration: DEFAULT_DURATION_MS,
		};
	}

	dialogsStateRef = state.dialogs;
	return state.dialogs;
}

function cancelPendingClose( panel: HTMLElement | null, refs: DialogRefs ): void {
	if ( refs.closeTimer !== null ) {
		clearTimeout( refs.closeTimer );
		refs.closeTimer = null;
	}
	if ( panel && refs.transitionEndHandler ) {
		panel.removeEventListener( 'transitionend', refs.transitionEndHandler );
	}
	refs.transitionEndHandler = null;
	refs.isClosing = false;
	if ( panel ) {
		panel.style.removeProperty( 'transition' );
		panel.style.removeProperty( 'opacity' );
	}
}

function openDialog( id: string, trigger: HTMLElement | null = null ): void {
	const dialogs = ensureDialogState( id );
	const current = dialogs[ id ];
	if ( ! id || ! current || current.isOpen ) {
		return;
	}

	const shell = getShell( id );
	const panel = getPanel( id );
	if ( ! shell || ! panel ) {
		return;
	}

	let refs = dialogRefs.get( id );

	const triggerElement =
		trigger instanceof HTMLElement
			? trigger
			: document.activeElement instanceof HTMLElement
				? document.activeElement
				: null;

	if ( refs?.isClosing ) {
		cancelPendingClose( panel, refs );
		refs.triggerElement = triggerElement;
	} else {
		refs = {
			triggerElement,
			focusTrapCleanup: null,
			closeTimer: null,
			transitionEndHandler: null,
			isClosing: false,
		};
		dialogRefs.set( id, refs );
		lockScroll();
	}

	removeFromStack( id );
	dialogStack.push( id );

	void shell.offsetHeight;
	shell.classList.add( 'is-open' );
	current.isOpen = true;
	syncTriggers( id, true );
	syncBackgroundInert();

	const announcer = getAnnouncer( id );
	if ( announcer ) {
		announcer.textContent = '';
		requestAnimationFrame( () => {
			if ( dialogs[ id ]?.isOpen ) {
				announcer.textContent = announcer.dataset.label ?? '';
			}
		} );
	}

	requestAnimationFrame( () => {
		const currentRefs = dialogRefs.get( id );
		const shellEl = getShell( id );
		const panelEl = getPanel( id );
		if ( ! shellEl || ! panelEl || ! dialogs[ id ]?.isOpen || ! currentRefs ) {
			return;
		}
		if ( ! currentRefs.focusTrapCleanup ) {
			currentRefs.focusTrapCleanup = setupFocusTrap( shellEl );
		}
		panelEl.focus();
	} );
}

function closeDialog( id: string ): void {
	const dialogs = ensureDialogState( id );
	const current = dialogs[ id ];
	if ( ! id || ! current?.isOpen ) {
		return;
	}

	current.isOpen = false;
	syncTriggers( id, false );

	const announcer = getAnnouncer( id );
	if ( announcer ) {
		announcer.textContent = '';
	}

	const refs = dialogRefs.get( id );
	if ( refs ) {
		refs.isClosing = true;
	}

	const duration = current.animationDuration ?? DEFAULT_DURATION_MS;
	const shell = getShell( id );
	const panel = getPanel( id );
	const reducedMotion = window.matchMedia(
		'(prefers-reduced-motion: reduce)'
	).matches;
	const effectiveDuration = reducedMotion ? 0 : duration;

	if ( shell ) {
		shell.classList.remove( 'is-open' );
	}

	if ( panel && effectiveDuration > 0 ) {
		panel.style.transition = `opacity ${ effectiveDuration }ms ease`;
		panel.style.opacity = '0';
	}

	let done = false;
	const finish = (): void => {
		if ( done ) {
			return;
		}
		done = true;

		if ( refs?.closeTimer !== null && refs?.closeTimer !== undefined ) {
			clearTimeout( refs.closeTimer );
			refs.closeTimer = null;
		}
		if ( panel && refs?.transitionEndHandler ) {
			panel.removeEventListener(
				'transitionend',
				refs.transitionEndHandler
			);
			refs.transitionEndHandler = null;
		}
		if ( panel ) {
			panel.style.removeProperty( 'transition' );
			panel.style.removeProperty( 'opacity' );
		}
		if ( refs?.focusTrapCleanup ) {
			refs.focusTrapCleanup();
			refs.focusTrapCleanup = null;
		}

		unlockScroll();
		removeFromStack( id );
		syncBackgroundInert();

		/*
		 * Restore after inert lifts. The trigger lives in .aggr-shell; focusing
		 * it while that subtree is still inert is ignored by the browser.
		 */
		const returnFocus = refs?.triggerElement ?? null;
		if ( canRestoreFocus( returnFocus ) ) {
			returnFocus.focus( { preventScroll: true } );
		}

		if ( dialogRefs.get( id ) === refs ) {
			dialogRefs.delete( id );
		}

		if (
			typeof window !== 'undefined' &&
			window.location.hash === `#${ id }`
		) {
			history.replaceState(
				null,
				'',
				window.location.pathname + window.location.search
			);
		}
	};

	if ( panel && effectiveDuration > 0 ) {
		const handleTransitionEnd = ( event: TransitionEvent ): void => {
			if (
				event.target === panel &&
				event.propertyName === 'opacity'
			) {
				finish();
			}
		};
		if ( refs ) {
			refs.transitionEndHandler = handleTransitionEnd;
		}
		panel.addEventListener( 'transitionend', handleTransitionEnd );
	}

	const closeTimer = setTimeout(
		finish,
		effectiveDuration + FALLBACK_BUFFER_MS
	);
	if ( refs ) {
		refs.closeTimer = closeTimer;
	}
}

/**
 * Bind open/close controls for one dialog. Idempotent per element.
 */
function bindControls( id: string ): void {
	document
		.querySelectorAll( `[aria-controls="${ CSS.escape( id ) }"]` )
		.forEach( ( el ) => {
			if ( ! ( el instanceof HTMLElement ) ) {
				return;
			}
			if ( boundTriggers.get( el ) === id ) {
				return;
			}
			boundTriggers.set( el, id );

			el.setAttribute( 'aria-haspopup', 'dialog' );
			el.setAttribute(
				'aria-expanded',
				state.dialogs?.[ id ]?.isOpen ? 'true' : 'false'
			);

			el.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				openDialog( id, el );
			} );
		} );

	const shell = getShell( id );
	if ( ! shell ) {
		return;
	}

	shell
		.querySelectorAll( '[data-aggr-dialog-close]' )
		.forEach( ( el ) => {
			if ( ! ( el instanceof HTMLElement ) ) {
				return;
			}
			if ( boundTriggers.get( el ) === `close:${ id }` ) {
				return;
			}
			boundTriggers.set( el, `close:${ id }` );

			el.addEventListener( 'click', ( event ) => {
				event.preventDefault();
				closeDialog( id );
			} );
		} );
}

function bindEscape(): void {
	if ( escapeBound ) {
		return;
	}
	escapeBound = true;

	document.addEventListener(
		'keydown',
		( event ) => {
			if ( event.key !== 'Escape' || dialogStack.length === 0 ) {
				return;
			}
			const id = dialogStack[ dialogStack.length - 1 ];
			if ( ! id || ! dialogsStateRef?.[ id ]?.isOpen ) {
				return;
			}
			event.preventDefault();
			event.stopPropagation();
			closeDialog( id );
		},
		true
	);
}

interface DialogStoreState {
	dialogs: Record< string, DialogState >;
}

const { state } = store< DialogStoreState, { init: () => void } >(
	'aggr/dialog',
	{
		actions: {
			/**
			 * Idempotent per dialog id — data-wp-init can fire twice.
			 */
			init() {
				const context = getContext< DialogContext >();
				const id = context?.dialogId;
				if ( typeof id === 'string' && '' !== id ) {
					bootDialog( id );
				}
			},
		},
	}
);

function bootDialog( id: string ): void {
	bindEscape();
	ensureDialogState( id );
	bindControls( id );

	if ( initializedIds.has( id ) ) {
		return;
	}
	initializedIds.add( id );

	const current = state.dialogs[ id ];
	if (
		typeof window !== 'undefined' &&
		window.location.hash === `#${ id }` &&
		current &&
		! current.isOpen
	) {
		openDialog( id );
	}
}

/**
 * Boot every dialog shell on the page without waiting for data-wp-init.
 * Script modules print after our wp_footer(5) markup, so the shells exist.
 */
function bootAllDialogs(): void {
	document
		.querySelectorAll( '.aggr-overlay[data-dialog-id]' )
		.forEach( ( shell ) => {
			const id = shell.getAttribute( 'data-dialog-id' );
			if ( id ) {
				bootDialog( id );
			}
		} );
}

bootAllDialogs();
