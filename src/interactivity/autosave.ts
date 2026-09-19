/**
 * Debounced REST autosave for wizard forms.
 *
 * Namespace: aggr/autosave
 * State is keyed per instance at state.autosaves[ autosaveId ].
 *
 * Ordinary POST still saves and advances. This store PATCHes the public
 * allowlist as the advertiser types so a refresh does not lose the draft, and
 * makes the page heading the campaign's rename control. File uploads and
 * submit are not autosaved.
 */

import { store, getContext } from '@wordpress/interactivity';
import { debounce, normaliseLink } from '@aggr/logic';
import { enableRename } from './shared/rename-heading';

type SaveStatus = 'idle' | 'saving' | 'saved' | 'error' | 'conflict';

type Outcome = 'saved' | 'error' | 'conflict';

type Copy =
	| SaveStatus
	| 'rename'
	| 'nameLabel'
	| 'nameSaved'
	| 'nameEmpty'
	| 'nameError'
	| 'linkSaved'
	| 'linkInvalid';

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

/*
 * The refusal code of each instance's last failed save. The link field says
 * why it was refused, and a bare 'error' outcome cannot tell a bad link from a
 * dropped connection.
 */
const lastErrorCode = new Map< string, string >();
const pending = new Map<
	string,
	ReturnType< typeof debounce< () => void > >
>();

function announcerFor( id: string ): HTMLElement | null {
	return document.getElementById( `aggr-autosave-status-${ id }` );
}

function announce( id: string, message: string ): void {
	const announcer = announcerFor( id );
	if ( announcer ) {
		announcer.textContent = message;
	}
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
	 * Only once the browser accepts it as a URL. This saves as it is typed,
	 * and `htt` on its way to `https://` would otherwise come back refused
	 * mid-word; an address still being typed is left unsaved, not rejected.
	 */
	const link = form.querySelector< HTMLInputElement >(
		'input[name="default_click_url"]'
	);
	const normalised = link ? normaliseLink( link.value ) : null;
	if ( null !== normalised ) {
		fields.default_click_url = normalised;
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

function syncRevision( revision: number ): void {
	document
		.querySelectorAll< HTMLInputElement >( 'input[name="autosave_rev"]' )
		.forEach( ( input ) => {
			input.value = String( revision );
		} );
}

/**
 * One campaign's autosave writes, one after another.
 *
 * The heading's rename and the form's debounce share one revision. Two PATCHes
 * sent together carry the same revision, the server accepts the first and
 * refuses the second as a conflict — so each waits for the one before it and
 * sends the revision that one left behind.
 */
const queues = new Map< string, Promise< unknown > >();

function serialise< T >( id: string, task: () => Promise< T > ): Promise< T > {
	const next = ( queues.get( id ) ?? Promise.resolve() ).then( task, task );

	queues.set(
		id,
		next.catch( () => undefined )
	);

	return next;
}

/**
 * PATCHes fields and adopts the revision the server answers with.
 *
 * @param id     Autosave instance.
 * @param fields Allowlisted campaign fields.
 * @return What happened, for the caller to report.
 */
async function send(
	id: string,
	fields: Record< string, unknown >
): Promise< Outcome > {
	const current = state.autosaves[ id ];
	if ( ! current || current.restUrl === '' || current.nonce === '' ) {
		return 'error';
	}

	try {
		const response = await fetch( current.restUrl, {
			method: 'PATCH',
			credentials: 'same-origin',

			/*
			 * Finishes even if the page navigates. Leaving a field and pressing
			 * Continue is one gesture, and without this the save it started
			 * was dropped by the navigation it was racing.
			 */
			keepalive: true,
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
			return 'conflict';
		}

		if ( ! response.ok ) {
			const refusal: unknown = await response.json().catch( () => null );
			lastErrorCode.set(
				id,
				refusal &&
					typeof refusal === 'object' &&
					'code' in refusal &&
					typeof refusal.code === 'string'
					? refusal.code
					: ''
			);

			return 'error';
		}

		lastErrorCode.delete( id );
		const payload: unknown = await response.json();
		const revision =
			payload &&
			typeof payload === 'object' &&
			'autosave_rev' in payload &&
			typeof payload.autosave_rev === 'number'
				? payload.autosave_rev
				: null;

		if ( revision === null ) {
			return 'error';
		}

		current.revision = revision;
		syncRevision( revision );

		return 'saved';
	} catch {
		return 'error';
	}
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
	const outcome = await send( id, fields );
	setStatus( id, outcome );

	if ( 'default_click_url' in fields ) {
		showLinkStatus(
			form,
			'saved' === outcome ? 'saved' : outcome,
			lastErrorCode.get( id ) ?? ''
		);
	}
}

/**
 * Says, beside the Destination field, whether its link was kept.
 *
 * Visible rather than announced only: the field used to go quiet when a save
 * failed, and a link that looks saved and is not sends every click nowhere.
 *
 * @param form    The Destination form.
 * @param outcome What the save did, or 'invalid' before anything was sent.
 * @param code    The server's refusal code, when there was one.
 */
function showLinkStatus(
	form: HTMLFormElement,
	outcome: Outcome | 'invalid',
	code = ''
): void {
	const link = form.querySelector< HTMLInputElement >(
		'input[name="default_click_url"]'
	);
	const status = form.querySelector< HTMLElement >(
		'[data-aggr-link-status]'
	);

	if ( ! link || ! status ) {
		return;
	}

	const invalid =
		'invalid' === outcome || 'aggr_default_click_url_invalid' === code;
	const copy = invalid
		? state.i18n.linkInvalid
		: 'saved' === outcome
		? state.i18n.linkSaved
		: state.i18n[ outcome ];

	status.textContent = typeof copy === 'string' ? copy : '';
	status.classList.toggle( 'is-error', 'saved' !== outcome );

	if ( invalid ) {
		link.setAttribute( 'aria-invalid', 'true' );
	} else {
		link.removeAttribute( 'aria-invalid' );
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
		i18n: {} as Partial< Record< Copy, string > >,
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

			const saveNow = () => {
				const started = serialise( autosaveId, () =>
					patch( autosaveId, root )
				).finally( () => {
					if ( inFlight === started ) {
						inFlight = null;
					}
				} );

				inFlight = started;
			};
			const run = debounce( saveNow, 600 );
			pending.set( autosaveId, run );

			/*
			 * **Cancelling on submit is not enough on its own.**
			 *
			 * Choosing a package used to advance the step by calling
			 * `requestSubmit()` from a listener on the radio, so `submit`
			 * fired — and `run.cancel()` with it — during the *target* phase
			 * of that same `change` event. The radio no longer submits, but
			 * any `change` that lands during a submit meets the same order. The event then carries on
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
			const link = root.querySelector< HTMLInputElement >(
				'input[name="default_click_url"]'
			);

			const onChange = ( event: Event ) => {
				// The link commits on its own below, straight away.
				if (
					submitting ||
					( 'change' === event.type && event.target === link )
				) {
					return;
				}

				run();
			};

			root.addEventListener( 'input', onChange );
			root.addEventListener( 'change', onChange );

			/*
			 * On commit, the link field shows the address it will save, says
			 * so when there is none, and saves at once rather than after the
			 * debounce: leaving the field is usually the move to the next
			 * thing, and six hundred milliseconds is long enough to lose it.
			 * Typing is left alone — rewriting `exa` to `https://exa` under
			 * someone's cursor would be worse.
			 */
			link?.addEventListener( 'change', () => {
				const normalised = normaliseLink( link.value );

				if ( null === normalised ) {
					run.cancel();
					showLinkStatus( root, 'invalid' );
					return;
				}

				link.value = normalised;
				run.cancel();
				saveNow();
			} );

			/*
			 * **Any other form carrying the revision waits too.** The ads
			 * step's Continue is its own form, so the wait below never saw it:
			 * a link saved on the way to that button bumped the revision the
			 * button had already read, and the step came back as a conflict.
			 */
			document.addEventListener( 'submit', ( event ) => {
				const other = event.target;

				if (
					! ( other instanceof HTMLFormElement ) ||
					other === root ||
					! other.querySelector( 'input[name="autosave_rev"]' ) ||
					null === inFlight
				) {
					return;
				}

				event.preventDefault();

				const submitter =
					event instanceof SubmitEvent ? event.submitter : null;

				void inFlight.finally( () => {
					other.requestSubmit(
						submitter instanceof HTMLElement ? submitter : undefined
					);
				} );
			} );

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
			 * first fixed and was not enough: the radio used to auto-advance
			 * by calling `requestSubmit()`, but the step also has an ordinary
			 * Continue button, and Enter in a field submits too. Every one
			 * arrives here, and only here is early enough to stop the
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

			/*
			 * Says the module is attached, for anything that has to wait for
			 * it. The button's label used to serve as this signal and no longer
			 * can: CSS now picks that before paint, so it says nothing about
			 * whether this code is running. A data attribute is the sanctioned
			 * behaviour hook here — see docs/architecture.md.
			 */
			root.dataset.aggrAutosaveReady = '1';
		},

		initTitle() {
			const { autosaveId } = getContext< AutosaveContext >();
			const heading = document.getElementById( 'aggr-campaign-title' );

			if (
				! autosaveId ||
				! ( heading instanceof HTMLElement ) ||
				'1' === heading.dataset.aggrTitleReady
			) {
				return;
			}

			// In the DOM, for the same reason the upload store marks its forms:
			// a module evaluated twice would otherwise wrap the heading twice.
			heading.dataset.aggrTitleReady = '1';
			enableRename( heading, {
				hint: state.i18n.rename ?? '',
				label: state.i18n.nameLabel ?? '',
				maxLength: 160,
				save: ( next ) =>
					serialise( autosaveId, () =>
						send( autosaveId, { title: next } )
					),
				said: ( outcome ) => {
					const copy = {
						saved: state.i18n.nameSaved,
						empty: state.i18n.nameEmpty,
						conflict: state.i18n.conflict,
						error: state.i18n.nameError,
					}[ outcome ];

					announce( autosaveId, copy ?? '' );
				},
			} );
		},
	},
} );

export { state };
