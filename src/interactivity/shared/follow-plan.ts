/**
 * The schedule following the package chosen above it.
 *
 * Bundled into `@aggr/calendar`, not a module of its own: both screens that
 * draw a package grid over a schedule — creation, and editing a running
 * campaign — load the calendar beside the dates.
 */

/**
 * The last day of a run, as a UTC midnight, or null.
 *
 * The start day counts as the first day. UTC parts, so the visitor's own
 * daylight-saving change can never move it by one.
 *
 * @param start `YYYY-MM-DD`.
 * @param days  Days the package sells.
 * @return The last day, or null for no usable start.
 */
function lastDay( start: string, days: number ): Date | null {
	const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec( start );

	if ( null === match || days < 1 ) {
		return null;
	}

	return new Date(
		Date.UTC(
			Number( match[ 1 ] ),
			Number( match[ 2 ] ) - 1,
			Number( match[ 3 ] ) + days - 1
		)
	);
}

/**
 * Keeps the schedule true to the package chosen above it.
 *
 * A fixed package decides its own end date, so its end field is hidden and
 * disabled — which also keeps it out of what the form posts, so the server
 * derives the end — and the last day is stated instead. A custom package gets
 * the field back.
 *
 * Here rather than in autosave, where it began, because the running-campaign
 * edit flow draws creation's package grid and schedule and has no autosave:
 * the calendar is the one module both screens load beside the dates.
 *
 * Nothing runs on attach. The server rendered the fieldset for the package it
 * had selected, and rewriting it before anyone has changed anything would only
 * swap one date format for another in front of them. Synchronous on `change`,
 * which matters: autosave reads the form 600ms later, and must find the end
 * field already enabled or disabled to match.
 *
 * @param form The form holding the package radios and the dates.
 */
export function followPlan( form: HTMLFormElement ): void {
	const radios = Array.from(
		form.querySelectorAll< HTMLInputElement >(
			'input[type="radio"][name="package_id"]'
		)
	);
	const start = form.querySelector< HTMLInputElement >(
		'input[name="start_date"]'
	);
	const endField = form.querySelector< HTMLElement >(
		'[data-aggr-end-field]'
	);
	const end = form.querySelector< HTMLInputElement >(
		'input[name="end_date"]'
	);
	const through = form.querySelector< HTMLElement >(
		'[data-aggr-run-through]'
	);

	if (
		0 === radios.length ||
		null === start ||
		'1' === form.dataset.aggrFollowPlan
	) {
		return;
	}

	form.dataset.aggrFollowPlan = '1';

	const said = new Intl.DateTimeFormat(
		document.documentElement.lang || undefined,
		{ year: 'numeric', month: 'long', day: 'numeric', timeZone: 'UTC' }
	);

	const sync = (): void => {
		const chosen = radios.find( ( radio ) => radio.checked );
		const days = Number( chosen?.dataset.aggrDurationDays ?? '0' );
		const fixed = undefined !== chosen && days > 0;

		if ( endField && end ) {
			endField.hidden = fixed;
			end.disabled = fixed;
			// Every campaign ends; a custom package has to say when.
			end.required = ! fixed;
		}

		if ( through ) {
			const moment = fixed ? lastDay( start.value, days ) : null;

			through.hidden = null === moment;
			through.textContent =
				null === moment
					? ''
					: ( through.dataset.aggrTemplate ?? '' ).replace(
							'%s',
							said.format( moment )
					  );
		}
	};

	radios.forEach( ( radio ) => radio.addEventListener( 'change', sync ) );
	start.addEventListener( 'input', sync );
	start.addEventListener( 'change', sync );
}
