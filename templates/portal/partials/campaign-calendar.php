<?php
/**
 * A range calendar for the date fields beside it, with quick picks.
 *
 * The date fields are what is submitted. Without script this draws the range
 * they hold, hidden from assistive technology because it is a picture, not a
 * second way to choose; the quick picks and month buttons are not shown at
 * all. `@aggr/calendar` swaps the picture for grids of day buttons that write
 * into the fields, in the same box, so nothing moves when it attaches.
 *
 * It never marks availability. That needs the forecast behind it, and a
 * made-up "sold out" day is worse than none.
 *
 * Scope is inherited from the including step.
 *
 * @var string $aggr_cal_start_id Id of the start date input.
 * @var string $aggr_cal_end_id   Id of the end date input.
 * @var string $aggr_cal_start    Start date the field holds, `Y-m-d` or empty.
 * @var string $aggr_cal_end      End date: chosen, or derived for a fixed run.
 * @var string $aggr_cal_min      Earliest day that may be chosen, `Y-m-d` or empty for today.
 * @var bool   $aggr_cal_fixed    Whether a package sets the length.
 * @var bool   $aggr_cal_locked   Whether the start has passed and cannot move.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$aggr_cal_zone       = wp_timezone();
$aggr_cal_today      = (string) wp_date( 'Y-m-d', null, $aggr_cal_zone );
$aggr_cal_min        = '' !== $aggr_cal_min ? $aggr_cal_min : $aggr_cal_today;
$aggr_cal_week_start = min( 6, max( 0, (int) get_option( 'start_of_week', 0 ) ) );

$aggr_cal_first = '' !== $aggr_cal_start ? DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_start, $aggr_cal_zone ) : false;
$aggr_cal_first = false === $aggr_cal_first ? DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_min, $aggr_cal_zone ) : $aggr_cal_first;
$aggr_cal_first = false === $aggr_cal_first ? new DateTimeImmutable( 'now', $aggr_cal_zone ) : $aggr_cal_first;
$aggr_cal_first = $aggr_cal_first->modify( 'first day of this month' )->setTime( 0, 0 );
$aggr_cal_names = array();

for ( $aggr_cal_i = 0; $aggr_cal_i < 7; $aggr_cal_i++ ) {
	$aggr_cal_names[] = $wp_locale instanceof WP_Locale
		? (string) $wp_locale->get_weekday_initial( $wp_locale->get_weekday( ( $aggr_cal_i + $aggr_cal_week_start ) % 7 ) )
		: array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' )[ ( $aggr_cal_i + $aggr_cal_week_start ) % 7 ];
}

$aggr_cal_span = 0;

if ( '' !== $aggr_cal_start && '' !== $aggr_cal_end ) {
	$aggr_cal_from = DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_start, $aggr_cal_zone );
	$aggr_cal_to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_end, $aggr_cal_zone );

	if ( false !== $aggr_cal_from && false !== $aggr_cal_to && $aggr_cal_to >= $aggr_cal_from ) {
		$aggr_cal_span = (int) $aggr_cal_from->diff( $aggr_cal_to )->days + 1;
	}
}

/*
 * One and many, as the browser needs them. It picks between the two with
 * Intl.PluralRules; a language with more forms than two gets its "many" form
 * for every count above one, which reads acceptably and is the limit of what
 * a data attribute can carry.
 */
/* translators: %s: number of days between the start and end dates, inclusive. */
$aggr_cal_days_one = _n( '%s day selected', '%s days selected', 1, 'aggressive-ads' );
/* translators: %s: number of days between the start and end dates, inclusive. */
$aggr_cal_days_other = _n( '%s day selected', '%s days selected', 2, 'aggressive-ads' );
$aggr_cal_hint_id    = $aggr_cal_start_id . '-calendar-hint';
?>
<div
	class="aggr-calendar-picker"
	data-aggr-calendar
	data-aggr-start="<?php echo esc_attr( $aggr_cal_start_id ); ?>"
	data-aggr-end="<?php echo esc_attr( $aggr_cal_end_id ); ?>"
	data-aggr-today="<?php echo esc_attr( $aggr_cal_today ); ?>"
	data-aggr-min="<?php echo esc_attr( $aggr_cal_min ); ?>"
	data-aggr-week-start="<?php echo esc_attr( (string) $aggr_cal_week_start ); ?>"
	data-aggr-months="2"
	data-aggr-start-locked="<?php echo $aggr_cal_locked ? '1' : '0'; ?>"
	data-aggr-label-start="<?php esc_attr_e( 'first day', 'aggressive-ads' ); ?>"
	data-aggr-label-end="<?php esc_attr_e( 'last day', 'aggressive-ads' ); ?>"
	data-aggr-label-unavailable="<?php esc_attr_e( 'not available', 'aggressive-ads' ); ?>"
	data-aggr-label-range="<?php /* translators: 1: first day, e.g. Friday, September 18, 2026. 2: last day. */ esc_attr_e( 'Runs from %1$s through %2$s.', 'aggressive-ads' ); ?>"
	data-aggr-label-open="<?php /* translators: %s: first day, e.g. Friday, September 18, 2026. */ esc_attr_e( 'Starts %s. Choose an end date, or leave it open to run until it is ended.', 'aggressive-ads' ); ?>"
	data-aggr-label-fixed="<?php /* translators: 1: first day, e.g. Friday, September 18, 2026. 2: last day. */ esc_attr_e( 'Starts %1$s and runs through %2$s.', 'aggressive-ads' ); ?>"
	data-aggr-label-days-one="<?php echo esc_attr( $aggr_cal_days_one ); ?>"
	data-aggr-label-days-other="<?php echo esc_attr( $aggr_cal_days_other ); ?>"
>
	<div class="aggr-presets aggr-script-only" role="group" aria-label="<?php esc_attr_e( 'Quick picks', 'aggressive-ads' ); ?>">
		<?php
		// Disabled until the module attaches, so a page whose script failed offers nothing that does nothing.
		$aggr_cal_presets = array(
			'today'     => array( __( 'Starts today', 'aggressive-ads' ), $aggr_cal_locked ),
			'monday'    => array( __( 'Next Monday', 'aggressive-ads' ), $aggr_cal_locked ),
			'two-weeks' => array( __( '2 weeks', 'aggressive-ads' ), $aggr_cal_fixed ),
			'month'     => array( __( 'This month', 'aggressive-ads' ), $aggr_cal_fixed ),
			'open'      => array( __( 'No end date', 'aggressive-ads' ), $aggr_cal_fixed ),
		);
		?>
		<?php foreach ( $aggr_cal_presets as $aggr_cal_preset => $aggr_cal_preset_def ) : ?>
			<button type="button" data-aggr-preset="<?php echo esc_attr( $aggr_cal_preset ); ?>" disabled <?php echo $aggr_cal_preset_def[1] ? 'hidden' : ''; ?>><?php echo esc_html( $aggr_cal_preset_def[0] ); ?></button>
		<?php endforeach; ?>
	</div>

	<div class="aggr-calendar__bar">
		<p class="aggr-calendar__span" data-aggr-calendar-span <?php echo 0 === $aggr_cal_span ? 'hidden' : ''; ?>>
			<?php echo esc_html( sprintf( 1 === $aggr_cal_span ? $aggr_cal_days_one : $aggr_cal_days_other, number_format_i18n( $aggr_cal_span ) ) ); ?>
		</p>
		<div class="aggr-calendar__nav aggr-script-only">
			<button class="aggr-calendar__turn" type="button" data-aggr-calendar-prev disabled aria-label="<?php esc_attr_e( 'Previous month', 'aggressive-ads' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m15 18-6-6 6-6"/></svg>
			</button>
			<button class="aggr-calendar__turn" type="button" data-aggr-calendar-next disabled aria-label="<?php esc_attr_e( 'Next month', 'aggressive-ads' ); ?>">
				<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="m9 18 6-6-6-6"/></svg>
			</button>
		</div>
	</div>

	<p id="<?php echo esc_attr( $aggr_cal_hint_id ); ?>" class="aggr-sr" data-aggr-calendar-hint><?php esc_html_e( 'Arrow keys move between days. Page Up and Page Down change the month.', 'aggressive-ads' ); ?></p>

	<div class="aggr-calendar" data-aggr-calendar-grids aria-hidden="true">
		<?php foreach ( array( $aggr_cal_first, $aggr_cal_first->modify( '+1 month' ) ) as $aggr_cal_month ) : ?>
			<?php
			$aggr_cal_blanks = ( (int) $aggr_cal_month->format( 'w' ) - $aggr_cal_week_start + 7 ) % 7;
			$aggr_cal_days   = (int) $aggr_cal_month->format( 't' );
			$aggr_cal_cells  = array_merge( array_fill( 0, $aggr_cal_blanks, 0 ), range( 1, $aggr_cal_days ) );
			$aggr_cal_cells  = array_merge( $aggr_cal_cells, array_fill( 0, ( 7 - count( $aggr_cal_cells ) % 7 ) % 7, 0 ) );
			?>
			<div class="aggr-calendar__month">
				<table class="aggr-calendar__table">
					<caption class="aggr-calendar__name"><?php echo esc_html( (string) wp_date( 'F Y', $aggr_cal_month->getTimestamp(), $aggr_cal_zone ) ); ?></caption>
					<thead>
						<tr>
							<?php foreach ( $aggr_cal_names as $aggr_cal_name ) : ?>
								<th class="aggr-calendar__weekday" scope="col"><?php echo esc_html( $aggr_cal_name ); ?></th>
							<?php endforeach; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( array_chunk( $aggr_cal_cells, 7 ) as $aggr_cal_week ) : ?>
							<tr>
								<?php foreach ( $aggr_cal_week as $aggr_cal_d ) : ?>
									<?php
									if ( 0 === $aggr_cal_d ) {
										echo '<td></td>';
										continue;
									}

									$aggr_cal_key        = $aggr_cal_month->format( 'Y-m-' ) . str_pad( (string) $aggr_cal_d, 2, '0', STR_PAD_LEFT );
									$aggr_cal_class      = 'aggr-calendar__day';
									$aggr_cal_cell_class = '';

									// Y-m-d strings order correctly as strings, so no date objects are compared.
									if ( $aggr_cal_key === $aggr_cal_start || $aggr_cal_key === $aggr_cal_end ) {
										$aggr_cal_class .= ' aggr-calendar__day--edge';
									} elseif ( '' !== $aggr_cal_start && '' !== $aggr_cal_end && $aggr_cal_key > $aggr_cal_start && $aggr_cal_key < $aggr_cal_end ) {
										$aggr_cal_cell_class = 'aggr-calendar__cell--in';
									}

									if ( '' !== $aggr_cal_end && $aggr_cal_end > $aggr_cal_start ) {
										if ( $aggr_cal_key === $aggr_cal_start ) {
											$aggr_cal_cell_class = 'aggr-calendar__cell--from';
										} elseif ( $aggr_cal_key === $aggr_cal_end ) {
											$aggr_cal_cell_class = 'aggr-calendar__cell--to';
										}
									}
									?>
									<td class="<?php echo esc_attr( $aggr_cal_cell_class ); ?>"><span class="<?php echo esc_attr( $aggr_cal_class ); ?>"><?php echo esc_html( number_format_i18n( $aggr_cal_d ) ); ?></span></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endforeach; ?>
	</div>

	<p class="aggr-sr" data-aggr-calendar-status role="status" aria-live="polite"></p>

	<?php // The key the design draws. Availability itself waits on the forecast; no day is marked until it has a source. ?>
	<ul class="aggr-calendar__legend" aria-hidden="true">
		<li class="aggr-calendar__key aggr-calendar__key--selected"><?php esc_html_e( 'Selected', 'aggressive-ads' ); ?></li>
		<li class="aggr-calendar__key aggr-calendar__key--limited"><?php esc_html_e( 'Limited availability', 'aggressive-ads' ); ?></li>
		<li class="aggr-calendar__key aggr-calendar__key--sold"><?php esc_html_e( 'Sold out', 'aggressive-ads' ); ?></li>
	</ul>
</div>
