<?php
/**
 * Measurement model, event types, no-fill taxonomy, and lifecycle rules tests.
 *
 * @package Aggressive\Ads\Tests\Unit\Domain
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Exclusion_Reason;
use Aggressive\Ads\Domain\Measurement_Event_Type;
use Aggressive\Ads\Domain\Measurement_Rules;
use Aggressive\Ads\Domain\No_Fill_Reason;
use PHPUnit\Framework\TestCase;

/**
 * Validates canonical measurement event types, legacy alias normalization, no-fill mappings, and lifecycle transitions.
 */
final class MeasurementModelTest extends TestCase {

	/**
	 * Canonical event types vocabulary contains all lifecycle stages.
	 */
	public function test_all_canonical_event_types_defined(): void {
		$types = Measurement_Event_Type::all();

		$this->assertContains( Measurement_Event_Type::TYPE_REQUEST, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_FILL, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_NO_FILL, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_SERVED, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_VIEWABLE, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_CLICK, $types );
		$this->assertContains( Measurement_Event_Type::TYPE_CONVERSION, $types );
	}

	/**
	 * Normalization maps legacy impression to served and leaves canonical types unchanged.
	 */
	public function test_legacy_impression_normalizes_to_served(): void {
		$this->assertSame( Measurement_Event_Type::TYPE_SERVED, Measurement_Event_Type::normalize( 'impression' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_SERVED, Measurement_Event_Type::normalize( 'served' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_CLICK, Measurement_Event_Type::normalize( 'click' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_REQUEST, Measurement_Event_Type::normalize( 'request' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_FILL, Measurement_Event_Type::normalize( 'fill' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_NO_FILL, Measurement_Event_Type::normalize( 'no_fill' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_VIEWABLE, Measurement_Event_Type::normalize( 'viewable' ) );
		$this->assertSame( Measurement_Event_Type::TYPE_CONVERSION, Measurement_Event_Type::normalize( 'conversion' ) );
		$this->assertNull( Measurement_Event_Type::normalize( 'invalid_type' ) );
		$this->assertNull( Measurement_Event_Type::normalize( '' ) );
	}

	/**
	 * Checks validity of canonical events and legacy aliases.
	 */
	public function test_is_valid_checks(): void {
		$this->assertTrue( Measurement_Event_Type::is_valid( 'impression' ) );
		$this->assertTrue( Measurement_Event_Type::is_valid( 'served' ) );
		$this->assertTrue( Measurement_Event_Type::is_valid( 'click' ) );
		$this->assertFalse( Measurement_Event_Type::is_valid( 'unknown' ) );
	}

	/**
	 * No-fill taxonomy maps exclusion reasons to structured codes.
	 */
	public function test_no_fill_reasons_mapped_from_exclusion_reasons(): void {
		$this->assertSame( No_Fill_Reason::NO_CANDIDATES, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::NO_FILL ) );
		$this->assertSame( No_Fill_Reason::NO_CANDIDATES, No_Fill_Reason::from_exclusion_reason( '' ) );

		$this->assertSame( No_Fill_Reason::ALL_INELIGIBLE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::ELIGIBILITY_INVALID_CLICK_URL ) );
		$this->assertSame( No_Fill_Reason::ALL_INELIGIBLE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::ELIGIBILITY_MISSING_ATTACHMENT ) );
		$this->assertSame( No_Fill_Reason::ALL_INELIGIBLE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::ELIGIBILITY_INVALID_WEIGHT ) );

		$this->assertSame( No_Fill_Reason::SCHEDULE_EXCLUDED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::SCHEDULE_NOT_STARTED ) );
		$this->assertSame( No_Fill_Reason::SCHEDULE_EXCLUDED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::SCHEDULE_EXPIRED ) );
		$this->assertSame( No_Fill_Reason::SCHEDULE_EXCLUDED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::SCHEDULE_DAYPART_EXCLUDED ) );
		$this->assertSame( No_Fill_Reason::SCHEDULE_EXCLUDED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::SCHEDULE_INVALID_TIMEZONE ) );

		$this->assertSame( No_Fill_Reason::TARGETING_MISMATCH, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::TARGETING_EXCLUDED ) );
		$this->assertSame( No_Fill_Reason::FREQUENCY_CAPPED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::FREQUENCY_CAPPED ) );

		$this->assertSame( No_Fill_Reason::PACING_THROTTLED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_DAILY_CAP_REACHED ) );
		$this->assertSame( No_Fill_Reason::PACING_THROTTLED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_LIFETIME_CAP_REACHED ) );
		$this->assertSame( No_Fill_Reason::PACING_THROTTLED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_BEHIND_PACE ) );
		$this->assertSame( No_Fill_Reason::PACING_THROTTLED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_THROTTLED ) );
		$this->assertSame( No_Fill_Reason::PACING_THROTTLED, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_UNAVAILABLE ) );

		$this->assertSame( No_Fill_Reason::COMPETITIVE_EXCLUDE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PAGE_COMPETITIVE_SEPARATION ) );
		$this->assertSame( No_Fill_Reason::COMPETITIVE_EXCLUDE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PAGE_ROADBLOCK_INCOMPLETE ) );
		$this->assertSame( No_Fill_Reason::COMPETITIVE_EXCLUDE, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PAGE_DUPLICATE_ASSET ) );

		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::ELIGIBILITY_STAGE_ERROR ) );
		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::SCHEDULE_STAGE_ERROR ) );
		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::TARGETING_STAGE_ERROR ) );
		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::FREQUENCY_STAGE_ERROR ) );
		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PACING_STAGE_ERROR ) );
		$this->assertSame( No_Fill_Reason::PIPELINE_ERROR, No_Fill_Reason::from_exclusion_reason( Exclusion_Reason::PRIORITY_STAGE_ERROR ) );

		$this->assertSame( No_Fill_Reason::UNKNOWN, No_Fill_Reason::from_exclusion_reason( 'unrecognized_custom_code' ) );
	}

	/**
	 * Token and IP hash validator rules.
	 */
	public function test_hash_validators(): void {
		$valid_hex   = str_repeat( 'a', 64 );
		$invalid_hex = str_repeat( 'z', 64 );
		$short_hex   = str_repeat( 'a', 63 );

		$this->assertTrue( Measurement_Rules::is_valid_token_hash( $valid_hex ) );
		$this->assertFalse( Measurement_Rules::is_valid_token_hash( $invalid_hex ) );
		$this->assertFalse( Measurement_Rules::is_valid_token_hash( $short_hex ) );

		$this->assertTrue( Measurement_Rules::is_valid_ip_hash( $valid_hex ) );
		$this->assertFalse( Measurement_Rules::is_valid_ip_hash( $invalid_hex ) );
		$this->assertFalse( Measurement_Rules::is_valid_ip_hash( $short_hex ) );
	}

	/**
	 * Timestamp skew validator rules.
	 */
	public function test_timestamp_skew_validator(): void {
		$now = 1_000_000;

		$this->assertTrue( Measurement_Rules::is_valid_timestamp( $now, $now ) );
		$this->assertTrue( Measurement_Rules::is_valid_timestamp( $now - 3600, $now ) );
		$this->assertTrue( Measurement_Rules::is_valid_timestamp( $now + 3600, $now ) );
		$this->assertTrue( Measurement_Rules::is_valid_timestamp( $now - 86400, $now ) );

		// Greater than 24 hours skew.
		$this->assertFalse( Measurement_Rules::is_valid_timestamp( $now - 86401, $now ) );
		$this->assertFalse( Measurement_Rules::is_valid_timestamp( $now + 86401, $now ) );

		// Non-positive timestamps.
		$this->assertFalse( Measurement_Rules::is_valid_timestamp( 0, $now ) );
		$this->assertFalse( Measurement_Rules::is_valid_timestamp( -100, $now ) );
		$this->assertFalse( Measurement_Rules::is_valid_timestamp( $now, 0 ) );
	}

	/**
	 * Validates allowed lifecycle transitions.
	 */
	public function test_lifecycle_transitions(): void {
		// Request can transition to Fill or No_Fill.
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_REQUEST, Measurement_Event_Type::TYPE_FILL ) );
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_REQUEST, Measurement_Event_Type::TYPE_NO_FILL ) );
		$this->assertFalse( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_REQUEST, Measurement_Event_Type::TYPE_SERVED ) );

		// Fill transitions to Served (or legacy impression).
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_FILL, Measurement_Event_Type::TYPE_SERVED ) );
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_FILL, Measurement_Event_Type::LEGACY_IMPRESSION ) );
		$this->assertFalse( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_FILL, Measurement_Event_Type::TYPE_CLICK ) );

		// Served transitions to Viewable or Click.
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_SERVED, Measurement_Event_Type::TYPE_VIEWABLE ) );
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_SERVED, Measurement_Event_Type::TYPE_CLICK ) );
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::LEGACY_IMPRESSION, Measurement_Event_Type::TYPE_CLICK ) );
		$this->assertFalse( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_SERVED, Measurement_Event_Type::TYPE_FILL ) );

		// Viewable or Click can transition to Conversion.
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_VIEWABLE, Measurement_Event_Type::TYPE_CONVERSION ) );
		$this->assertTrue( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_CLICK, Measurement_Event_Type::TYPE_CONVERSION ) );

		// Invalid transitions.
		$this->assertFalse( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_CONVERSION, Measurement_Event_Type::TYPE_CLICK ) );
		$this->assertFalse( Measurement_Rules::is_valid_transition( 'unknown', Measurement_Event_Type::TYPE_CLICK ) );
		$this->assertFalse( Measurement_Rules::is_valid_transition( Measurement_Event_Type::TYPE_REQUEST, 'unknown' ) );
	}
}
