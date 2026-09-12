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
 * ordinary POST persists the name and the package together and there is no race
 * with the debounce.
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

			const run = debounce( () => {
				void patch( autosaveId, root );
			}, 600 );
			pending.set( autosaveId, run );

			const onChange = () => {
				run();
			};

			root.addEventListener( 'input', onChange );
			root.addEventListener( 'change', onChange );
			root.addEventListener( 'submit', () => {
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
