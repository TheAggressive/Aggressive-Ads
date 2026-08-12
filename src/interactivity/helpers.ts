/**
 * Shared DOM helpers for portal Interactivity modules.
 */

const FOCUSABLE_SELECTOR =
	'a[href], button:not([disabled]), input:not([disabled]), ' +
	'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

/**
 * Trap Tab within a container. Applied to the overlay shell so close controls
 * outside the panel stay in the cycle.
 */
export function setupFocusTrap( container: HTMLElement ): () => void {
	const handleKeydown = ( event: KeyboardEvent ): void => {
		if ( event.key !== 'Tab' ) {
			return;
		}

		const focusable = Array.from(
			container.querySelectorAll< HTMLElement >( FOCUSABLE_SELECTOR )
		).filter(
			( el ) => ! el.closest( '[hidden]' ) && ! el.closest( '[inert]' )
		);

		if ( focusable.length === 0 ) {
			event.preventDefault();
			return;
		}

		const currentIndex = focusable.indexOf(
			document.activeElement as HTMLElement
		);
		let nextIndex: number;

		if ( event.shiftKey ) {
			nextIndex =
				currentIndex <= 0 ? focusable.length - 1 : currentIndex - 1;
		} else {
			nextIndex =
				currentIndex >= focusable.length - 1 ? 0 : currentIndex + 1;
		}

		event.preventDefault();
		focusable[ nextIndex ]?.focus();
	};

	container.addEventListener( 'keydown', handleKeydown );

	return () => {
		container.removeEventListener( 'keydown', handleKeydown );
	};
}

/**
 * Whether focus can safely return to an element after a dialog closes.
 */
export function canRestoreFocus(
	element: Element | null
): element is HTMLElement {
	return (
		element instanceof HTMLElement &&
		element.isConnected &&
		element !== document.body &&
		typeof element.focus === 'function'
	);
}
