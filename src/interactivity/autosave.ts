/**
 * Debounced REST autosave for wizard forms.
 *
 * Namespace: aggr/autosave
 * State is keyed per instance at state.autosaves[ autosaveId ].
 *
 * Ordinary POST still saves and advances. This store PATCHes the public
 * allowlist as the advertiser types so a refresh does not lose the draft.
 * File uploads and submit are not autosaved.
 */

import { store, getContext } from '@wordpress/interactivity';
import { debounce } from '@aggr/logic';

type SaveStatus = 'idle' | 'saving' | 'saved' | 'error' | 'conflict';

interface AutosaveState {
	restUrl: string;
	nonce: string;
	revision: number;
	status: SaveStatus;
}

interface AutosaveContext {
	autosaveId?: string;
}

const initializedIds = new Set< string >();
const pending = new Map<
	string,
	ReturnType< typeof debounce< () => void > >
>();

function announcerFor( id: string ): HTMLElement | null {
	return document.getElementById( `aggr-autosave-status-${ id }` );
}

function setStatus( id: string, status: SaveStatus ): void {
	const current = state.autosaves[ id ];
	if ( ! current ) {
		return;
	}
	current.status = status;

	const announcer = announcerFor( id );
	if ( ! announcer ) {
		return;
	}

	const copy = state.i18n[ status ];
	announcer.textContent = typeof copy === 'string' ? copy : '';
}

function fieldsFrom( form: HTMLFormElement ): Record< string, unknown > {
	const data = new FormData( form );
	const fields: Record< string, unknown > = {};

	const title = data.get( 'title' );
	if ( typeof title === 'string' ) {
		fields.title = title;
	}

	const notes = data.get( 'advertiser_notes' );
	if ( typeof notes === 'string' ) {
		fields.advertiser_notes = notes;
	}

	/*
	 * Sent as the local date string the input holds, never as a timestamp.
	 * Converting here would use the visitor's timezone, and the campaign runs
	 * in the site's; the server does the conversion for both save paths.
	 */
	for ( const key of [ 'start_date', 'end_date' ] ) {
		const value = data.get( key );
		if ( typeof value === 'string' ) {
			fields[ key ] = value;
		}
	}

	const packageId = data.get( 'package_id' );
	if ( typeof packageId === 'string' && packageId !== '' ) {
		fields.package_id = Number( packageId );
	}

	const placements = data
		.getAll( 'placement_ids[]' )
		.filter( ( value ): value is string => typeof value === 'string' )
		.map( ( value ) => Number( value ) )
		.filter( ( value ) => Number.isInteger( value ) && value > 0 );

	const placementInputs = form.querySelectorAll(
		'input[name="placement_ids[]"]'
	);
	if ( placementInputs.length > 0 ) {
		fields.placement_ids = placements;
	}

	return fields;
}

/**
 * Choosing a package finishes the first step.
 *
 * One control, one unambiguous choice — the only step where "done" is a single
 * event rather than a guess. It submits the form rather than navigating, so the
 * ordinary POST persists the name and the package together.
 *
 * **There is no race with the debounce, and that is not free.** This comment
 * claimed it while the race was live: a save already on the wire left the form
 * holding a stale `autosave_rev`, and the POST was refused. What makes the
 * claim true is the submit listener in `init()`, which holds every submit —
 * this one, the Continue button, and Enter in a text field — until any save in
 * flight has come back.
 *
 * Not while the campaign is still called what the wizard named it. Carrying an
 * unnamed draft forward only earns a title error at review and a trip back to
 * this field, which is a worse journey than the click it saved. The flag is the
 * server's, because comparing the title against the placeholder string breaks
 * as soon as the site language changes.
 */
function advanceOnPackage( form: HTMLFormElement ): void {
	const radios = form.querySelectorAll< HTMLInputElement >(
		'input[type="radio"][name="package_id"]'
	);

	if ( 0 === radios.length ) {
		return;
	}

	const title = form.querySelector< HTMLInputElement >(
		'input[name="title"]'
	);
	const placeholder =
		'1' === form.getAttribute( 'data-aggr-title-placeholder' );
	let advancing = false;

	/*
	 * Asked at the moment of choosing, not tracked as it is typed.
	 *
	 * Listening for `input` on the name looked equivalent and was not: this
	 * module attaches on hydration, and a name filled before that — a paste, a
	 * password manager, a fast typist, a browser test — raises its event with
	 * nobody listening, leaving the step convinced the campaign is unnamed.
	 * `defaultValue` is the value the server rendered, so comparing against it
	 * reads the same answer no matter when the edit happened.
	 */
	const named = (): boolean =>
		null !== title &&
		'' !== title.value.trim() &&
		( ! placeholder || title.value !== title.defaultValue );

	radios.forEach( ( radio ) => {
		radio.addEventListener( 'change', () => {
			if ( advancing || ! named() || ! form.checkValidity() ) {
				return;
			}

			advancing = true;
			form.requestSubmit();
		} );
	} );
}

function syncRevision( revision: number ): void {
	document
		.querySelectorAll< HTMLInputElement >( 'input[name="autosave_rev"]' )
		.forEach( ( input ) => {
			input.value = String( revision );
		} );
}

async function patch( id: string, form: HTMLFormElement ): Promise< void > {
	const current = state.autosaves[ id ];
	if ( ! current || current.restUrl === '' || current.nonce === '' ) {
		return;
	}

	const fields = fieldsFrom( form );
	if ( Object.keys( fields ).length === 0 ) {
		return;
	}

	setStatus( id, 'saving' );

	try {
		const response = await fetch( current.restUrl, {
			method: 'PATCH',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': current.nonce,
			},
			body: JSON.stringify( {
				...fields,
				autosave_rev: current.revision,
			} ),
		} );

		if ( response.status === 409 ) {
			setStatus( id, 'conflict' );
			return;
		}

		if ( ! response.ok ) {
			setStatus( id, 'error' );
			return;
		}

		const payload: unknown = await response.json();
		const revision =
			payload &&
			typeof payload === 'object' &&
			'autosave_rev' in payload &&
			typeof payload.autosave_rev === 'number'
				? payload.autosave_rev
				: null;

		if ( revision === null ) {
			setStatus( id, 'error' );
			return;
		}

		current.revision = revision;
		syncRevision( revision );
		setStatus( id, 'saved' );
	} catch {
		setStatus( id, 'error' );
	}
}

const { state } = store( 'aggr/autosave', {
	state: {
		autosaves: {} as Record< string, AutosaveState >,
		/*
		 * Left empty on purpose, and it must stay that way.
		 *
		 * The server hydrates these through wp_interactivity_state(), and the
		 * Interactivity API merges *this* object over that one — so spelling out
		 * placeholder defaults here overwrites every translated string with an
		 * empty one. The live region then renders, passes axe, and announces
		 * nothing at all. Errors and conflicts are the messages that matters
		 * most, and they were the ones being silently dropped.
		 */
		i18n: {} as Partial< Record< SaveStatus, string > >,
	},
	actions: {
		init() {
			const { autosaveId } = getContext< AutosaveContext >();
			if ( ! autosaveId || initializedIds.has( autosaveId ) ) {
				return;
			}
			initializedIds.add( autosaveId );

			const root = document.querySelector(
				`[data-aggr-autosave="${ CSS.escape( autosaveId ) }"]`
			);
			if ( ! ( root instanceof HTMLFormElement ) ) {
				return;
			}

			/*
			 * The save currently on the wire, so anything that has to know the
			 * campaign's real revision can wait for it. A cancelled debounce
			 * says nothing about a request that has already left.
			 */
			let submitting = false;
			let inFlight: Promise< void > | null = null;

			const run = debounce( () => {
				const started = patch( autosaveId, root ).finally( () => {
					if ( inFlight === started ) {
						inFlight = null;
					}
				} );

				inFlight = started;
			}, 600 );
			pending.set( autosaveId, run );

			/*
			 * **Cancelling on submit is not enough on its own.**
			 *
			 * Choosing a package advances the step by calling
			 * `requestSubmit()` from a listener on the radio, so `submit`
			 * fires — and `run.cancel()` with it — during the *target* phase
			 * of that same `change` event. The event then carries on
			 * bubbling to the form, where the handler below re-arms the very
			 * debounce that was just cancelled. Six hundred milliseconds
			 * later the page is navigating and the save goes out into a
			 * document being torn down.
			 *
			 * Chromium drops that request silently. WebKit reports it as
			 * `Fetch API cannot load … due to access control checks`, which
			 * reads as a CORS fault and is not one: the origins match and the
			 * same request from the same page succeeds. Nothing reaches the
			 * wire either way, so the last edit before choosing a package was
			 * never saved by this path — it survived only because the form
			 * post that navigates carries the same fields.
			 *
			 * A latch rather than a second cancel, because the ordering is
			 * the problem: anything that re-arms after the cancel has to be
			 * refused, not undone.
			 */
			const onChange = () => {
				if ( submitting ) {
					return;
				}

				run();
			};

			root.addEventListener( 'input', onChange );
			root.addEventListener( 'change', onChange );

			/*
			 * **Every submit waits for a save already on the wire.**
			 *
			 * Cancelling covers a save that has not left; it does nothing
			 * about one that has. Type the name, pause the six hundred
			 * milliseconds it takes to read the package list, and the PATCH
			 * is in flight when the click lands — the browser serialises the
			 * old `autosave_rev`, the PATCH bumps the stored one first, and
			 * the POST arrives a revision behind. `Campaign_Editor::save()`
			 * refuses it, and the advertiser is returned to step one with
			 * their package unset, told the campaign changed in another
			 * window they never opened.
			 *
			 * Here rather than on the package radio, which is where this was
			 * first fixed and was not enough: the radio auto-advances by
			 * calling `requestSubmit()`, but the step also has an ordinary
			 * Continue button, and Enter in a text field submits too. All
			 * three arrive here, and only here is early enough to stop the
			 * browser reading the inputs.
			 *
			 * The re-submit cannot loop: `inFlight` is cleared by the
			 * handler registered when the save started, which runs before
			 * this one, so the second pass finds nothing to wait for.
			 */
			root.addEventListener( 'submit', ( event ) => {
				submitting = true;
				run.cancel();

				const waiting = inFlight;

				if ( null === waiting ) {
					return;
				}

				event.preventDefault();

				// The button that was pressed carries its own name and value
				// on some steps, so re-submitting without it changes the post.
				const submitter =
					event instanceof SubmitEvent ? event.submitter : null;

				void waiting.finally( () => {
					root.requestSubmit(
						submitter instanceof HTMLElement ? submitter : undefined
					);
				} );
			} );

			/*
			 * A navigation this form did not start — a rail link, the back
			 * button — has the same effect on an in-flight debounce, and
			 * `pagehide` is the event that fires for all of them in WebKit,
			 * where `unload` is unreliable and a page may be held in the
			 * back/forward cache instead of destroyed.
			 */
			window.addEventListener( 'pagehide', () => {
				run.cancel();
			} );

			advanceOnPackage( root );

			/*
			 * Says the module is attached, for anything that has to wait for
			 * it. The button's label used to serve as this signal and no longer
			 * can: CSS now picks that before paint, so it says nothing about
			 * whether this code is running. A data attribute is the sanctioned
			 * behaviour hook here — see docs/architecture.md.
			 */
			root.dataset.aggrAutosaveReady = '1';
		},
	},
} );

export { state };
