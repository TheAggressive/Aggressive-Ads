/** One placement's outlook for the window on screen. */
export type ForecastRow = {
	id: number;
	name: string;
	slug: string;

	/**
	 * What the window is forecast to supply, or `null` when nobody has
	 * measured this placement. Null is not zero: a zero would say sold out.
	 */
	forecast: number | null;
	optimistic: number | null;
	confidence: 'none' | 'low' | 'medium' | 'high';
	version: number;
	made_at: string;
	committed: number;
	remaining: number | null;
	verdict: 'available' | 'oversell' | 'unknown';
};

/** The figures above the table. */
export type ForecastTotals = {
	placements: number;
	forecast: number;
	committed: number;
	remaining: number;
	unforecast: number;
	oversold: number;
};

/** Everything the server hands the screen. */
export type ForecastPayload = {
	view: {
		window: { from: string; to: string };
		opportunity: string;
		rows: ForecastRow[];
		totals: ForecastTotals;
	};
	i18n: Record< string, string >;
};
