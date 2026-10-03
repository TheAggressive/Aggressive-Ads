/**
 * A name's initials on a round chip, beside the name — the portal's avatar.
 *
 * A column of names in body text is a column of names; with a mark beside
 * each, a person or an organization reads as one, and a list can be scanned by
 * shape. The initials repeat the name printed next to them, so the chip is
 * hidden from assistive technology rather than announced twice.
 *
 * `Array.from` rather than indexing, so a name that starts with an emoji or an
 * astral-plane letter yields that character and not half of a surrogate pair.
 */

import type { ReactElement } from 'react';

export function initialsOf( name: string ): string {
	const words = name.trim().split( /\s+/ ).filter( Boolean );
	const first = ( word: string | undefined ): string =>
		word ? Array.from( word )[ 0 ] ?? '' : '';

	return (
		first( words[ 0 ] ) +
		( words.length > 1 ? first( words[ words.length - 1 ] ) : '' )
	).toUpperCase();
}

export function Named( {
	name,
	variant = 'person',
}: {
	name: string;
	variant?: 'person' | 'organization';
} ): ReactElement | null {
	/*
	 * Nothing for no name. A campaign whose organization has since been
	 * deleted arrives with an empty name, and drew an empty grey square
	 * under the page title.
	 */
	if ( '' === name.trim() ) {
		return null;
	}

	return (
		<span className="aggr-named">
			<span
				className={ `aggr-initials aggr-initials--${ variant }` }
				aria-hidden="true"
			>
				{ initialsOf( name ) }
			</span>
			<span>{ name }</span>
		</span>
	);
}
