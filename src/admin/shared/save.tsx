/**
 * Saving, and saying so, for the React admin screens.
 *
 * Two screens now write through REST, and they need to agree about what a
 * failure looks like. A save that fails silently on a screen of kill-switches
 * and catalogue prices is worse than one that fails loudly, and "worse" here is
 * measurable: this plugin has already shipped one silent failure — a test lane
 * that exited zero while skipping ten classes — and it survived for as long as
 * it did precisely because nothing reported it.
 *
 * So error state is not optional here. Every hook in this file ends in one of
 * exactly three visible outcomes: saving, saved, or a message naming what the
 * server refused and a way to try again.
 *
 * Strings arrive from PHP. `wp i18n make-pot` does not parse .tsx, so an __()
 * call here would compile, run, and produce no catalog entry.
 */

import type { ReactElement } from 'react';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import {
	Button,
	Notice,
	Spinner,
	__experimentalHStack as HStack,
	__experimentalVStack as VStack,
} from '@wordpress/components';

export type Status = 'idle' | 'pending' | 'saving' | 'saved' | 'error';

/** The catalog, hydrated once from the bootstrap payload. */
let strings: Record< string, string > = {};

export function setStrings( next: Record< string, string > ): void {
	strings = next ?? {};
}

/** A missing key renders empty rather than throwing. */
export function t( key: string ): string {
	return strings[ key ] ?? '';
}

/**
 * Whatever the server said went wrong, or a generic fallback.
 *
 * WordPress REST errors arrive as `{ code, message, data }` and their messages
 * are already translated and already user-facing. Preferring the server's own
 * words keeps one wording per failure instead of a second set maintained here
 * that drifts from the rules that actually rejected the request.
 */
export function errorMessage( reason: unknown ): string {
	if (
		reason &&
		typeof reason === 'object' &&
		'message' in reason &&
		typeof reason.message === 'string' &&
		'' !== reason.message
	) {
		return reason.message;
	}

	return t( 'saveFailed' );
}

/**
 * Save state, announced as well as drawn.
 *
 * An autosaved screen has no button to watch, so this line is the only evidence
 * a change was stored. Drawing it without announcing it would leave a screen
 * reader user with no evidence at all.
 */
export function SaveStatus( { status }: { status: Status } ): ReactElement {
	const label: Record< Status, string > = {
		idle: '',
		pending: t( 'statusPending' ),
		saving: t( 'statusSaving' ),
		saved: t( 'statusSaved' ),
		error: t( 'statusError' ),
	};

	return (
		<HStack justify="flex-start" spacing={ 2 } aria-live="polite">
			{ 'saving' === status ? <Spinner /> : <></> }
			<span>{ label[ status ] }</span>
		</HStack>
	);
}

/**
 * A failure the reader can act on: what happened, and a way to retry.
 *
 * `isDismissible` is off on purpose. A dismissible error on a screen that keeps
 * saving in the background can be waved away while the underlying value is
 * still unsaved, which is exactly the state nobody should be able to hide.
 */
export function SaveError( {
	message,
	onRetry,
}: {
	message: string;
	onRetry?: () => void;
} ): ReactElement | null {
	if ( '' === message ) {
		return null;
	}

	return (
		<Notice status="error" isDismissible={ false }>
			<VStack spacing={ 2 }>
				<span>{ message }</span>
				{ onRetry ? (
					<HStack justify="flex-start">
						<Button variant="secondary" onClick={ onRetry }>
							{ t( 'retry' ) }
						</Button>
					</HStack>
				) : (
					<></>
				) }
			</VStack>
		</Notice>
	);
}

/**
 * A one-shot write: fires when asked, reports what happened.
 *
 * For deliberate actions — create, delete, grant — rather than for editing.
 * `run` resolves with the server's response so the caller can adopt whatever
 * fresh state came back instead of guessing at what the write did.
 */
export function useAction< T >(): {
	status: Status;
	error: string;
	busy: boolean;
	run: ( request: () => Promise< T > ) => Promise< T | null >;
	clearError: () => void;
} {
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ error, setError ] = useState( '' );

	const run = useCallback(
		async ( request: () => Promise< T > ): Promise< T | null > => {
			setStatus( 'saving' );
			setError( '' );

			try {
				const result = await request();
				setStatus( 'saved' );

				return result;
			} catch ( reason ) {
				setError( errorMessage( reason ) );
				setStatus( 'error' );

				return null;
			}
		},
		[]
	);

	return {
		status,
		error,
		busy: 'saving' === status,
		run,
		clearError: () => {
			setError( '' );
			setStatus( 'idle' );
		},
	};
}

/**
 * Debounced autosave for a document that is edited rather than submitted.
 *
 * Three properties are load-bearing and none are obvious:
 *
 * The newest change wins. Every send takes a ticket, and a response whose
 * ticket is no longer current is dropped — otherwise a slow first request
 * finishing after a fast second one reports the older outcome, which on a
 * failure means telling somebody their saved change was rejected.
 *
 * The whole document goes every time. Partial updates need a merge, and a merge
 * needs to know which absent key means "unchanged" and which means "off";
 * getting that wrong on a screen of kill-switches turns a debounced keystroke
 * into a disabled module.
 *
 * Leaving with a change still in the timer would lose it, so the browser asks.
 */
export function useAutosave< T >( send: ( value: T ) => Promise< unknown > ): {
	status: Status;
	error: string;
	schedule: ( value: T, delay: number ) => void;
	retry: () => void;
} {
	const [ status, setStatus ] = useState< Status >( 'idle' );
	const [ error, setError ] = useState( '' );
	const timer = useRef< ReturnType< typeof setTimeout > | undefined >(
		undefined
	);
	const ticket = useRef( 0 );
	const pending = useRef< T | null >( null );
	const sender = useRef( send );

	sender.current = send;

	const fire = useCallback( () => {
		const value = pending.current;

		if ( null === value ) {
			return;
		}

		const mine = ticket.current + 1;
		ticket.current = mine;
		setStatus( 'saving' );

		sender
			.current( value )
			.then( () => {
				if ( mine !== ticket.current ) {
					return;
				}

				setError( '' );
				setStatus( 'saved' );
			} )
			.catch( ( reason: unknown ) => {
				if ( mine !== ticket.current ) {
					return;
				}

				setError( errorMessage( reason ) );
				setStatus( 'error' );
			} );
	}, [] );

	const schedule = useCallback(
		( value: T, delay: number ) => {
			pending.current = value;
			setStatus( 'pending' );
			clearTimeout( timer.current );
			timer.current = setTimeout( fire, delay );
		},
		[ fire ]
	);

	useEffect( () => {
		if ( 'pending' !== status && 'saving' !== status ) {
			return;
		}

		const warn = ( event: BeforeUnloadEvent ): void => {
			event.preventDefault();
		};

		window.addEventListener( 'beforeunload', warn );

		return () => window.removeEventListener( 'beforeunload', warn );
	}, [ status ] );

	useEffect( () => () => clearTimeout( timer.current ), [] );

	return { status, error, schedule, retry: fire };
}

/*
 * How long a change waits before it is sent.
 *
 * A switch is a finished decision the moment it is flipped, so it goes at once
 * and the screen feels like it responds. Text and colour are not: every
 * keystroke of a hex value and every pixel of a colour drag is an intermediate
 * state, most of which fail validation. Sending those would turn a validation
 * gate into a flicker of errors about values nobody chose.
 */
export const AT_ONCE = 0;
export const AFTER_TYPING = 1000;
export const AFTER_DRAGGING = 1200;
