/**
 * The review screen's modal, on the portal's overlay.
 *
 * Not a second dialog implementation. The markup, the classes and the focus
 * trap are the ones `src/interactivity/dialog.ts` uses for the advertiser's
 * overlays — docs/accessibility.md is explicit that building a second stack is
 * how half of them end up without a trap, and `setupFocusTrap()` already has
 * unit coverage that now protects this screen too.
 *
 * What differs is only what has to. This is React rather than the Interactivity
 * API, so open state is a prop. And it renders *in place* rather than through a
 * portal to <body>, which was the first attempt and produced a panel with no
 * background, border or padding: every --aggr-* token is declared on
 * `.aggr-portal`, the portal's front end puts that class on <body>, and
 * wp-admin does not. A dialog outside that scope resolves every token to
 * nothing — which portal.css warns about in its own header, and which is a
 * transparent panel rather than a degraded one.
 *
 * So the background is inerted by class instead: everything on the screen sits
 * in .aggr-review-content, and the dialog is its sibling.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry at all.
 */

import type { ReactNode } from 'react';
import { useEffect, useRef } from '@wordpress/element';
import { canRestoreFocus, setupFocusTrap } from '../../interactivity/helpers';
import { lockScroll, unlockScroll } from '../../interactivity/scroll-lock';
import { t } from '../shared/save';

/**
 * The background, which is everything on the screen except this dialog.
 *
 * A data attribute rather than a class, following the convention the rest of
 * the plugin already uses for behaviour hooks — `data-aggr-autosave`,
 * `data-aggr-dialog-close`. A class here would be one with no rule behind it,
 * which is indistinguishable from a class whose rule was forgotten.
 */
const PAGE_ROOT = '[data-aggr-review-content]';

export function Dialog( {
	open,
	title,
	labelId,
	onClose,
	children,
}: {
	open: boolean;
	title: string;
	labelId: string;
	onClose: () => void;
	children: ReactNode;
} ): ReactNode {
	const panel = useRef< HTMLDivElement | null >( null );
	const opener = useRef< HTMLElement | null >( null );

	useEffect( () => {
		if ( ! open ) {
			return;
		}

		// Captured before focus moves, so closing returns the reviewer to the
		// button they opened this from rather than to the top of the document.
		opener.current =
			document.activeElement instanceof HTMLElement
				? document.activeElement
				: null;

		const root = document.querySelector( PAGE_ROOT );
		const shell = root instanceof HTMLElement ? root : null;
		const release = panel.current ? setupFocusTrap( panel.current ) : null;

		if ( shell ) {
			shell.inert = true;
		}

		lockScroll();
		panel.current?.focus();

		const onKey = ( event: KeyboardEvent ): void => {
			if ( 'Escape' === event.key ) {
				event.preventDefault();
				onClose();
			}
		};

		document.addEventListener( 'keydown', onKey, true );

		return () => {
			document.removeEventListener( 'keydown', onKey, true );
			release?.();
			unlockScroll();

			if ( shell ) {
				shell.inert = false;
			}

			/*
			 * Restored after inert lifts. The trigger lives inside the content
			 * wrapper, and focusing it while that subtree is still inert is
			 * ignored by the browser — the same ordering the portal's dialog
			 * documents.
			 */
			if ( canRestoreFocus( opener.current ) ) {
				opener.current.focus( { preventScroll: true } );
			}
		};
	}, [ open, onClose ] );

	if ( ! open ) {
		return null;
	}

	return (
		<div className="aggr-overlay aggr-overlay--admin is-open">
			{ /* Clicking away closes, which is what a reviewer expects — and
			     Escape does the same, so this adds no keyboard-only path. */ }
			<div
				className="aggr-overlay__backdrop"
				aria-hidden="true"
				onClick={ onClose }
			/>
			<div
				className="aggr-overlay__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby={ labelId }
				tabIndex={ -1 }
				ref={ panel }
			>
				<div className="aggr-overlay__header">
					<h2 className="aggr-overlay__title" id={ labelId }>
						{ title }
					</h2>
					<button
						type="button"
						className="aggr-overlay__close"
						aria-label={ t( 'close' ) }
						onClick={ onClose }
					>
						{ /*
						 * The same stroked cross the portal's dialogs draw,
						 * rather than a × character. The glyph inherited the
						 * body font size and rendered thin and small inside a
						 * 44px target; this is sized by .aggr-overlay__close
						 * .aggr-icon, which already exists for exactly this.
						 */ }
						<svg
							className="aggr-icon"
							viewBox="0 0 24 24"
							width="18"
							height="18"
							fill="none"
							stroke="currentColor"
							strokeWidth="1.75"
							strokeLinecap="round"
							strokeLinejoin="round"
							aria-hidden="true"
							focusable="false"
						>
							<path d="M6 6l12 12" />
							<path d="M18 6L6 18" />
						</svg>
					</button>
				</div>
				<div className="aggr-overlay__body">{ children }</div>
			</div>
		</div>
	);
}
