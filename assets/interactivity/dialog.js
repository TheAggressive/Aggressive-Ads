/**
 * Shared portal dialog store.
 *
 * Namespace: laao-advertiser-portal/dialog
 * State is keyed per instance at state.dialogs[ dialogId ].
 *
 * Open/close is imperative (classList + trigger listeners in init), same pattern
 * as the Aggressive Apparel modal. Declarative `data-wp-class` on nested paths
 * like state.dialogs[context.dialogId].isOpen is not reliable enough here, and
 * a preventDefault without a visible open leaves the page looking dead.
 *
 * Contract: docs/accessibility.md — focus trap on the shell, guarded restore,
 * reference-counted scroll lock, inert on .laao-ads-shell, Escape closes only
 * the top of the stack, reduced-motion collapses exit duration.
 */

import { store, getContext } from '@wordpress/interactivity';
import { lockScroll, unlockScroll } from '@laao-ads/scroll-lock';
import { canRestoreFocus, setupFocusTrap } from '@laao-ads/helpers';

const FALLBACK_BUFFER_MS = 50;
const DEFAULT_DURATION_MS = 200;
const PAGE_ROOT_SELECTOR = '.laao-ads-shell';

/**
 * @typedef {{ isOpen: boolean, animationDuration?: number }} DialogState
 */

/** @type {Map<string, {
 *   triggerElement: HTMLElement | null,
 *   focusTrapCleanup: (() => void) | null,
 *   closeTimer: ReturnType<typeof setTimeout> | null,
 *   transitionEndHandler: ((event: TransitionEvent) => void) | null,
 *   isClosing: boolean,
 * }>} */
const dialogRefs = new Map();

/** @type {string[]} */
const dialogStack = [];

/** @type {Set<string>} */
const initializedIds = new Set();

/** @type {WeakMap<HTMLElement, string>} */
const boundTriggers = new WeakMap();

let escapeBound = false;
/** @type {Record<string, DialogState> | null} */
let dialogsStateRef = null;

/**
 * @param {string} id
 * @return {HTMLElement | null}
 */
function getShell( id ) {
	return document.querySelector(
		`.laao-ads-overlay[data-dialog-id="${ CSS.escape( id ) }"]`
	);
}

/**
 * @param {string} id
 * @return {HTMLElement | null}
 */
function getPanel( id ) {
	return getShell( id )?.querySelector( '.laao-ads-overlay__panel' ) ?? null;
}

/**
 * @param {string} id
 * @return {HTMLElement | null}
 */
function getAnnouncer( id ) {
	return (
		getShell( id )?.querySelector( '.laao-ads-overlay__announcer' ) ?? null
	);
}

/**
 * @param {string} id
 * @param {boolean} isOpen
 */
function syncTriggers( id, isOpen ) {
	const expanded = isOpen ? 'true' : 'false';
	document
		.querySelectorAll( `[aria-controls="${ CSS.escape( id ) }"]` )
		.forEach( ( el ) => {
			el.setAttribute( 'aria-expanded', expanded );
		} );
}

/**
 * @param {string} id
 */
function removeFromStack( id ) {
	let index = dialogStack.lastIndexOf( id );
	while ( index !== -1 ) {
		dialogStack.splice( index, 1 );
		index = dialogStack.lastIndexOf( id );
	}
}

function syncBackgroundInert() {
	const root = document.querySelector( PAGE_ROOT_SELECTOR );
	if ( ! ( root instanceof HTMLElement ) ) {
		return;
	}

	root.inert = dialogStack.length > 0;
}

/**
 * @param {string} id
 * @return {Record<string, DialogState>}
 */
function ensureDialogState( id ) {
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

/**
 * @param {HTMLElement | null} panel
 * @param {{
 *   closeTimer: ReturnType<typeof setTimeout> | null,
 *   transitionEndHandler: ((event: TransitionEvent) => void) | null,
 *   isClosing: boolean,
 * }} refs
 */
function cancelPendingClose( panel, refs ) {
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

/**
 * @param {string} id
 * @param {HTMLElement | null} [trigger]
 */
function openDialog( id, trigger = null ) {
	const dialogs = ensureDialogState( id );
	if ( ! id || dialogs[ id ].isOpen ) {
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
	dialogs[ id ].isOpen = true;
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
		const current = dialogRefs.get( id );
		const shellEl = getShell( id );
		const panelEl = getPanel( id );
		if ( ! shellEl || ! panelEl || ! dialogs[ id ]?.isOpen || ! current ) {
			return;
		}
		if ( ! current.focusTrapCleanup ) {
			current.focusTrapCleanup = setupFocusTrap( shellEl );
		}
		panelEl.focus();
	} );
}

/**
 * @param {string} id
 */
function closeDialog( id ) {
	const dialogs = ensureDialogState( id );
	if ( ! id || ! dialogs[ id ]?.isOpen ) {
		return;
	}

	dialogs[ id ].isOpen = false;
	syncTriggers( id, false );

	const announcer = getAnnouncer( id );
	if ( announcer ) {
		announcer.textContent = '';
	}

	const refs = dialogRefs.get( id );
	if ( refs ) {
		refs.isClosing = true;
	}

	const duration = dialogs[ id ].animationDuration ?? DEFAULT_DURATION_MS;
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
	const finish = () => {
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

		const returnFocus = refs?.triggerElement ?? null;
		if ( canRestoreFocus( returnFocus ) ) {
			returnFocus.focus( { preventScroll: true } );
		}

		unlockScroll();
		removeFromStack( id );
		syncBackgroundInert();
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
		const handleTransitionEnd = ( /** @type {TransitionEvent} */ event ) => {
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
 *
 * @param {string} id
 */
function bindControls( id ) {
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
		.querySelectorAll( '[data-laao-ads-dialog-close]' )
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

function bindEscape() {
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

const { state } = store( 'laao-advertiser-portal/dialog', {
	actions: {
		/**
		 * Idempotent per dialog id — data-wp-init can fire twice.
		 */
		init() {
			const context = getContext();
			const id = context?.dialogId;
			if ( typeof id === 'string' && '' !== id ) {
				bootDialog( id );
			}
		},
	},
} );

/**
 * @param {string} id
 */
function bootDialog( id ) {
	bindEscape();
	ensureDialogState( id );
	bindControls( id );

	if ( initializedIds.has( id ) ) {
		return;
	}
	initializedIds.add( id );

	if (
		typeof window !== 'undefined' &&
		window.location.hash === `#${ id }` &&
		! state.dialogs[ id ].isOpen
	) {
		openDialog( id );
	}
}

/**
 * Boot every dialog shell on the page without waiting for data-wp-init.
 * Script modules print after our wp_footer(5) markup, so the shells exist.
 */
function bootAllDialogs() {
	document
		.querySelectorAll( '.laao-ads-overlay[data-dialog-id]' )
		.forEach( ( shell ) => {
			const id = shell.getAttribute( 'data-dialog-id' );
			if ( id ) {
				bootDialog( id );
			}
		} );
}

bootAllDialogs();

