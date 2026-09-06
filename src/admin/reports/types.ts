/**
 * The utilisation view, as the reports screen bootstraps it.
 */

/** One placement's page-opportunity utilisation for the window on screen. */
export type PlacementRow = {
	id: number;
	name: string;
	slug: string;
	groups: string[];
	requests: number;
	fills: number;

	/**
	 * Fills over page requests, or null when nothing was requested.
	 *
	 * **Null is not zero.** A placement nobody requested did not fail to fill,
	 * and rendering 0% for it tells a publisher they have a problem where they
	 * have no data. The server draws the same distinction — see
	 * `Domain\Fill_Figures`.
	 */
	fill_rate: number | null;

	/** Requests with neither a fill nor a recorded reason. Zero on a healthy site. */
	unaccounted: number;
};

/** One group's totals, summed from its placements rather than averaged. */
export type GroupRow = {
	slug: string;
	placements: number;
	requests: number;
	fills: number;
	fill_rate: number | null;
};

export type Utilisation = {
	placements: PlacementRow[];
	groups: GroupRow[];
};

export type Payload = {
	view: Utilisation;
	i18n: Record< string, string >;
};

export const EMPTY: Payload = {
	view: { placements: [], groups: [] },
	i18n: {},
};
