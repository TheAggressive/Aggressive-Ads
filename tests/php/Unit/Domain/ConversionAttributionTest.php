<?php
/**
 * The attribution decision, at every branch.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Conversion_Attribution;
use Aggressive\Ads\Domain\Conversion_Definition;
use Aggressive\Ads\Domain\Measurement_Event_Type;
use PHPUnit\Framework\TestCase;

/**
 * Every refusal here is a different operational problem, so every one is
 * asserted by name rather than as "not accepted".
 */
final class ConversionAttributionTest extends TestCase {

	private const CLICK_AT = 1700000000;

	/**
	 * Builds one definition.
	 *
	 * Named parameters rather than an overrides array, so the shape is what PHP
	 * infers rather than what an inline `@var` asserts. This project forbids
	 * those precisely because an assertion outlives the thing it describes.
	 *
	 * @param int    $org_id         Owning organization, or 0 for house.
	 * @param int    $window_seconds Attribution window.
	 * @param string $status         Definition status.
	 * @return array{org_id: int, window_seconds: int, status: string}
	 */
	private static function definition(
		int $org_id = 12,
		int $window_seconds = 2592000,
		string $status = Conversion_Definition::STATUS_ACTIVE
	): array {
		return array(
			'org_id'         => $org_id,
			'window_seconds' => $window_seconds,
			'status'         => $status,
		);
	}

	/**
	 * Builds one recorded interaction.
	 *
	 * @param string $event         Attributed event type.
	 * @param int    $created_at_ts When the server recorded it.
	 * @return array{event: string, created_at_ts: int}
	 */
	private static function click(
		string $event = Measurement_Event_Type::TYPE_CLICK,
		int $created_at_ts = self::CLICK_AT
	): array {
		return array(
			'event'         => $event,
			'created_at_ts' => $created_at_ts,
		);
	}

	public function test_a_click_inside_the_window_is_accepted(): void {
		$this->assertSame(
			Conversion_Attribution::ACCEPTED,
			Conversion_Attribution::decide( self::definition(), self::click(), 12, self::CLICK_AT + 3600 )
		);
	}

	public function test_a_view_inside_the_window_is_accepted(): void {
		$this->assertSame(
			Conversion_Attribution::ACCEPTED,
			Conversion_Attribution::decide(
				self::definition(),
				self::click( event: Measurement_Event_Type::TYPE_VIEWABLE ),
				12,
				self::CLICK_AT + 3600
			)
		);
	}

	public function test_an_unknown_definition_says_so(): void {
		$this->assertSame(
			Conversion_Attribution::NO_DEFINITION,
			Conversion_Attribution::decide( null, self::click(), 12, self::CLICK_AT + 1 )
		);
	}

	public function test_an_archived_definition_is_closed_not_missing(): void {
		$this->assertSame(
			Conversion_Attribution::DEFINITION_CLOSED,
			Conversion_Attribution::decide(
				self::definition( status: Conversion_Definition::STATUS_ARCHIVED ),
				self::click(),
				12,
				self::CLICK_AT + 1
			)
		);
	}

	/**
	 * **The tenancy boundary.**
	 *
	 * One advertiser's thank-you page must not be able to report conversions
	 * onto another advertiser's campaign, which is what would happen if a
	 * definition could be credited against any click at all.
	 */
	public function test_a_definition_cannot_be_credited_to_another_organizations_campaign(): void {
		$this->assertSame(
			Conversion_Attribution::FOREIGN_DEFINITION,
			Conversion_Attribution::decide( self::definition( org_id: 12 ), self::click(), 99, self::CLICK_AT + 1 )
		);
	}

	/**
	 * A house definition measures for whoever clicked.
	 *
	 * Organization 0 is the publisher's own, not an advertiser's, so it is the
	 * one definition that may be credited across campaigns.
	 */
	public function test_a_house_definition_is_credited_to_any_campaign(): void {
		$this->assertSame(
			Conversion_Attribution::ACCEPTED,
			Conversion_Attribution::decide( self::definition( org_id: 0 ), self::click(), 99, self::CLICK_AT + 1 )
		);
	}

	/**
	 * But an advertiser's definition is not creditable to a house fill.
	 *
	 * A house fill has campaign 0 and therefore organization 0, and an
	 * organization-scoped definition must not match it.
	 */
	public function test_an_advertiser_definition_is_not_credited_to_a_house_fill(): void {
		$this->assertSame(
			Conversion_Attribution::FOREIGN_DEFINITION,
			Conversion_Attribution::decide( self::definition( org_id: 12 ), self::click(), 0, self::CLICK_AT + 1 )
		);
	}

	public function test_a_token_that_never_interacted_credits_nothing(): void {
		$this->assertSame(
			Conversion_Attribution::NO_INTERACTION,
			Conversion_Attribution::decide( self::definition(), null, 12, self::CLICK_AT + 1 )
		);
	}

	/**
	 * A delivery is in the lifecycle, precedes both attributable events, and is
	 * not one of them. Crediting it would attribute every filled slot.
	 */
	public function test_a_served_ad_alone_credits_nothing(): void {
		$this->assertSame(
			Conversion_Attribution::NO_INTERACTION,
			Conversion_Attribution::decide(
				self::definition(),
				self::click( event: Measurement_Event_Type::TYPE_SERVED ),
				12,
				self::CLICK_AT + 1
			)
		);
	}

	public function test_a_report_past_the_window_says_so(): void {
		$this->assertSame(
			Conversion_Attribution::OUT_OF_WINDOW,
			Conversion_Attribution::decide( self::definition( window_seconds: 3600 ), self::click(), 12, self::CLICK_AT + 3601 )
		);
	}

	public function test_the_window_is_inclusive_at_its_last_second(): void {
		$this->assertSame(
			Conversion_Attribution::ACCEPTED,
			Conversion_Attribution::decide( self::definition( window_seconds: 3600 ), self::click(), 12, self::CLICK_AT + 3600 )
		);
	}

	public function test_a_conversion_before_its_click_is_out_of_window(): void {
		$this->assertSame(
			Conversion_Attribution::OUT_OF_WINDOW,
			Conversion_Attribution::decide( self::definition(), self::click(), 12, self::CLICK_AT - 1 )
		);
	}

	/**
	 * **The definition is resolved before the lineage is.**
	 *
	 * The recorder relies on this to defer its ledger read: passing a null
	 * interaction must answer `NO_INTERACTION` whenever every definition-level
	 * check passed, and something else whenever one did not. If that stopped
	 * being true the recorder would either query the ledger for requests that
	 * never needed it, or skip the query for requests that did.
	 */
	public function test_a_null_interaction_reports_only_definition_problems(): void {
		$this->assertSame(
			Conversion_Attribution::NO_INTERACTION,
			Conversion_Attribution::decide( self::definition(), null, 12, self::CLICK_AT ),
			'A good definition with no lineage must answer NO_INTERACTION.'
		);

		$this->assertSame(
			Conversion_Attribution::NO_DEFINITION,
			Conversion_Attribution::decide( null, null, 12, self::CLICK_AT )
		);

		$this->assertSame(
			Conversion_Attribution::DEFINITION_CLOSED,
			Conversion_Attribution::decide( self::definition( status: Conversion_Definition::STATUS_ARCHIVED ), null, 12, self::CLICK_AT )
		);

		$this->assertSame(
			Conversion_Attribution::FOREIGN_DEFINITION,
			Conversion_Attribution::decide( self::definition(), null, 99, self::CLICK_AT )
		);
	}

	/**
	 * The three refusals a client must not be able to tell apart are named, and
	 * acceptance is not one of them.
	 */
	public function test_the_indistinguishable_refusals_are_the_definition_ones(): void {
		$hidden = Conversion_Attribution::indistinguishable_refusals();

		$this->assertContains( Conversion_Attribution::NO_DEFINITION, $hidden );
		$this->assertContains( Conversion_Attribution::DEFINITION_CLOSED, $hidden );
		$this->assertContains( Conversion_Attribution::FOREIGN_DEFINITION, $hidden );
		$this->assertNotContains( Conversion_Attribution::ACCEPTED, $hidden );
	}

	/**
	 * Every reason the decision can return is in the published vocabulary.
	 *
	 * The operator counters are keyed by it, so a reason missing from the list
	 * is a refusal nobody can see happening — and the assertion runs both ways,
	 * so a reason in the list that nothing produces is a counter that can only
	 * ever read zero.
	 *
	 * The vocabulary now spans two functions. `decide()` answers whether an
	 * outcome is creditable to an interaction; `decide_server_report()` answers
	 * whether this reporter may report against this definition at all, which has
	 * no meaning for a browser. Both feed the same counters, so both have to be
	 * exercised here or adding a reason to one would look reachable because the
	 * other happened to cover the list.
	 */
	public function test_every_reachable_reason_is_published(): void {
		$reachable = array(
			Conversion_Attribution::decide( self::definition(), self::click(), 12, self::CLICK_AT ),
			Conversion_Attribution::decide( null, null, 0, self::CLICK_AT ),
			Conversion_Attribution::decide( self::definition( status: Conversion_Definition::STATUS_ARCHIVED ), null, 12, self::CLICK_AT ),
			Conversion_Attribution::decide( self::definition(), null, 99, self::CLICK_AT ),
			Conversion_Attribution::decide( self::definition(), null, 12, self::CLICK_AT ),
			Conversion_Attribution::decide( self::definition( window_seconds: 3600 ), self::click(), 12, self::CLICK_AT + 99999 ),
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 12,
					'allow_s2s' => false,
				),
				12
			),
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 12,
					'allow_s2s' => true,
				),
				99
			),
		);

		$this->assertSame( Conversion_Attribution::reasons(), array_values( array_unique( $reachable ) ) );
	}

	/**
	 * **A definition that does not permit server reports refuses one.**
	 *
	 * `allow_s2s` was stored, validated and exposed through REST for a whole
	 * phase while nothing read it — a checkbox that did nothing. This is the
	 * read.
	 */
	public function test_a_definition_that_forbids_server_reports_refuses_one(): void {
		$this->assertSame(
			Conversion_Attribution::S2S_NOT_PERMITTED,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 7,
					'allow_s2s' => false,
				),
				7
			)
		);
	}

	/**
	 * **A credential may only report into its own organization's definition.**
	 *
	 * Otherwise one advertiser's integration reports conversions onto another
	 * advertiser's spend, which is the whole reason a credential is scoped.
	 */
	public function test_a_credential_for_another_organization_is_refused(): void {
		$this->assertSame(
			Conversion_Attribution::FOREIGN_CREDENTIAL,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 7,
					'allow_s2s' => true,
				),
				8
			)
		);
	}

	/**
	 * **Organization 0 is not a wildcard here, and that asymmetry is deliberate.**
	 *
	 * `decide()` lets an org-0 definition accept a conversion from any campaign,
	 * because the visitor reporting it is anonymous and the definition is the
	 * publisher's own. A credential never is: one scoped to org 0 could report
	 * against every advertiser on the site, so neither direction matches.
	 */
	public function test_organization_zero_is_not_a_wildcard_for_a_credential(): void {
		$this->assertSame(
			Conversion_Attribution::FOREIGN_CREDENTIAL,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 0,
					'allow_s2s' => true,
				),
				7
			),
			"A publisher's own definition must not be reportable by an advertiser's credential."
		);

		$this->assertSame(
			Conversion_Attribution::FOREIGN_CREDENTIAL,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 7,
					'allow_s2s' => true,
				),
				0
			),
			'An unscoped credential must not reach a scoped definition.'
		);
	}

	/**
	 * The permitted case, so the refusals above are not passing by accident.
	 */
	public function test_a_scoped_credential_on_a_permitted_definition_is_accepted(): void {
		$this->assertSame(
			Conversion_Attribution::ACCEPTED,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 7,
					'allow_s2s' => true,
				),
				7
			)
		);
	}

	/**
	 * **Permission is checked before scope, and the order is not cosmetic.**
	 *
	 * Both answers reach the caller as one refusal, so the ordering is about
	 * what an operator reads in the counters: "this definition does not allow
	 * server reporting" is a configuration mistake somebody can fix, while
	 * "that credential belongs to someone else" is either a misconfigured
	 * integration or an attempt. Reporting the second when the first is also
	 * true would send the operator after the wrong one.
	 */
	public function test_a_definition_that_forbids_reporting_says_so_before_it_says_foreign(): void {
		$this->assertSame(
			Conversion_Attribution::S2S_NOT_PERMITTED,
			Conversion_Attribution::decide_server_report(
				array(
					'org_id'    => 7,
					'allow_s2s' => false,
				),
				8
			)
		);
	}

	/**
	 * **Both new refusals are indistinguishable from an unknown definition.**
	 *
	 * A credential holder is authenticated, so telling it "not yours" leaks less
	 * than telling an anonymous browser — but it still separates a definition
	 * that exists from one that does not, which is exactly the enumeration
	 * `public_key` is unguessable to prevent.
	 */
	public function test_the_server_refusals_are_indistinguishable_from_an_unknown_definition(): void {
		$hidden = Conversion_Attribution::indistinguishable_refusals();

		$this->assertContains( Conversion_Attribution::S2S_NOT_PERMITTED, $hidden );
		$this->assertContains( Conversion_Attribution::FOREIGN_CREDENTIAL, $hidden );
		$this->assertContains( Conversion_Attribution::NO_DEFINITION, $hidden );
	}

	/**
	 * The reason vocabulary stays closed, so the counters can enumerate it.
	 */
	public function test_every_reason_is_declared(): void {
		$reasons = Conversion_Attribution::reasons();

		$this->assertContains( Conversion_Attribution::S2S_NOT_PERMITTED, $reasons );
		$this->assertContains( Conversion_Attribution::FOREIGN_CREDENTIAL, $reasons );
		$this->assertSame( array_unique( $reasons ), $reasons, 'A duplicated reason would double-count.' );
	}
}
