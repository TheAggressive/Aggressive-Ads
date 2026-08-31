/**
 * What the conversions screen is handed by PHP.
 *
 * Shared by the two halves of the screen rather than declared in either, so a
 * string added for the credentials card and a string added for the definitions
 * card cannot disagree about the shape of `i18n`.
 */

export type Definition = {
	id: number;
	org_id: number;
	public_key: string;
	name: string;
	window_seconds: number;
	default_value_micros: number;
	currency: string;
	allow_s2s: boolean;
	status: string;
	accepts_reports: boolean;
	revision: number;
};

/**
 * One credential as the staff list sees it: never a secret, and never a raw
 * timestamp it would have to format in the browser's timezone.
 *
 * `id`, `org_id` and the `*_ts` fields are the stored row;
 * `REST\Conversion_Credentials_Controller::index()` adds the rest, and the note
 * there says why each one is decided on the server.
 */
export type Credential = {
	id: number;
	org_id: number;
	org_name: string;
	label: string;
	live: boolean;
	created_at: string;
	last_used_at: string;
	revoked_at: string;

	// Stored seconds, carried alongside the formatted strings so the table can
	// sort on the instant rather than on "August" and "July".
	created_at_ts: number;
	last_used_at_ts: number;
	revoked_at_ts: number;
};

/**
 * The screen's strings, named rather than a `Record< string, string >`.
 *
 * With `noUncheckedIndexedAccess` a record lookup is `string | undefined`, and
 * the honest fixes are either a `?? ''` at every use — which renders an
 * unlabelled control that looks merely unfinished — or a `t()` helper, which
 * this codebase already has to guard with `ReviewStringsTest` for exactly that
 * reason. Naming the keys makes the compiler the guard for anything this bundle
 * misspells; `ConversionStringsTest` is what catches a key PHP never sends,
 * because a payload parsed out of a data attribute is cast, not checked.
 */
export type Strings = {
	newDefinition: string;
	existing: string;
	none: string;
	name: string;
	window: string;
	windowHelp: string;
	value: string;
	valueHelp: string;
	currency: string;
	currencyHelp: string;
	orgScoped: string;
	orgScopedHelp: string;
	orgId: string;
	snippetKey: string;
	status: string;
	actions: string;
	active: string;
	archived: string;
	archive: string;
	create: string;
	days: string;
	loadFailed: string;
	saveFailed: string;
	allowS2s: string;
	allowS2sHelp: string;
	serverReports: string;
	yes: string;
	no: string;
	credentials: string;
	credentialsHelp: string;
	credentialsList: string;
	credentialsNone: string;
	newCredential: string;
	label: string;
	labelHelp: string;
	advertiser: string;
	advertiserHelp: string;
	noAdvertisers: string;
	issue: string;
	issuedOnce: string;
	copy: string;
	copied: string;
	issued: string;
	lastUsed: string;
	never: string;
	live: string;
	revoke: string;
	revoked: string;
	revokeConfirm: string;
	credentialFailed: string;
	searchDefinitions: string;
	searchCredentials: string;
};

export type Payload = {
	restPath: string;
	credentialsPath: string;
	windows: Array< { label: string; value: string } >;
	advertisers: Array< { id: number; name: string } >;
	i18n: Strings;
};
