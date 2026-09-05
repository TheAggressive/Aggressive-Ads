/**
 * Placement catalogue types, and the body the REST route allowlists.
 */

export const CUSTOM = 'custom';

export type Placement = {
	id: number;
	name: string;
	slug: string;
	size: string;
	size_preset: string;
	size_width: number;
	size_height: number;
	active: boolean;
	sort_order: number;
	house_attachment_id: number;
	house_click_url: string;
	house_alt: string;
	refresh_enabled: boolean;
	refresh_seconds: number;
	refresh_max_per_view: number;
};

export type RefreshDefaults = {
	enabled: boolean;
	seconds: number;
	max_per_view: number;
};

export type Catalogue = {
	sizes: Record< string, string >;
	refresh_defaults: RefreshDefaults;
	refresh_ceiling: number;
	rows: Placement[];
};

export type Bootstrap = {
	view: Catalogue;
	restPath: string;
	i18n: Record< string, string >;
};

export const EMPTY: Bootstrap = {
	view: {
		sizes: {},
		refresh_defaults: { enabled: false, seconds: 30, max_per_view: 6 },
		refresh_ceiling: 100,
		rows: [],
	},
	restPath: '',
	i18n: {},
};

export const blankPlacement = ( defaults: RefreshDefaults ): Placement => ( {
	id: 0,
	name: '',
	slug: '',
	size: '',
	size_preset: '',
	size_width: 0,
	size_height: 0,
	active: true,
	sort_order: 0,
	house_attachment_id: 0,
	house_click_url: '',
	house_alt: '',
	refresh_enabled: defaults.enabled,
	refresh_seconds: defaults.seconds,
	refresh_max_per_view: defaults.max_per_view,
} );

/** The body the REST route allowlists. */
export function body( draft: Placement ): Record< string, unknown > {
	return {
		name: draft.name,
		slug: draft.slug,
		size_preset: draft.size_preset,
		size_width: draft.size_width,
		size_height: draft.size_height,
		sort_order: draft.sort_order,
		is_active: draft.active,
		house_attachment_id: draft.house_attachment_id,
		house_click_url: draft.house_click_url,
		house_alt: draft.house_alt,
		refresh_enabled: draft.refresh_enabled,
		refresh_seconds: draft.refresh_seconds,
		refresh_max_per_view: draft.refresh_max_per_view,
	};
}
