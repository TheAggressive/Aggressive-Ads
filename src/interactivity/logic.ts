/**
 * Decidable portal logic. Imports nothing from @wordpress/interactivity
 * so Jest can exercise it without a runtime mock.
 *
 * Limits and copy are hydrated from PHP. This file only decides.
 */

export const DISPLAY_STEPS = [
	'details',
	'package',
	'creative',
	'destination',
	'review',
	'submit',
] as const;

export type WizardStep = ( typeof DISPLAY_STEPS )[ number ];

export type FileCheckCode = 'type' | 'size' | 'pixels' | 'dimensions' | 'empty';

export type FileCheck = { ok: true } | { ok: false; code: FileCheckCode };

export function isWizardStep( value: string ): value is WizardStep {
	return ( DISPLAY_STEPS as readonly string[] ).includes( value );
}

export function stepIndex( step: string ): number {
	return DISPLAY_STEPS.indexOf( step as WizardStep );
}

export function nextStep( current: string ): WizardStep | null {
	const index = stepIndex( current );
	if ( index < 0 || index >= DISPLAY_STEPS.length - 1 ) {
		return null;
	}
	return DISPLAY_STEPS[ index + 1 ] ?? null;
}

export function previousStep( current: string ): WizardStep | null {
	const index = stepIndex( current );
	if ( index <= 0 ) {
		return null;
	}
	return DISPLAY_STEPS[ index - 1 ] ?? null;
}

/**
 * Submit is gated. Every earlier step is reachable so an advertiser can
 * go back; the server still refuses to persist an illegal resume point.
 */
export function canVisitStep( target: string, submitReady: boolean ): boolean {
	if ( ! isWizardStep( target ) ) {
		return false;
	}
	if ( target === 'submit' ) {
		return submitReady;
	}
	return true;
}

export function parsePixelSize(
	value: string
): { width: number; height: number } | null {
	const match = /^(\d+)\s*[x×]\s*(\d+)$/i.exec( value.trim() );
	if ( ! match ) {
		return null;
	}
	const width = Number( match[ 1 ] );
	const height = Number( match[ 2 ] );
	if ( ! Number.isInteger( width ) || ! Number.isInteger( height ) ) {
		return null;
	}
	if ( width < 1 || height < 1 ) {
		return null;
	}
	return { width, height };
}

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
} ): FileCheck {
	if ( input.bytes <= 0 ) {
		return { ok: false, code: 'empty' };
	}

	const mime = input.mime.toLowerCase().trim();
	if ( ! input.allowedMime.includes( mime ) ) {
		return { ok: false, code: 'type' };
	}

	if ( input.bytes > input.maxBytes ) {
		return { ok: false, code: 'size' };
	}

	if (
		input.width < 1 ||
		input.height < 1 ||
		input.width * input.height > input.maxPixels
	) {
		return { ok: false, code: 'pixels' };
	}

	if (
		input.width !== input.expectedWidth ||
		input.height !== input.expectedHeight
	) {
		return { ok: false, code: 'dimensions' };
	}

	return { ok: true };
}

export function debounce< T extends ( ...args: never[] ) => void >(
	fn: T,
	ms: number
): T & { cancel: () => void } {
	let timer: ReturnType< typeof setTimeout > | null = null;

	const wrapped = ( ( ...args: never[] ) => {
		if ( timer !== null ) {
			clearTimeout( timer );
		}
		timer = setTimeout( () => {
			timer = null;
			fn( ...args );
		}, ms );
	} ) as T & { cancel: () => void };

	wrapped.cancel = () => {
		if ( timer !== null ) {
			clearTimeout( timer );
			timer = null;
		}
	};

	return wrapped;
}
