/**
 * Shared DOM helpers for portal Interactivity modules.
 */

const FOCUSABLE_SELECTOR =
	'a[href], button:not([disabled]), input:not([disabled]), ' +
	'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Trap Tab within a container. Applied to the overlay shell so close controls
 * outside the panel stay in the cycle.
 *
 * @param {HTMLElement} container
 * @return {() => void} Cleanup.
 */
export function setupFocusTrap( container ) {
	const handleKeydown = ( event ) => {
		if ( event.key !== 'Tab' ) {
			return;
		}

		const focusable = Array.from(
			container.querySelectorAll( FOCUSABLE_SELECTOR )
		).filter(
			( el ) => ! el.closest( '[hidden]' ) && ! el.closest( '[inert]' )
		);

		if ( focusable.length === 0 ) {
			event.preventDefault();
			return;
		}

		const currentIndex = focusable.indexOf(
			/** @type {HTMLElement} */ ( document.activeElement )
		);
		let nextIndex;

		if ( event.shiftKey ) {
			nextIndex =
				currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1;
		} else {
			nextIndex =
				currentIndex >= focusable.length - 1 ? 0 : currentIndex + 1;
		}

		event.preventDefault();
		focusable[ nextIndex ].focus();
	};

	container.addEventListener( 'keydown', handleKeydown );

	return () => {
		container.removeEventListener( 'keydown', handleKeydown );
	};
}

/**
 * Whether focus can safely return to an element after a dialog closes.
 *
 * @param {Element | null} element
 * @return {element is HTMLElement}
 */
export function canRestoreFocus( element ) {
	return (
		element instanceof HTMLElement &&
		element.isConnected &&
		element !== document.body &&
		typeof element.focus === 'function'
	);
}
