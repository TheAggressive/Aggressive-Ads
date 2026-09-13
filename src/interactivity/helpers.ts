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

/**
 * Where this form posts.
 *
 * **Never `form.action`.** Named controls shadow properties on the form
 * element, and every one of these forms carries WordPress's required
 * `<input name="action">` — so `form.action` returns that input, `fetch()`
 * stringifies it to `[object HTMLInputElement]`, and the write goes to a
 * nonsense relative URL. The 404 that comes back is not JSON, the catch
 * treats it as a network failure, and what the reader sees is the page
 * reloading exactly as if none of this existed. It took a browser to find.
 *
 * @param form The form being saved.
 * @return The endpoint from the attribute, which nothing can shadow.
 */
export function endpointOf( form: HTMLFormElement ): string {
	return form.getAttribute( 'action' ) ?? window.location.href;
}
