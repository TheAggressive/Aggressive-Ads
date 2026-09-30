/**
 * What a table shows when it has no rows: an icon, what is missing, and why it
 * matters.
 *
 * It renders inside DataViews' own `empty` slot, so the search box and filters
 * stay on screen — replacing the table with a sentence takes away the controls
 * needed to undo the query that emptied it. The creating action is not
 * repeated here; it is already in the table's toolbar directly above, and two
 * buttons that do the same thing make a person wonder whether they differ.
 */

import type { ReactElement } from 'react';
import { IconChip, type IconName } from './icon';

export function Empty( {
	icon,
	children,
}: {
	icon: IconName;
	children: string;
} ): ReactElement {
	return (
		<div className="aggr-empty-state">
			<IconChip name={ icon } size="lg" />
			<p>{ children }</p>
		</div>
	);
}
