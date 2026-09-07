<?php
/**
 * Table definitions for commercial planning.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Install;

/**
 * The tables P16 introduced: what a publisher expects to have, and who has
 * claimed it.
 *
 * **Split out of `Schema` when that file crossed the length gate, and by
 * responsibility rather than by line count.** These two describe commercial
 * planning — staff-only figures about inventory that has not been delivered
 * yet — where everything left in `Schema` describes delivery, measurement or
 * identity. A reader asking "what does this site record about what happened"
 * and one asking "what has been promised" are different readers.
 *
 * The names and `DB_VERSION` stay in `Schema`, which remains the one place to
 * answer "what tables exist". Only the definitions moved.
 */
final class Planning_Schema {

	/**
	 * Columns `aggr_forecasts` must have.
	 *
	 * Declared beside the DDL so a schema test can compare what the site really
	 * built against what this file says it should be. `dbDelta` is forgiving —
	 * it adds and never drops — so a table that drifted from its definition
	 * stays working and wrong until something asserts the difference.
	 *
	 * @return list<string>
	 */
	public static function forecasts_columns(): array {
		return array(
			'id',
			'placement_id',
			'opportunity',
			'window_start',
			'window_end',
			'version',
			'estimate',
			'optimistic',
			'confidence',
			'days_observed',
			'days_forecast',
			'made_at',
			'actual',
			'actual_at',
		);
	}

	/**
	 * Index names `aggr_forecasts` must have, in the order MySQL reports them.
	 *
	 * @return list<string>
	 */
	public static function forecasts_index_names(): array {
		return array( 'PRIMARY', 'slot_window_version', 'slot_made', 'maturing' );
	}

	/**
	 * Columns `aggr_reservations` must have.
	 *
	 * @return list<string>
	 */
	public static function reservations_columns(): array {
		return array(
			'id',
			'placement_id',
			'opportunity',
			'window_start',
			'window_end',
			'campaign_id',
			'org_id',
			'quantity',
			'status',
			'forecast_version',
			'created_at',
			'updated_at',
		);
	}

	/**
	 * Index names `aggr_reservations` must have, in the order MySQL reports them.
	 *
	 * `slot_window_status` is the one capacity is summed over, so a test that
	 * catches its loss catches a booking check degrading into a table scan
	 * rather than failing outright.
	 *
	 * @return list<string>
	 */
	public static function reservations_index_names(): array {
		return array( 'PRIMARY', 'slot_window_status', 'campaign', 'org_window' );
	}

	/**
	 * Versioned forecast snapshots.
	 *
	 * **A forecast is a claim made at a moment, and the moment is the point.**
	 * Re-forecasting the same window writes a new row rather than editing the
	 * old one, so what a publisher was told in March survives being told
	 * something else in April — which is the only way the error recorded
	 * against the March figure means anything.
	 *
	 * `actual` is the one column written after insert, once and only once. The
	 * forecast half never changes; the outcome is appended when the window has
	 * matured. That is the whole of the mutability this table allows, and
	 * `Forecast_Repository::record_actual()` is what holds the line.
	 *
	 * The unique key is what makes a version a version: two rows cannot claim
	 * the same version of the same window, so a concurrent re-forecast loses
	 * the insert rather than silently overwriting a snapshot somebody has
	 * already quoted.
	 *
	 * `opportunity` and the window are `varchar(8)`/`date` to match
	 * `aggr_decision_rollups`, which is the table this is forecast from — a
	 * grain that disagreed with its own source would be forecasting one thing
	 * and measuring another.
	 *
	 * @param string $table_name      Prefixed table name.
	 * @param string $charset_collate Site charset and collation.
	 */
	public static function forecasts_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	opportunity varchar(8) NOT NULL DEFAULT 'page',
	window_start date NOT NULL,
	window_end date NOT NULL,
	version smallint(5) unsigned NOT NULL DEFAULT 1,
	estimate bigint(20) unsigned NOT NULL DEFAULT 0,
	optimistic bigint(20) unsigned NOT NULL DEFAULT 0,
	confidence varchar(8) NOT NULL DEFAULT 'none',
	days_observed smallint(5) unsigned NOT NULL DEFAULT 0,
	days_forecast smallint(5) unsigned NOT NULL DEFAULT 0,
	made_at datetime NOT NULL,
	actual bigint(20) unsigned NULL DEFAULT NULL,
	actual_at datetime NULL DEFAULT NULL,
	PRIMARY KEY  (id),
	UNIQUE KEY slot_window_version (placement_id,opportunity,window_start,window_end,version),
	KEY slot_made (placement_id,made_at),
	KEY maturing (actual,window_end)
) {$charset_collate};";
	}

	/**
	 * Time-bounded claims against a placement's forecast supply.
	 *
	 * **`status` is the column capacity is summed over**, so it carries the
	 * index that read depends on. `Domain\Reservation_Rules` decides which
	 * values consume inventory; the table only has to make counting them cheap
	 * for one placement and window, which `slot_window_status` does.
	 *
	 * `forecast_version` records which snapshot the claim was checked against.
	 * The contract requires an oversell override to name a forecast version,
	 * and a reservation that could not say which figure it was approved
	 * against would make that impossible to reconstruct afterwards — a
	 * publisher would know somebody overrode a warning and not what the warning
	 * said.
	 *
	 * `org_id` is stored rather than resolved from the campaign, so a tenancy
	 * check is a predicate on this table instead of a join to posts that a
	 * later query might forget to make.
	 *
	 * @param string $table_name      Prefixed table name.
	 * @param string $charset_collate Site charset and collation.
	 */
	public static function reservations_table_ddl( string $table_name, string $charset_collate ): string {
		return "CREATE TABLE {$table_name} (
	id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
	placement_id bigint(20) unsigned NOT NULL DEFAULT 0,
	opportunity varchar(8) NOT NULL DEFAULT 'page',
	window_start date NOT NULL,
	window_end date NOT NULL,
	campaign_id bigint(20) unsigned NOT NULL DEFAULT 0,
	org_id bigint(20) unsigned NOT NULL DEFAULT 0,
	quantity bigint(20) unsigned NOT NULL DEFAULT 0,
	status varchar(20) NOT NULL DEFAULT 'held',
	forecast_version smallint(5) unsigned NOT NULL DEFAULT 0,
	created_at datetime NOT NULL,
	updated_at datetime NOT NULL,
	PRIMARY KEY  (id),
	KEY slot_window_status (placement_id,opportunity,window_start,window_end,status),
	KEY campaign (campaign_id),
	KEY org_window (org_id,window_end)
) {$charset_collate};";
	}
}
