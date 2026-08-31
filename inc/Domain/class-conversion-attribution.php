<?php
/**
 * Whether one reported outcome may be credited to one interaction.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * The whole attribution decision, as one pure function over server-held facts.
 *
 * Every input here was read from the database or verified from a signed token.
 * Nothing a client said reaches this class except the moment the outcome
 * happened, and even that is bounded against a timestamp the server wrote.
 *
 * The reasons are a closed set because the observability contract requires
 * refusals to be counted apart: an invalid lineage is abuse or a bug, while an
 * out-of-window report is usually a window configured too short. Collapsing
 * them into one "rejected" number would hide the difference that decides what
 * to do about it.
 */
final class Conversion_Attribution {

	/** Creditable. */
	public const ACCEPTED = 'accepted';

	/** No definition with that public key. */
	public const NO_DEFINITION = 'no_definition';

	/** The definition exists and is archived. */
	public const DEFINITION_CLOSED = 'definition_closed';

	/** The definition belongs to a different organization than the campaign. */
	public const FOREIGN_DEFINITION = 'foreign_definition';

	/** The token never recorded a click or a view, so there is nothing to credit. */
	public const NO_INTERACTION = 'no_interaction';

	/** The interaction happened, but too long ago. */
	public const OUT_OF_WINDOW = 'out_of_window';

	/** The definition exists but does not accept server-side reports. */
	public const S2S_NOT_PERMITTED = 's2s_not_permitted';

	/** The credential is scoped to a different organization than the definition. */
	public const FOREIGN_CREDENTIAL = 'foreign_credential';

	/**
	 * Every reason, for the observability counters.
	 *
	 * @return list<string>
	 */
	public static function reasons(): array {
		return array(
			self::ACCEPTED,
			self::NO_DEFINITION,
			self::DEFINITION_CLOSED,
			self::FOREIGN_DEFINITION,
			self::NO_INTERACTION,
			self::OUT_OF_WINDOW,
			self::S2S_NOT_PERMITTED,
			self::FOREIGN_CREDENTIAL,
		);
	}

	/**
	 * The three refusals a client must not be able to tell apart.
	 *
	 * A definition that does not exist, one that has been retired, and one
	 * belonging to somebody else are different facts internally and the same
	 * answer externally. Distinguishing them would turn the public endpoint
	 * into an oracle for which definitions exist on the site and who owns them,
	 * which is the reason `public_key` is unguessable in the first place.
	 *
	 * The two server-side refusals join them for the same reason one step on.
	 * A credential holder is authenticated, so telling it "that definition is
	 * not yours" leaks less than telling an anonymous browser — but it still
	 * distinguishes a definition that exists from one that does not, which is
	 * exactly the enumeration `public_key` is unguessable to prevent. Holding a
	 * credential for one organization must not become a way to discover another
	 * organization's definitions.
	 *
	 * @return list<string>
	 */
	public static function indistinguishable_refusals(): array {
		return array(
			self::NO_DEFINITION,
			self::DEFINITION_CLOSED,
			self::FOREIGN_DEFINITION,
			self::S2S_NOT_PERMITTED,
			self::FOREIGN_CREDENTIAL,
		);
	}

	/**
	 * Whether an authenticated server-side reporter may use this definition.
	 *
	 * Separate from `decide()` rather than another branch inside it, because it
	 * answers a different question about a different actor. `decide()` asks
	 * whether an outcome is creditable to an interaction, and its inputs are the
	 * same whoever reported it; this asks whether *this reporter* may report
	 * against *this definition* at all, and it has no meaning for a browser —
	 * which is why the browser path has no parameter through which a credential
	 * could arrive.
	 *
	 * Ordered so the cheaper, less revealing refusal comes first, matching
	 * `decide()`.
	 *
	 * @param array{org_id: int, allow_s2s: bool} $definition        Stored definition.
	 * @param int                                 $credential_org_id Organization the credential is scoped to.
	 * @return string ACCEPTED, or the reason this reporter may not.
	 */
	public static function decide_server_report( array $definition, int $credential_org_id ): string {
		if ( ! $definition['allow_s2s'] ) {
			return self::S2S_NOT_PERMITTED;
		}

		/*
		 * Exact match, and org 0 is not a wildcard in either direction. A
		 * publisher's own definition (org 0) measures whoever clicked, and no
		 * advertiser's credential may report into it; an advertiser's
		 * definition may only be reported into by that advertiser's credential.
		 * `decide()` lets org 0 accept any campaign because the *visitor* is
		 * anonymous there — a credential never is.
		 */
		if ( $definition['org_id'] !== $credential_org_id ) {
			return self::FOREIGN_CREDENTIAL;
		}

		return self::ACCEPTED;
	}

	/**
	 * Decides whether one outcome is creditable, and says why when it is not.
	 *
	 * Ordered cheapest-first and least-revealing-first: the definition is
	 * resolved before any event lookup, so a request naming no real definition
	 * costs one indexed read and never touches the ledger.
	 *
	 * @param array{org_id: int, window_seconds: int, status: string}|null $definition  Stored definition, or null when the key resolved to nothing.
	 * @param array{event: string, created_at_ts: int}|null                $interaction Server-recorded click or view for the token, or null.
	 * @param int                                                          $campaign_org_id Organization owning the campaign the token was minted for.
	 * @param int                                                          $occurred_at_ts  When the outcome happened.
	 * @return string One of the reason constants.
	 */
	public static function decide(
		?array $definition,
		?array $interaction,
		int $campaign_org_id,
		int $occurred_at_ts
	): string {
		if ( null === $definition ) {
			return self::NO_DEFINITION;
		}

		if ( ! Conversion_Definition::accepts_reports( $definition['status'] ) ) {
			return self::DEFINITION_CLOSED;
		}

		/*
		 * A definition with org 0 measures for whoever clicked — it is the
		 * publisher's own, not an advertiser's. A definition naming an
		 * organization may only be credited against that organization's
		 * campaigns, or one advertiser's thank-you page could report
		 * conversions onto another's spend.
		 */
		if ( 0 !== $definition['org_id'] && $definition['org_id'] !== $campaign_org_id ) {
			return self::FOREIGN_DEFINITION;
		}

		if ( null === $interaction ) {
			return self::NO_INTERACTION;
		}

		if ( ! Conversion_Rules::is_attributable_event( $interaction['event'] ) ) {
			return self::NO_INTERACTION;
		}

		if ( ! Conversion_Rules::is_within_window( $interaction['created_at_ts'], $occurred_at_ts, $definition['window_seconds'] ) ) {
			return self::OUT_OF_WINDOW;
		}

		return self::ACCEPTED;
	}
}
