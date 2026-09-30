/**
 * A record's state in a staff table, drawn the way the portal draws a status.
 *
 * Four screens showed their state column as plain grey text — "Active",
 * "Suspended", "Accepting reports" — the same weight as a slot slug or a date,
 * so a suspended advertiser or a revoked credential had to be read to be found.
 * The portal already answers this with a pill: the word, a dot in the same ink
 * and a tint. This is that pill for the staff screens.
 *
 * **The word is the information; the tone is emphasis.** Callers pass the label
 * their field's `elements` already declare, so the filter menu and the cell can
 * never disagree, and every tone is a pair measured in `AdminContrastTest`.
 *
 * `aggr-state`, not the portal's `aggr-pill`: the Review screen loads both
 * stylesheets, and two components sharing a class name is how a rule written
 * for one ends up reshaping the other.
 */

import type { ReactElement, ReactNode } from 'react';

/** Tones map onto the status tokens; they are not statuses of their own. */
export type Tone = 'live' | 'neutral' | 'danger' | 'pending' | 'attention';

export function State( {
	tone,
	children,
}: {
	tone: Tone;
	children: ReactNode;
} ): ReactElement {
	return (
		<span className={ `aggr-state aggr-state--${ tone }` }>
			{ children }
		</span>
	);
}
