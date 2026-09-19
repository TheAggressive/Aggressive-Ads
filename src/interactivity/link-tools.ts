/**
 * The destination link's two helpers: does it work, and tracking tags.
 *
 * Module: @aggr/link-tools
 *
 * Both sit beside the link field and neither is required to use it. The check
 * asks the server about the link already saved on the campaign — it sends no
 * URL, because the route accepts none. On a running campaign the link is
 * staged in the edit proposal first, through the same form handler a press of
 * "Save and continue" reaches, and the check reads that. The tag builder runs entirely here and
 * never rewrites the field on its own: it shows what it would write and waits
 * to be told.
 */

export interface TrackingTags {
	source: string;
	medium: string;
	campaign: string;
	content: string;
	term: string;
}

const PARAMETERS: Array< [ keyof TrackingTags, string ] > = [
	[ 'source', 'utm_source' ],
	[ 'medium', 'utm_medium' ],
	[ 'campaign', 'utm_campaign' ],
	[ 'content', 'utm_content' ],
	[ 'term', 'utm_term' ],
];

/**
 * A tag value as analytics tools want it.
 *
 * Lowercase, spaces to hyphens, punctuation dropped. Reporting tools treat
 * "Autumn Sale", "autumn sale" and "autumn-sale" as three different sources,
 * which splits one campaign across three rows in the advertiser's report. The
 * field shows what will be written, so the tidying is visible rather than a
 * surprise at the other end.
 *
 * @param value As typed.
 * @return The value as it will appear on the link.
 */
export function tidyTag( value: string ): string {
	/*
	 * Macros are left exactly as written. `{creative_id}` is a name the click
	 * hop matches, and tidying it to `{creative-id}` stopped it being filled
	 * in — the link then carried a literal brace to the advertiser's
	 * analytics, which is worse than the untidy value this avoids.
	 */
	return value
		.trim()
		.split( /(\{[a-z_]{1,32}\})/i )
		.map( ( part, index ) =>
			1 === index % 2
				? part.toLowerCase()
				: part
						.toLowerCase()
						.replace( /[\s_]+/g, '-' )
						.replace( /[^a-z0-9.\-|]+/g, '' )
						.replace( /-{2,}/g, '-' )
		)
		.join( '' )
		.replace( /^-+|-+$/g, '' );
}

/**
 * The link with tracking tags on it.
 *
 * **What is already there wins.** A link that carries `utm_source` was written
 * that way on purpose, often by the advertiser's own agency, and replacing it
 * would silently change where their reporting attributes the traffic. Empty
 * fields add nothing, and the fragment stays at the end where a browser needs
 * it.
 *
 * @param url  The link as typed. Returned unchanged when it is not a URL.
 * @param tags Values for the five standard parameters.
 * @return The link with any missing tags appended.
 */
export function withTrackingTags( url: string, tags: TrackingTags ): string {
	const trimmed = url.trim();

	if ( '' === trimmed ) {
		return url;
	}

	let parsed: URL;

	try {
		parsed = new URL( trimmed );
	} catch {
		// Not a URL at all — "example.com" on its own, or a sentence. Handed
		// back untouched, because guessing a scheme would change the address.
		return url;
	}

	PARAMETERS.forEach( ( [ field, parameter ] ) => {
		const value = tidyTag( tags[ field ] );

		if ( '' !== value && ! parsed.searchParams.has( parameter ) ) {
			parsed.searchParams.set( parameter, value );
		}
	} );

	return parsed.toString();
}

/**
 * Which tags a link already carries, so the form can say so.
 *
 * @param url The link as typed.
 * @return The parameter names present, in standard order.
 */
export function existingTags( url: string ): string[] {
	try {
		const parsed = new URL( url.trim() );

		return PARAMETERS.map( ( [ , parameter ] ) => parameter ).filter(
			( parameter ) => parsed.searchParams.has( parameter )
		);
	} catch {
		return [];
	}
}

type Fetcher = ( url: string, init: RequestInit ) => Promise< Response >;

interface CheckResult {
	outcome: string;
	status: number;
}

interface StageResult {
	ok?: boolean;
	notice?: string;
}

/**
 * Stages the edit form's link so the check has something stored to read.
 *
 * The form's own handler, nonce and rules, asked for JSON instead of a
 * redirect — the marker every scripted portal save uses — so a link the rules
 * refuse is refused here in the words the page would have used.
 *
 * @param form    The Destination card's form.
 * @param fetcher Network access.
 * @return Whether it was staged, and the refusal when not.
 */
export async function stageLink(
	form: HTMLFormElement,
	fetcher: Fetcher
): Promise< { ok: boolean; notice: string } > {
	const body = new FormData( form );

	body.set( 'aggr_async', '1' );

	try {
		// The attribute, not `form.action`: an `<input name="action">` shadows the property.
		const response = await fetcher( form.getAttribute( 'action' ) ?? '', {
			method: 'POST',
			credentials: 'same-origin',
			headers: { Accept: 'application/json' },
			body,
		} );
		const result = ( await response.json() ) as StageResult;

		return { ok: true === result.ok, notice: result.notice ?? '' };
	} catch {
		return { ok: false, notice: '' };
	}
}

/**
 * Wires the "Check link" button. Returns a handle for tests, or null when the
 * markup it needs is absent.
 *
 * @param root    The element carrying `data-aggr-link-check`.
 * @param fetcher Network access; `fetch` in the browser.
 * @param settle  Milliseconds to wait after a change before checking.
 */
export function initLinkCheck(
	root: HTMLElement,
	fetcher: Fetcher = ( url, init ) => window.fetch( url, init ),
	settle = 1500
): { check: () => Promise< void > } | null {
	const button = root.querySelector< HTMLButtonElement >(
		'[data-aggr-link-check-button]'
	);
	const chip = root.querySelector< HTMLElement >( '[data-aggr-link-chip]' );
	const endpoint = root.dataset.aggrLinkCheck ?? '';
	const nonce = root.dataset.aggrNonce ?? '';

	if (
		! button ||
		! chip ||
		'' === endpoint ||
		'1' === root.dataset.aggrLinkCheckReady
	) {
		return null;
	}

	root.dataset.aggrLinkCheckReady = '1';

	const labels: Record< string, string > = {
		works: root.dataset.aggrLabelWorks ?? '',
		missing: root.dataset.aggrLabelMissing ?? '',
		private: root.dataset.aggrLabelPrivate ?? '',
		broken: root.dataset.aggrLabelBroken ?? '',
		unreachable: root.dataset.aggrLabelUnreachable ?? '',
		checking: root.dataset.aggrLabelChecking ?? '',
		failed: root.dataset.aggrLabelFailed ?? '',
	};
	const tones: Record< string, string > = {
		works: 'aggr-pill--live',
		missing: 'aggr-pill--danger',
		broken: 'aggr-pill--danger',
		private: 'aggr-pill--pending',
		unreachable: 'aggr-pill--pending',
		checking: 'aggr-pill--neutral',
		failed: 'aggr-pill--attention',
	};

	const show = ( outcome: string ): void => {
		chip.hidden = false;
		chip.textContent = labels[ outcome ] ?? '';
		chip.className = `aggr-pill ${
			tones[ outcome ] ?? 'aggr-pill--neutral'
		} aggr-ads-link__status`;
	};

	const status = root.querySelector< HTMLElement >(
		'[data-aggr-link-status]'
	);

	const check = async (): Promise< void > => {
		button.disabled = true;
		show( 'checking' );

		if ( status ) {
			status.textContent = '';
		}

		if (
			'1' === root.dataset.aggrStage &&
			root instanceof HTMLFormElement
		) {
			const staged = await stageLink( root, fetcher );

			if ( ! staged.ok ) {
				show( 'failed' );

				if ( status ) {
					status.textContent = staged.notice;
				}

				button.disabled = false;

				return;
			}
		}

		try {
			const response = await fetcher( endpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: {
					'X-WP-Nonce': nonce,
					Accept: 'application/json',
				},
			} );

			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}

			const result = ( await response.json() ) as CheckResult;

			show(
				undefined === labels[ result.outcome ]
					? 'failed'
					: result.outcome
			);
		} catch {
			// Including a refusal: the server will not say why in more detail.
			show( 'failed' );
		} finally {
			button.disabled = false;
		}
	};

	button.disabled = false;
	button.addEventListener( 'click', () => {
		void check();
	} );

	/*
	 * And without being asked. A link nobody checks is a link nobody knows is
	 * broken, so a saved change checks itself: the delay lets autosave store
	 * the link first — the route reads what is stored, not what is typed —
	 * and lets somebody finish pasting before anything is fetched. Where there
	 * is no autosave, `check` stages the link itself.
	 *
	 * If the field moved on while the request was in flight, the answer is
	 * about the old link, so it is thrown away and asked again. Once: two
	 * rounds is the correction, more would be a loop chasing every keystroke.
	 */
	const link = root.querySelector< HTMLInputElement >(
		'input[name="default_click_url"]'
	);

	if ( link ) {
		let timer: ReturnType< typeof setTimeout > | undefined;
		let checked = link.value;

		const later = (): void => {
			window.clearTimeout( timer );

			if ( '' === link.value.trim() || link.value === checked ) {
				return;
			}

			timer = setTimeout( () => {
				const asked = link.value;

				checked = asked;
				void check().then( () => {
					if ( link.value !== asked && '' !== link.value.trim() ) {
						checked = link.value;
						void check();
					}
				} );
			}, settle );
		};

		link.addEventListener( 'change', later );
	}

	return { check };
}

/**
 * Wires the tracking-tag fold.
 *
 * The preview updates as the fields are typed in; nothing reaches the link
 * field until Apply is pressed, and after that the fold closes so the link on
 * screen is the one that is saved.
 *
 * @param root The element carrying `data-aggr-tags`.
 */
export function initTrackingTags( root: HTMLElement ): void {
	const link = document.getElementById( root.dataset.aggrFor ?? '' );
	const preview = root.querySelector< HTMLElement >(
		'[data-aggr-tags-preview]'
	);
	const apply = root.querySelector< HTMLButtonElement >(
		'[data-aggr-tags-apply]'
	);
	const note = root.querySelector< HTMLElement >( '[data-aggr-tags-note]' );

	if (
		! ( link instanceof HTMLInputElement ) ||
		! preview ||
		! apply ||
		'1' === root.dataset.aggrTagsReady
	) {
		return;
	}

	root.dataset.aggrTagsReady = '1';

	const fields = new Map< keyof TrackingTags, HTMLInputElement >();

	PARAMETERS.forEach( ( [ field ] ) => {
		const input = root.querySelector< HTMLInputElement >(
			`[data-aggr-tag="${ field }"]`
		);

		if ( input ) {
			fields.set( field, input );
		}
	} );

	const read = (): TrackingTags => ( {
		source: fields.get( 'source' )?.value ?? '',
		medium: fields.get( 'medium' )?.value ?? '',
		campaign: fields.get( 'campaign' )?.value ?? '',
		content: fields.get( 'content' )?.value ?? '',
		term: fields.get( 'term' )?.value ?? '',
	} );

	/*
	 * The words a team uses for source and medium are the same on every
	 * campaign they book, so the next one starts from what they chose here.
	 * The campaign's own tag is never remembered — it belongs to one campaign.
	 * Storage may be unavailable or full; a forgotten preference is not worth
	 * a broken fold.
	 */
	const REMEMBERED = 'aggr-tracking-tags';

	const recall = (): void => {
		try {
			const saved = JSON.parse(
				window.localStorage.getItem( REMEMBERED ) ?? '{}'
			) as Partial< TrackingTags >;

			( [ 'source', 'medium' ] as const ).forEach( ( field ) => {
				const input = fields.get( field );
				const value = saved[ field ];

				if ( input && 'string' === typeof value && '' !== value ) {
					input.value = value;
				}
			} );
		} catch {
			// The suggestions the server filled in stand.
		}
	};

	const remember = (): void => {
		try {
			window.localStorage.setItem(
				REMEMBERED,
				JSON.stringify( {
					source: tidyTag( fields.get( 'source' )?.value ?? '' ),
					medium: tidyTag( fields.get( 'medium' )?.value ?? '' ),
				} )
			);
		} catch {
			// Nothing to do: it is a convenience, not state anything depends on.
		}
	};

	const draw = (): void => {
		const next = withTrackingTags( link.value, read() );
		const already = existingTags( link.value );

		/*
		 * The link, then the part being added, marked. A second copy of an
		 * address the advertiser can already see above says nothing; what
		 * they are agreeing to is the tail.
		 */
		const shared = next.startsWith( link.value ) ? link.value : '';
		const added = '' === shared ? next : next.slice( shared.length );

		preview.replaceChildren();

		if ( '' !== shared ) {
			preview.append( document.createTextNode( shared ) );
		}

		if ( '' !== added ) {
			const mark = document.createElement( 'mark' );

			mark.className = 'aggr-tags__added';
			mark.textContent = added;
			preview.append( mark );
		}

		apply.disabled = next === link.value;

		if ( note ) {
			note.hidden = 0 === already.length;
			note.textContent =
				0 === already.length
					? ''
					: ( root.dataset.aggrLabelKept ?? '' ).replace(
							'%s',
							already.join( ', ' )
					  );
		}
	};

	fields.forEach( ( input ) => input.addEventListener( 'input', draw ) );
	link.addEventListener( 'input', draw );
	link.addEventListener( 'change', draw );

	apply.addEventListener( 'click', () => {
		link.value = withTrackingTags( link.value, read() );
		remember();

		// The same events typing raises, so autosave stores it as a typed edit.
		link.dispatchEvent( new Event( 'input', { bubbles: true } ) );
		link.dispatchEvent( new Event( 'change', { bubbles: true } ) );

		const fold = root.closest( 'details' );

		if ( fold instanceof HTMLDetailsElement ) {
			fold.open = false;
			fold.querySelector( 'summary' )?.focus();
		}

		draw();
	} );

	/*
	 * The macro chips write into whichever field was last used, because a
	 * value like `{creative_id}` belongs in a different parameter for
	 * different advertisers — usually Content, sometimes Campaign. Inserted at
	 * the cursor, so it can sit inside a value they have already typed.
	 */
	let lastField = fields.get( 'content' ) ?? fields.get( 'campaign' ) ?? null;

	fields.forEach( ( input ) => {
		input.addEventListener( 'focus', () => {
			lastField = input;
		} );
	} );

	root.querySelectorAll< HTMLButtonElement >( '[data-aggr-macro]' ).forEach(
		( chip ) => {
			chip.addEventListener( 'click', () => {
				const macro = `{${ chip.dataset.aggrMacro ?? '' }}`;
				const target = lastField;

				if ( ! target ) {
					return;
				}

				const at = target.selectionStart ?? target.value.length;
				const to = target.selectionEnd ?? at;

				target.value =
					target.value.slice( 0, at ) +
					macro +
					target.value.slice( to );
				target.focus();
				target.setSelectionRange(
					at + macro.length,
					at + macro.length
				);
				draw();
			} );
		}
	);

	recall();
	apply.disabled = true;
	draw();
}

if ( typeof document !== 'undefined' ) {
	document
		.querySelectorAll< HTMLElement >( '[data-aggr-link-check]' )
		.forEach( ( root ) => {
			initLinkCheck( root );
		} );
	document
		.querySelectorAll< HTMLElement >( '[data-aggr-tags]' )
		.forEach( ( root ) => {
			initTrackingTags( root );
		} );
}
