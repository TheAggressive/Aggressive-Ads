<?php
/**
 * The start and end dates, the "Runs through" line and the calendar.
 *
 * Creation's schedule, drawn by creation and by the running-campaign edit flow
 * alike. The package above it decides whether the end is asked for: a fixed
 * package sells a number of days, so its end is derived and stated, and only a
 * custom package asks for one. `@aggr/calendar` keeps that true as the package
 * changes.
 *
 * Scope is inherited from the step that requires it.
 *
 * @var string $aggr_sched_start        Start date, `Y-m-d` or empty.
 * @var string $aggr_sched_end          End date, `Y-m-d` or empty.
 * @var string $aggr_sched_min          Earliest start the field offers, or empty for none.
 * @var bool   $aggr_sched_fixed        Whether the selected package sets the length.
 * @var int    $aggr_sched_run_end      The derived last moment for a fixed package, or zero.
 * @var bool   $aggr_sched_locked       Whether the start has passed and cannot move.
 * @var bool   $aggr_sched_end_required Whether a custom schedule must have an end.
 * @var string $aggr_error_for          Field the current error belongs to.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Date_Input;

$aggr_sched_error_for = isset( $aggr_error_for ) ? (string) $aggr_error_for : '';
?>
<div class="aggr-formgrid">
	<div class="aggr-field">
		<label for="aggr-start-date"><?php esc_html_e( 'Start date', 'aggressive-ads' ); ?></label>
		<p id="aggr-start-hint" class="aggr-hint">
			<?php
			echo $aggr_sched_locked
				? esc_html__( 'It has already started, so the start stays as it is.', 'aggressive-ads' )
				: esc_html__( 'The campaign begins at the start of this day.', 'aggressive-ads' );
			?>
		</p>
		<div class="aggr-date-input">
		<input
			id="aggr-start-date"
			name="start_date"
			type="date"
			value="<?php echo esc_attr( $aggr_sched_start ); ?>"
			<?php echo '' !== $aggr_sched_min ? 'min="' . esc_attr( $aggr_sched_min ) . '"' : ''; ?>
			<?php echo $aggr_sched_locked ? 'readonly' : 'required'; ?>
			aria-describedby="aggr-start-hint aggr-run-through<?php echo 'aggr-start-date' === $aggr_sched_error_for ? ' aggr-campaign-error' : ''; ?>"
			<?php echo 'aggr-start-date' === $aggr_sched_error_for ? 'aria-invalid="true"' : ''; ?>
		>
			<span class="aggr-date-input__note" aria-hidden="true" data-aggr-zone-note data-aggr-edge="start" data-aggr-for="aggr-start-date" data-aggr-zone="<?php echo esc_attr( wp_timezone_string() ); ?>"><?php echo esc_html( Date_Input::edge_label( $aggr_sched_start, false ) ); ?></span>
		</div>
	</div>

	<?php
	/*
	 * Rendered for every package and disabled for a fixed one, rather than
	 * left out. A disabled control is not posted, so the server derives the
	 * end; and switching to a custom package only has to enable it, not build
	 * a field the page never had.
	 */
	?>
	<div class="aggr-field" data-aggr-end-field <?php echo $aggr_sched_fixed ? 'hidden' : ''; ?>>
		<label for="aggr-end-date"><?php esc_html_e( 'End date', 'aggressive-ads' ); ?></label>
		<p id="aggr-end-hint" class="aggr-hint"><?php esc_html_e( 'The campaign runs through the end of this day.', 'aggressive-ads' ); ?></p>
		<div class="aggr-date-input">
		<input
			id="aggr-end-date"
			name="end_date"
			type="date"
			value="<?php echo esc_attr( $aggr_sched_end ); ?>"
			aria-describedby="aggr-end-hint<?php echo 'aggr-end-date' === $aggr_sched_error_for ? ' aggr-campaign-error' : ''; ?>"
			<?php
			if ( $aggr_sched_fixed ) {
				echo 'disabled';
			} elseif ( $aggr_sched_end_required ) {
				echo 'required';
			}
			?>
			<?php echo 'aggr-end-date' === $aggr_sched_error_for ? 'aria-invalid="true"' : ''; ?>
		>
			<span class="aggr-date-input__note" aria-hidden="true" data-aggr-zone-note data-aggr-edge="end" data-aggr-for="aggr-end-date" data-aggr-fallback="aggr-start-date" data-aggr-zone="<?php echo esc_attr( wp_timezone_string() ); ?>"><?php echo esc_html( Date_Input::edge_label( '' !== $aggr_sched_end ? $aggr_sched_end : $aggr_sched_start, true ) ); ?></span>
		</div>
	</div>
</div>

<p
	id="aggr-run-through"
	class="aggr-hint"
	data-aggr-run-through
	data-aggr-template="<?php /* translators: %s: the campaign's last day, e.g. October 30, 2026. */ esc_attr_e( 'Runs through %s.', 'aggressive-ads' ); ?>"
	aria-live="polite"
	<?php echo 0 === $aggr_sched_run_end ? 'hidden' : ''; ?>
>
	<?php
	if ( $aggr_sched_run_end > 0 ) {
		printf(
			/* translators: %s: the campaign's last day, e.g. October 30, 2026. */
			esc_html__( 'Runs through %s.', 'aggressive-ads' ),
			esc_html( (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $aggr_sched_run_end ) )
		);
	}
	?>
</p>

<?php
$aggr_cal_start_id = 'aggr-start-date';
$aggr_cal_end_id   = 'aggr-end-date';
$aggr_cal_start    = $aggr_sched_start;
$aggr_cal_end      = $aggr_sched_run_end > 0 ? (string) wp_date( 'Y-m-d', $aggr_sched_run_end, wp_timezone() ) : $aggr_sched_end;
$aggr_cal_min      = $aggr_sched_min;
$aggr_cal_fixed    = $aggr_sched_fixed;
$aggr_cal_locked   = $aggr_sched_locked;

require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-calendar.php';
