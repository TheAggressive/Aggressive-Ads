/**
 * The staff screens' icons, drawn the way the portal's rail draws its own.
 *
 * Same geometry as `templates/portal/partials/icon.php`: a 24-unit box, a
 * 1.75 stroke in `currentColor`, round caps and joins, no fills. The shapes the
 * two sets share (`campaigns`, `organization`) use the portal's paths exactly,
 * so a campaign looks like a campaign on both surfaces. Inline SVG rather than
 * an icon library: a dozen shapes is less than one dependency, and nothing
 * here needs to load before it can draw.
 *
 * **Decorative, always.** Every icon sits beside a word that says the same
 * thing, so each is `aria-hidden` and never the only name a control has. An
 * icon that needs to carry meaning alone needs a label, not a better icon.
 */

import type { ReactElement } from 'react';

const SHAPES = {
	campaigns: (
		<>
			<path d="M4 9v6h3l6 4V5L7 9H4z" />
			<path d="M17.5 8.5a5 5 0 0 1 0 7" />
		</>
	),
	organization: (
		<>
			<path d="M3 21h18" />
			<path d="M5 21V6a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v15" />
			<path d="M13 10h5a1 1 0 0 1 1 1v10" />
			<path d="M8 9h2M8 13h2M8 17h2M16 14h1M16 18h1" />
		</>
	),
	modules: (
		<>
			<rect x="2.5" y="6.5" width="19" height="11" rx="5.5" />
			<circle cx="16" cy="12" r="3" />
		</>
	),
	edit: (
		<>
			<path d="M4 20h4L19 9l-4-4L4 16v4z" />
			<path d="M13.5 6.5l4 4" />
		</>
	),
	brand: (
		<>
			<path d="M12 3a9 9 0 1 0 0 18c1.1 0 1.6-.8 1.6-1.6 0-.9-.8-1.3-.8-2.2 0-.8.7-1.4 1.6-1.4H17a4 4 0 0 0 4-4c0-4.9-4-8.8-9-8.8z" />
			<circle cx="7.5" cy="11.5" r="1" />
			<circle cx="10" cy="7.5" r="1" />
			<circle cx="15" cy="7.5" r="1" />
		</>
	),
	delivery: <path d="M13 3 5 14h6l-1 7 8-11h-6l1-7z" />,
	retention: (
		<>
			<rect x="3" y="4" width="18" height="5" rx="1" />
			<path d="M5 9v10a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9" />
			<path d="M10 13h4" />
		</>
	),
	access: (
		<>
			<path d="M12 3l7 3v5c0 4.5-3 8-7 10-4-2-7-5.5-7-10V6l7-3z" />
			<path d="M9 12l2 2 4-4" />
		</>
	),
	placements: (
		<>
			<rect x="3" y="3" width="18" height="18" rx="2" />
			<path d="M3 9h18M9 21V9" />
		</>
	),
	forecast: (
		<>
			<path d="M3 17l6-6 4 4 8-8" />
			<path d="M15 7h6v6" />
		</>
	),
	booked: (
		<>
			<rect x="3" y="5" width="18" height="16" rx="2" />
			<path d="M3 10h18M8 3v4M16 3v4" />
			<path d="M9 15l2 2 4-4" />
		</>
	),
	warning: (
		<>
			<path d="M12 3 2 20h20L12 3z" />
			<path d="M12 10v4M12 17h.01" />
		</>
	),
	unknown: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.6.2-.7.6-.7 1.1v.5" />
			<path d="M12 17h.01" />
		</>
	),
	conversion: (
		<>
			<circle cx="12" cy="12" r="9" />
			<circle cx="12" cy="12" r="5" />
			<circle cx="12" cy="12" r="1" />
		</>
	),
	key: (
		<>
			<circle cx="8" cy="15" r="4" />
			<path d="M11 12l9-9M17 6l3 3M15 8l2 2" />
		</>
	),
	package: (
		<>
			<path d="M21 8l-9-5-9 5 9 5 9-5z" />
			<path d="M3 8v8l9 5 9-5V8" />
			<path d="M12 13v8" />
		</>
	),
	clock: (
		<>
			<circle cx="12" cy="12" r="9" />
			<path d="M12 7v5l3 2" />
		</>
	),
	plus: <path d="M12 5v14M5 12h14" />,
} as const;

export type IconName = keyof typeof SHAPES;

export function Icon( {
	name,
	size = 18,
}: {
	name: IconName;
	size?: number;
} ): ReactElement {
	return (
		<svg
			className="aggr-icon"
			viewBox="0 0 24 24"
			width={ size }
			height={ size }
			fill="none"
			stroke="currentColor"
			strokeWidth={ 1.75 }
			strokeLinecap="round"
			strokeLinejoin="round"
			aria-hidden="true"
			focusable="false"
		>
			{ SHAPES[ name ] }
		</svg>
	);
}

/**
 * An icon on a soft square, for a section or an empty state.
 *
 * The chip is what makes a small line icon read as a marker for the block
 * beside it rather than as a stray glyph floating in whitespace — the portal's
 * rail uses the same device for its navigation.
 */
export function IconChip( {
	name,
	size = 'md',
}: {
	name: IconName;
	size?: 'md' | 'lg';
} ): ReactElement {
	return (
		<span className={ `aggr-icon-chip aggr-icon-chip--${ size }` }>
			<Icon name={ name } size={ 'lg' === size ? 24 : 18 } />
		</span>
	);
}
