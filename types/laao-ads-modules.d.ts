/**
 * Type declarations for plugin script modules resolved via WordPress import maps.
 *
 * Registered with wp_register_script_module() and resolved at runtime by the
 * browser's import map — not by webpack's module resolution.
 */

declare module '@laao-ads/helpers' {
	export function setupFocusTrap( container: HTMLElement ): () => void;
	export function canRestoreFocus(
		element: Element | null
	): element is HTMLElement;
}

declare module '@laao-ads/scroll-lock' {
	export function lockScroll(): void;
	export function unlockScroll(): void;
}

declare module '@laao-ads/dialog' {}

declare module '@wordpress/interactivity' {
	export function getContext< T = Record< string, unknown > >(): T;

	export function store<
		TState = Record< string, unknown >,
		TActions = Record< string, unknown >,
	>(
		namespace: string,
		storePart: {
			state?: TState;
			actions?: TActions;
			callbacks?: Record< string, unknown >;
		},
		options?: { lock?: boolean | string }
	): { state: TState; actions: TActions };
}
