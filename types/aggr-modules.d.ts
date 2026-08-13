/**
 * Type declarations for plugin script modules resolved via WordPress import maps.
 *
 * Registered with wp_register_script_module() and resolved at runtime by the
 * browser's import map — not by webpack's module resolution.
 */

declare module '@aggr/helpers' {
	export function setupFocusTrap( container: HTMLElement ): () => void;
	export function canRestoreFocus(
		element: Element | null
	): element is HTMLElement;
}

declare module '@aggr/scroll-lock' {
	export function lockScroll(): void;
	export function unlockScroll(): void;
}

declare module '@aggr/logic' {
	export const DISPLAY_STEPS: readonly [
		'details',
		'package',
		'creative',
		'destination',
		'review',
		'submit',
	];
	export type WizardStep = ( typeof DISPLAY_STEPS )[ number ];
	export type FileCheckCode =
		| 'type'
		| 'size'
		| 'pixels'
		| 'dimensions'
		| 'empty';
	export type FileCheck =
		| { ok: true }
		| { ok: false; code: FileCheckCode };
	export function isWizardStep( value: string ): value is WizardStep;
	export function stepIndex( step: string ): number;
	export function nextStep( current: string ): WizardStep | null;
	export function previousStep( current: string ): WizardStep | null;
	export function canVisitStep(
		target: string,
		submitReady: boolean
	): boolean;
	export function parsePixelSize(
		value: string
	): { width: number; height: number } | null;
	export function checkCreativeFile( input: {
		mime: string;
		bytes: number;
		width: number;
		height: number;
		expectedWidth: number;
		expectedHeight: number;
		maxBytes: number;
		maxPixels: number;
		allowedMime: readonly string[];
	} ): FileCheck;
	export function debounce< T extends ( ...args: never[] ) => void >(
		fn: T,
		ms: number
	): T & { cancel: () => void };
}

declare module '@aggr/dialog' {}
declare module '@aggr/wizard' {}
declare module '@aggr/autosave' {}
declare module '@aggr/upload' {}

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
