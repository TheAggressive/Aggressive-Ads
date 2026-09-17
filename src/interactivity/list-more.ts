/**
 * More campaigns, on the same page, without losing the pages underneath.
 *
 * Module: @aggr/list-more
 *
 * **Progressive, never the only path.** The server renders Previous and Next
 * links, which work without script and are what a failed load falls back to.
 * With script they become one "Show more campaigns" button that fetches the
 * next page's own URL — the same query, the same session, the same tenant
 * scope, no second endpoint to keep in step — and appends its rows.
 *
 * **Infinite, but bounded.** Scrolling to the button loads the next page for
 * up to three pages in a row, then waits for a press, so the footer and
 * everything after the table stay reachable. Loading announces the new count
 * and never moves focus by itself; pressing the button moves focus to the
 * first new campaign, which is where the reader asked to go.
 */

export const MAX_AUTO_LOADS = 3;

type Fetcher = ( url: string ) => Promise< Response >;

interface Labels {
	more: string;
	loading: string;
	error: string;
	count: string;
	loaded: string;
	done: string;
}

/**
 * `%1$s`-style placeholders filled in order, the way the server's strings are.
 *
 * @param template Translated sentence.
 * @param values   Replacements, in placeholder order.
 */
export function fill( template: string, values: string[] ): string {
	return template.replace(
		/%(?:(\d+)\$)?s/g,
		( match: string, position?: string ) =>
			values[ position ? Number( position ) - 1 : 0 ] ?? match
	);
}

/**
 * Wires one list's footer. Returns a handle for tests, or null when there is
 * nothing to enhance.
 *
 * @param footer  The element carrying `data-aggr-list-more`.
 * @param fetcher Network access; `fetch` in the browser.
 */
export function initListMore(
	footer: HTMLElement,
	fetcher: Fetcher = ( url ) =>
		window.fetch( url, {
			credentials: 'same-origin',
			headers: { Accept: 'text/html' },
		} )
): { load: ( fromUser: boolean ) => Promise< void > } | null {
	const rows = document.querySelector< HTMLElement >(
		'[data-aggr-list-rows]'
	);
	const count = footer.querySelector< HTMLElement >(
		'[data-aggr-list-count]'
	);
	const status = footer.querySelector< HTMLElement >(
		'[data-aggr-list-status]'
	);
	const pager = footer.querySelector< HTMLElement >( '.aggr-pager' );
	let next = footer.dataset.aggrNext ?? '';

	if ( ! rows || ! count || ! status || '' === next ) {
		return null;
	}

	const d = footer.dataset;
	const labels: Labels = {
		more: d.aggrLabelMore ?? '',
		loading: d.aggrLabelLoading ?? '',
		error: d.aggrLabelError ?? '',
		count: d.aggrLabelCount ?? '',
		loaded: d.aggrLabelLoaded ?? '',
		done: d.aggrLabelDone ?? '',
	};
	const total = Number( d.aggrTotal ?? '0' );
	const first = Number( d.aggrFirst ?? '1' );
	const numbers = new Intl.NumberFormat(
		document.documentElement.lang || undefined
	);

	const button = document.createElement( 'button' );
	button.type = 'button';
	button.className =
		'aggr-button aggr-button--secondary aggr-list-more__button';
	button.textContent = labels.more;

	if ( pager ) {
		pager.hidden = true;
		pager.after( button );
	} else {
		count.after( button );
	}

	let loading = false;
	let autoLoads = 0;

	const shown = (): number =>
		rows.querySelectorAll( '[data-aggr-row]' ).length;

	const restate = (): void => {
		count.textContent = fill( labels.count, [
			numbers.format( first ),
			numbers.format( first + shown() - 1 ),
			numbers.format( total ),
		] );
	};

	const fail = (): void => {
		status.textContent = labels.error;
		button.textContent = labels.more;
		button.disabled = false;

		// The links the server drew are the way on when this cannot be.
		if ( pager ) {
			pager.hidden = false;
		}
	};

	const load = async ( fromUser: boolean ): Promise< void > => {
		if ( loading || '' === next ) {
			return;
		}

		const target = new URL( next, window.location.href );

		// Only ever this site's own list page.
		if ( target.origin !== window.location.origin ) {
			fail();
			return;
		}

		loading = true;
		button.disabled = true;
		button.textContent = labels.loading;

		try {
			const response = await fetcher( target.toString() );

			if ( ! response.ok ) {
				throw new Error( String( response.status ) );
			}

			const page = new DOMParser().parseFromString(
				await response.text(),
				'text/html'
			);
			const incoming = page.querySelectorAll< HTMLElement >(
				'[data-aggr-list-rows] [data-aggr-row]'
			);
			const nextFooter = page.querySelector< HTMLElement >(
				'[data-aggr-list-more]'
			);

			if ( ! nextFooter ) {
				throw new Error( 'No list on the fetched page.' );
			}

			const seen = new Set(
				Array.from(
					rows.querySelectorAll< HTMLElement >( '[data-aggr-row]' )
				).map( ( row ) => row.dataset.aggrRow )
			);
			let firstNew: HTMLElement | null = null;
			let added = 0;

			incoming.forEach( ( row ) => {
				// A campaign created between loads shifts every page by one.
				if ( seen.has( row.dataset.aggrRow ) ) {
					return;
				}

				const adopted = document.importNode( row, true );
				rows.append( adopted );
				firstNew = firstNew ?? adopted;
				added++;
			} );

			next = nextFooter.dataset.aggrNext ?? '';
			restate();

			status.textContent =
				'' === next
					? fill( labels.done, [ numbers.format( shown() ) ] )
					: fill( labels.loaded, [
							numbers.format( added ),
							numbers.format( shown() ),
							numbers.format( total ),
					  ] );

			if ( fromUser && firstNew ) {
				( firstNew as HTMLElement )
					.querySelector< HTMLElement >( 'a' )
					?.focus();
			}

			if ( '' === next ) {
				button.remove();
				pager?.remove();
			} else {
				button.disabled = false;
				button.textContent = labels.more;
			}
		} catch {
			fail();
		} finally {
			loading = false;
		}
	};

	button.addEventListener( 'click', () => {
		autoLoads = 0;
		void load( true );
	} );

	if ( 'IntersectionObserver' in window ) {
		const observer = new IntersectionObserver(
			( entries ) => {
				if (
					entries.some( ( entry ) => entry.isIntersecting ) &&
					autoLoads < MAX_AUTO_LOADS &&
					! button.disabled
				) {
					autoLoads++;
					void load( false );
				}
			},
			{ rootMargin: '200px 0px' }
		);

		observer.observe( button );
	}

	return { load };
}

if ( typeof document !== 'undefined' ) {
	document
		.querySelectorAll< HTMLElement >( '[data-aggr-list-more]' )
		.forEach( ( footer ) => {
			initListMore( footer );
		} );
}
