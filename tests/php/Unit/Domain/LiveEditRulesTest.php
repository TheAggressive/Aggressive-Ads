<?php
/**
 * Rules for advertiser-proposed changes to a running campaign.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Tests\Unit\Domain;

use Aggressive\Ads\Domain\Live_Edit_Rules;
use Aggressive\Ads\Domain\Settings_Schema;
use PHPUnit\Framework\TestCase;

/**
 * The allowlist is a permission boundary, so it is asserted as one: a field
 * the site owner did not enable must not survive into the change set no matter
 * how it is spelled in the request.
 */
final class LiveEditRulesTest extends TestCase {

	private const NOW = 1800000000;

	/**
	 * A running campaign to diff against.
	 *
	 * @return array<string, mixed>
	 */
	private function current(): array {
		return array(
			'title'            => 'Autumn flight',
			'advertiser_notes' => 'Original notes',
			'start_ts'         => self::NOW - 86400,
			'end_ts'           => self::NOW + 86400,
			'placement_ids'    => array( 7, 3 ),
			'click_urls'       => array( 11 => 'https://example.com/a' ),
		);
	}

	/**
	 * Every settings key in one list, for tests about content rather than gating.
	 *
	 * @return array<int, string>
	 */
	private function all_allowed(): array {
		return Settings_Schema::edit_keys();
	}

	/**
	 * A disabled field is dropped, not reported — the advertiser learns
	 * nothing about switches the site owner turned off.
	 *
	 * @return void
	 */
	public function test_fields_outside_the_allowlist_are_dropped(): void {
		$diff = Live_Edit_Rules::diff(
			array( Settings_Schema::EDIT_TITLE ),
			$this->current(),
			array(
				'title'         => 'Renamed',
				'start_ts'      => self::NOW + 999,
				'placement_ids' => array( 42 ),
			)
		);

		$this->assertSame( array( 'title' => 'Renamed' ), $diff );
	}

	/**
	 * An empty allowlist is the feature switched off.
	 *
	 * @return void
	 */
	public function test_an_empty_allowlist_permits_nothing(): void {
		$diff = Live_Edit_Rules::diff( array(), $this->current(), array( 'title' => 'Renamed' ) );

		$this->assertSame( array(), $diff );
		$this->assertTrue( Live_Edit_Rules::validate( $diff, $this->current(), self::NOW )->has( Live_Edit_Rules::ERROR_NOTHING_CHANGED ) );
	}

	/**
	 * Resubmitting the same values is not a change.
	 *
	 * @return void
	 */
	public function test_unchanged_values_do_not_enter_the_diff(): void {
		$current = $this->current();

		$diff = Live_Edit_Rules::diff(
			$this->all_allowed(),
			$current,
			array(
				'title'            => 'Autumn flight',
				'advertiser_notes' => 'Original notes',
				'end_ts'           => $current['end_ts'],
			)
		);

		$this->assertSame( array(), $diff );
	}

	/**
	 * Whitespace and placement order are formatting, not edits.
	 *
	 * @return void
	 */
	public function test_normalization_prevents_cosmetic_changes(): void {
		$diff = Live_Edit_Rules::diff(
			$this->all_allowed(),
			$this->current(),
			array(
				'title'         => '  Autumn flight  ',
				'placement_ids' => array( 3, 7, 7 ),
			)
		);

		$this->assertSame( array(), $diff );
	}

	/**
	 * A real change survives.
	 *
	 * @return void
	 */
	public function test_a_changed_value_is_kept(): void {
		$diff = Live_Edit_Rules::diff(
			$this->all_allowed(),
			$this->current(),
			array( 'end_ts' => self::NOW + 604800 )
		);

		$this->assertSame( array( 'end_ts' => self::NOW + 604800 ), $diff );
		$this->assertTrue( Live_Edit_Rules::validate( $diff, $this->current(), self::NOW )->is_valid() );
	}

	/**
	 * A start already in the past cannot be moved.
	 *
	 * @return void
	 */
	public function test_a_started_campaign_cannot_move_its_start(): void {
		$diff   = Live_Edit_Rules::diff( $this->all_allowed(), $this->current(), array( 'start_ts' => self::NOW + 3600 ) );
		$result = Live_Edit_Rules::validate( $diff, $this->current(), self::NOW );

		$this->assertTrue( $result->has( Live_Edit_Rules::ERROR_START_LOCKED ) );
	}

	/**
	 * A campaign that has not started yet may still move its start.
	 *
	 * @return void
	 */
	public function test_a_scheduled_campaign_may_move_its_start(): void {
		$current             = $this->current();
		$current['start_ts'] = self::NOW + 86400;

		$diff   = Live_Edit_Rules::diff( $this->all_allowed(), $current, array( 'start_ts' => self::NOW + 172800 ) );
		$result = Live_Edit_Rules::validate( $diff, $current, self::NOW );

		$this->assertSame( array( 'start_ts' => self::NOW + 172800 ), $diff );
		$this->assertFalse( $result->has( Live_Edit_Rules::ERROR_START_LOCKED ) );
	}

	/**
	 * Submission-time window rules would reject every live edit, so they are
	 * not the rules used here. Changing only the end of a campaign that began
	 * yesterday must be valid.
	 *
	 * @return void
	 */
	public function test_a_past_start_does_not_invalidate_an_end_only_change(): void {
		$diff   = Live_Edit_Rules::diff( $this->all_allowed(), $this->current(), array( 'end_ts' => self::NOW + 604800 ) );
		$result = Live_Edit_Rules::validate( $diff, $this->current(), self::NOW );

		$this->assertTrue( $result->is_valid(), 'A live campaign must be able to extend its end date.' );
	}

	/**
	 * An end behind the start, or behind now, is refused.
	 *
	 * @return void
	 */
	public function test_incoherent_end_dates_are_refused(): void {
		$before_start = Live_Edit_Rules::validate(
			array( 'end_ts' => self::NOW - 172800 ),
			$this->current(),
			self::NOW
		);

		$this->assertTrue( $before_start->has( Live_Edit_Rules::ERROR_END_BEFORE_START ) );
		$this->assertTrue( $before_start->has( Live_Edit_Rules::ERROR_END_IN_PAST ) );
	}

	/**
	 * Open-ended stays legal.
	 *
	 * @return void
	 */
	public function test_a_zero_end_is_open_ended(): void {
		$result = Live_Edit_Rules::validate( array( 'end_ts' => 0 ), $this->current(), self::NOW );

		$this->assertTrue( $result->is_valid() );
	}

	/**
	 * Destinations are validated per creative.
	 *
	 * @return void
	 */
	public function test_destination_urls_are_validated(): void {
		$bad = Live_Edit_Rules::validate(
			array( 'click_urls' => array( 11 => 'javascript:alert(1)' ) ),
			$this->current(),
			self::NOW
		);

		$this->assertTrue( $bad->has( Live_Edit_Rules::ERROR_URL_INVALID ) );

		$good = Live_Edit_Rules::validate(
			array( 'click_urls' => array( 11 => 'https://example.com/b' ) ),
			$this->current(),
			self::NOW
		);

		$this->assertTrue( $good->is_valid() );
	}

	/**
	 * An empty title or placement list is refused.
	 *
	 * @return void
	 */
	public function test_empty_required_values_are_refused(): void {
		$this->assertTrue(
			Live_Edit_Rules::validate( array( 'title' => '' ), $this->current(), self::NOW )->has( Live_Edit_Rules::ERROR_TITLE_EMPTY )
		);

		$this->assertTrue(
			Live_Edit_Rules::validate( array( 'placement_ids' => array() ), $this->current(), self::NOW )->has( Live_Edit_Rules::ERROR_NO_PLACEMENTS )
		);
	}

	/**
	 * Only a placement change invalidates the creative.
	 *
	 * @return void
	 */
	public function test_only_placement_changes_are_structural(): void {
		$this->assertTrue( Live_Edit_Rules::is_structural( array( 'placement_ids' => array( 3 ) ) ) );
		$this->assertFalse(
			Live_Edit_Rules::is_structural(
				array(
					'title'  => 'x',
					'end_ts' => 1,
				)
			)
		);
	}
}
