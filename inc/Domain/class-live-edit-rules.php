<?php
/**
 * What may change on a running campaign, and whether a proposal is coherent.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Domain;

/**
 * Pure rules for advertiser-proposed changes to a scheduled, live or paused
 * campaign. No WordPress, so every branch here is testable in milliseconds.
 *
 * Two jobs, deliberately separate.
 *
 * `diff()` reduces a proposal to what actually differs from the campaign as it
 * stands. That is not a nicety: an unchanged field submitted alongside a
 * changed one would otherwise be re-approved on every round, and a "change"
 * containing nothing at all would create review work out of a stray click.
 *
 * `validate()` then judges only that reduced set. A proposal is never trusted
 * to say which fields it is allowed to touch — the allowlist comes from
 * settings, and anything outside it is dropped before validation, not
 * reported as an error. Reporting it would tell an advertiser which fields
 * exist behind a switch the site owner turned off.
 */
final class Live_Edit_Rules {

	public const ERROR_NOTHING_CHANGED  = 'live_edit_nothing_changed';
	public const ERROR_TITLE_EMPTY      = 'live_edit_title_empty';
	public const ERROR_TITLE_LONG       = 'live_edit_title_too_long';
	public const ERROR_NOTES_LONG       = 'live_edit_notes_too_long';
	public const ERROR_START_LOCKED     = 'live_edit_start_locked';
	public const ERROR_END_BEFORE_START = 'live_edit_end_before_start';
	public const ERROR_END_IN_PAST      = 'live_edit_end_in_past';
	public const ERROR_URL_INVALID      = 'live_edit_click_url_invalid';
	public const ERROR_NO_PLACEMENTS    = 'live_edit_no_placements';

	public const MAX_TITLE = 200;
	public const MAX_NOTES = 2000;

	/**
	 * Fields carried by each settings key, so one switch governs one idea.
	 *
	 * `schedule` covers both timestamps because a start and an end are one
	 * decision; letting a site allow one without the other would produce
	 * windows nobody chose.
	 *
	 * @return array<string, list<string>>
	 */
	public static function fields_for(): array {
		return array(
			Settings_Schema::EDIT_TITLE       => array( 'title' ),
			Settings_Schema::EDIT_NOTES       => array( 'advertiser_notes' ),
			Settings_Schema::EDIT_SCHEDULE    => array( 'start_ts', 'end_ts' ),
			Settings_Schema::EDIT_DESTINATION => array( 'click_urls' ),
			Settings_Schema::EDIT_PLACEMENTS  => array( 'placement_ids' ),
		);
	}

	/**
	 * The field names unlocked by an allowlist of settings keys.
	 *
	 * @param array<int, string> $allowed Enabled Settings_Schema::edit_keys().
	 * @return list<string>
	 */
	public static function allowed_fields( array $allowed ): array {
		$fields = array();

		foreach ( self::fields_for() as $key => $names ) {
			if ( in_array( $key, $allowed, true ) ) {
				$fields = array_merge( $fields, $names );
			}
		}

		return $fields;
	}

	/**
	 * Whether an approved change would leave the existing creative unusable.
	 *
	 * @param array<string, mixed> $diff Reduced change set.
	 */
	public static function is_structural( array $diff ): bool {
		return array_key_exists( 'placement_ids', $diff );
	}

	/**
	 * Reduces a proposal to the allowed fields whose value actually differs.
	 *
	 * @param array<int, string>   $allowed  Enabled Settings_Schema::edit_keys().
	 * @param array<string, mixed> $current  The campaign as it stands.
	 * @param array<string, mixed> $proposed Advertiser input.
	 * @return array<string, mixed> Only changed, allowed fields.
	 */
	public static function diff( array $allowed, array $current, array $proposed ): array {
		$fields = self::allowed_fields( $allowed );
		$diff   = array();

		foreach ( $fields as $field ) {
			if ( ! array_key_exists( $field, $proposed ) ) {
				continue;
			}

			$next = self::normalize( $field, $proposed[ $field ] );
			$now  = self::normalize( $field, $current[ $field ] ?? null );

			if ( $next !== $now ) {
				$diff[ $field ] = $next;
			}
		}

		return $diff;
	}

	/**
	 * Judges a reduced change set.
	 *
	 * @param array<string, mixed> $diff    Output of diff().
	 * @param array<string, mixed> $current The campaign as it stands.
	 * @param int                  $now     Current time, UTC Unix seconds.
	 * @return Validation_Result
	 */
	public static function validate( array $diff, array $current, int $now ): Validation_Result {
		$result = new Validation_Result();

		if ( array() === $diff ) {
			$result->add( self::ERROR_NOTHING_CHANGED );

			return $result;
		}

		if ( array_key_exists( 'title', $diff ) ) {
			$title = is_string( $diff['title'] ) ? $diff['title'] : '';

			if ( '' === $title ) {
				$result->add( self::ERROR_TITLE_EMPTY, 'title' );
			} elseif ( strlen( $title ) > self::MAX_TITLE ) {
				$result->add( self::ERROR_TITLE_LONG, 'title' );
			}
		}

		if ( array_key_exists( 'advertiser_notes', $diff ) ) {
			$notes = is_string( $diff['advertiser_notes'] ) ? $diff['advertiser_notes'] : '';

			if ( strlen( $notes ) > self::MAX_NOTES ) {
				$result->add( self::ERROR_NOTES_LONG, 'advertiser_notes' );
			}
		}

		self::validate_schedule( $diff, $current, $now, $result );

		if ( array_key_exists( 'click_urls', $diff ) ) {
			$urls = is_array( $diff['click_urls'] ) ? $diff['click_urls'] : array();

			foreach ( $urls as $creative_id => $url ) {
				if ( ! is_string( $url ) || ! Campaign_Rules::is_valid_click_url( $url ) ) {
					$result->add( self::ERROR_URL_INVALID, 'click_urls', array( 'creative_id' => (int) $creative_id ) );
				}
			}
		}

		if ( array_key_exists( 'placement_ids', $diff ) ) {
			$ids = is_array( $diff['placement_ids'] ) ? $diff['placement_ids'] : array();

			if ( array() === $ids ) {
				$result->add( self::ERROR_NO_PLACEMENTS, 'placement_ids' );
			}
		}

		return $result;
	}

	/**
	 * Schedule rules for a campaign that is already running.
	 *
	 * These are not `Campaign_Rules::validate_window()`, and the difference is
	 * the point. That function requires a start in the future, which is correct
	 * for a campaign being submitted and wrong for one that started three weeks
	 * ago: applied here it would reject every change to a live campaign,
	 * including ones that do not touch the start at all.
	 *
	 * So a start that has already passed is immovable rather than invalid, and
	 * the end is judged against now instead of against submission.
	 *
	 * @param array<string, mixed> $diff    Reduced change set.
	 * @param array<string, mixed> $current The campaign as it stands.
	 * @param int                  $now     Current time, UTC Unix seconds.
	 * @param Validation_Result    $result  Collector.
	 * @return void
	 */
	private static function validate_schedule( array $diff, array $current, int $now, Validation_Result $result ): void {
		$current_start = isset( $current['start_ts'] ) ? (int) $current['start_ts'] : 0;
		$start         = array_key_exists( 'start_ts', $diff ) ? (int) $diff['start_ts'] : $current_start;

		if ( array_key_exists( 'start_ts', $diff ) && $current_start > 0 && $current_start <= $now ) {
			$result->add( self::ERROR_START_LOCKED, 'start_ts', array( 'start_ts' => $current_start ) );
		}

		if ( ! array_key_exists( 'end_ts', $diff ) ) {
			return;
		}

		$end = (int) $diff['end_ts'];

		// Zero stays open-ended, exactly as it does at submission.
		if ( 0 === $end ) {
			return;
		}

		if ( $end <= $start ) {
			$result->add(
				self::ERROR_END_BEFORE_START,
				'end_ts',
				array(
					'start_ts' => $start,
					'end_ts'   => $end,
				)
			);
		}

		// An end already behind us would complete the campaign the moment it
		// was approved. If that is what somebody wants, cancelling says so.
		if ( $end <= $now ) {
			$result->add( self::ERROR_END_IN_PAST, 'end_ts', array( 'end_ts' => $end ) );
		}
	}

	/**
	 * Canonical form for comparison, so "no change" is not a formatting
	 * accident. Placement lists compare as sets: reordering is not an edit.
	 *
	 * @param string $field Field name.
	 * @param mixed  $value Raw value.
	 * @return mixed
	 */
	private static function normalize( string $field, mixed $value ): mixed {
		switch ( $field ) {
			case 'title':
			case 'advertiser_notes':
				return is_string( $value ) ? trim( $value ) : '';

			case 'start_ts':
			case 'end_ts':
				return is_numeric( $value ) ? (int) $value : 0;

			case 'placement_ids':
				$ids = is_array( $value ) ? array_map( 'intval', $value ) : array();
				$ids = array_values( array_unique( array_filter( $ids, static fn ( int $id ): bool => $id > 0 ) ) );
				sort( $ids );

				return $ids;

			case 'click_urls':
				$urls = array();

				if ( is_array( $value ) ) {
					foreach ( $value as $creative_id => $url ) {
						$id = (int) $creative_id;

						if ( $id > 0 ) {
							$urls[ $id ] = is_string( $url ) ? trim( $url ) : '';
						}
					}
				}

				ksort( $urls );

				return $urls;
		}

		return $value;
	}
}
