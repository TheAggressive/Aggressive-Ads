/**
 * Shapes the review screens receive from PHP.
 *
 * These mirror `Admin\Review_Data` exactly. They are hand-written rather than
 * generated, so when that class grows a field this file is the second edit —
 * and TypeScript will not tell you, because an unread field is not an error.
 */

export type Tab = {
	key: string;
	label: string;
	count: number;
};

export type QueueRow = {
	id: number;
	title: string;
	org_name: string;
	placements: string[];
	status_text: string;
	pill: string;
	submitted_at: number;
	submitted_text: string;
	reviewer: string;
	pending_updates: number;
};

export type Queue = {
	rows: QueueRow[];
	total: number;
	pages: number;
	page: number;
};

export type Creative = {
	id: number;
	placement: string;
	size: string;
	dimensions: string;
	alt_text: string;
	click_url: string;
	preview: string;
};

export type CreativeUpdate = Creative & {
	current_url: string;
	current_alt: string;
};

export type ChangeRow = {
	field: string;
	label: string;
	from: string;
	to: string;
};

export type ActionRequest = {
	action: string;
	action_label: string;
	reason: string;
};

export type ReviewAction = {
	to: string;
	label: string;
	needs_notes: boolean;
	destructive: boolean;
};

export type AuditEvent = {
	message: string;
	actor: string;
	created_at: number;
	created_text: string;
	outcome: string;
};

export type Campaign = {
	id: number;
	title: string;
	org_name: string;
	status: string;
	status_text: string;
	pill: string;
	placements: string[];
	schedule_text: string;
	submitted_text: string;
	revision: number;
	reviewer: string;
	review_notes: string;
	internal_notes: string;
	creatives: Creative[];
	creative_updates: CreativeUpdate[];
	pending_edits: ChangeRow[];
	action_request: ActionRequest | [];
	actions: ReviewAction[];
	can_view_audit: boolean;
	audit: AuditEvent[];
};

export type Bootstrap = {
	filter: string;
	paged: number;
	campaignId: number;
	queueUrl: string;
	restPath: string;
	tabs: Tab[];
	queue: Queue;
	campaign: Campaign | null;
	i18n: Record< string, string >;
};

/** The action request, when there is one. */
export function requestOf( campaign: Campaign ): ActionRequest | null {
	const request = campaign.action_request;

	return Array.isArray( request ) ? null : request;
}
