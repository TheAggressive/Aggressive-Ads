<?php
/**
 * Two months of calendar, drawing the date range the campaign holds.
 *
 * A picture of the dates, not a control: the date fields beside it are what is
 * submitted, so this is hidden from assistive technology rather than read as a
 * second, inert way to choose. It never marks availability — that needs real
 * inventory data, and a made-up "sold out" day is worse than none.
 *
 * Scope is inherited from the plan step.
 *
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 * @var int                  $aggr_run_end  Derived last second of a fixed run, or zero.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wp_locale;

$aggr_cal_zone  = wp_timezone();
$aggr_cal_start = (string) ( $aggr_campaign['start_date'] ?? '' );
$aggr_cal_end   = (string) ( $aggr_campaign['end_date'] ?? '' );

if ( '' === $aggr_cal_end && isset( $aggr_run_end ) && $aggr_run_end > 0 ) {
	$aggr_cal_end = (string) wp_date( 'Y-m-d', $aggr_run_end, $aggr_cal_zone );
}

$aggr_cal_first = '' !== $aggr_cal_start ? DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_start, $aggr_cal_zone ) : false;
$aggr_cal_first = false === $aggr_cal_first ? new DateTimeImmutable( 'now', $aggr_cal_zone ) : $aggr_cal_first;
$aggr_cal_first = $aggr_cal_first->modify( 'first day of this month' )->setTime( 0, 0 );
$aggr_cal_names = array();

for ( $aggr_cal_i = 0; $aggr_cal_i < 7; $aggr_cal_i++ ) {
	$aggr_cal_names[] = $wp_locale instanceof WP_Locale
		? (string) $wp_locale->get_weekday_initial( $wp_locale->get_weekday( $aggr_cal_i ) )
		: array( 'S', 'M', 'T', 'W', 'T', 'F', 'S' )[ $aggr_cal_i ];
}
?>
<?php
$aggr_cal_span = 0;

if ( '' !== $aggr_cal_start && '' !== $aggr_cal_end ) {
	$aggr_cal_from = DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_start, $aggr_cal_zone );
	$aggr_cal_to   = DateTimeImmutable::createFromFormat( '!Y-m-d', $aggr_cal_end, $aggr_cal_zone );

	if ( false !== $aggr_cal_from && false !== $aggr_cal_to && $aggr_cal_to >= $aggr_cal_from ) {
		$aggr_cal_span = (int) $aggr_cal_from->diff( $aggr_cal_to )->days + 1;
	}
}
?>
<?php if ( $aggr_cal_span > 0 ) : ?>
	<p class="aggr-calendar__span" aria-hidden="true">
		<?php
		printf(
			/* translators: %d: number of days between the start and end dates, inclusive. */
			esc_html( _n( '%d day selected', '%d days selected', $aggr_cal_span, 'aggressive-ads' ) ),
			(int) $aggr_cal_span
		);
		?>
	</p>
<?php endif; ?>
<div class="aggr-calendar" aria-hidden="true">
	<?php foreach ( array( $aggr_cal_first, $aggr_cal_first->modify( '+1 month' ) ) as $aggr_cal_month ) : ?>
		<?php
		$aggr_cal_blanks = (int) $aggr_cal_month->format( 'w' );
		$aggr_cal_days   = (int) $aggr_cal_month->format( 't' );
		?>
		<div class="aggr-calendar__month">
			<span class="aggr-calendar__name"><?php echo esc_html( (string) wp_date( 'F Y', $aggr_cal_month->getTimestamp(), $aggr_cal_zone ) ); ?></span>
			<div class="aggr-calendar__grid">
				<?php foreach ( $aggr_cal_names as $aggr_cal_name ) : ?>
					<span class="aggr-calendar__weekday"><?php echo esc_html( $aggr_cal_name ); ?></span>
				<?php endforeach; ?>
				<?php for ( $aggr_cal_i = 0; $aggr_cal_i < $aggr_cal_blanks; $aggr_cal_i++ ) : ?>
					<span class="aggr-calendar__day"></span>
				<?php endfor; ?>
				<?php for ( $aggr_cal_d = 1; $aggr_cal_d <= $aggr_cal_days; $aggr_cal_d++ ) : ?>
					<?php
					$aggr_cal_key   = $aggr_cal_month->format( 'Y-m-' ) . str_pad( (string) $aggr_cal_d, 2, '0', STR_PAD_LEFT );
					$aggr_cal_class = 'aggr-calendar__day';

					// Y-m-d strings order correctly as strings, so no date objects are compared.
					if ( $aggr_cal_key === $aggr_cal_start || $aggr_cal_key === $aggr_cal_end ) {
						$aggr_cal_class .= ' aggr-calendar__day--edge';
					} elseif ( '' !== $aggr_cal_start && '' !== $aggr_cal_end && $aggr_cal_key > $aggr_cal_start && $aggr_cal_key < $aggr_cal_end ) {
						$aggr_cal_class .= ' aggr-calendar__day--in';
					}
					?>
					<span class="<?php echo esc_attr( $aggr_cal_class ); ?>"><?php echo esc_html( number_format_i18n( $aggr_cal_d ) ); ?></span>
				<?php endfor; ?>
			</div>
		</div>
	<?php endforeach; ?>
</div>
<?php // The key the design draws. Availability itself is #293; no day is marked until it has a source. ?>
<ul class="aggr-calendar__legend" aria-hidden="true">
	<li class="aggr-calendar__key aggr-calendar__key--selected"><?php esc_html_e( 'Selected', 'aggressive-ads' ); ?></li>
	<li class="aggr-calendar__key aggr-calendar__key--limited"><?php esc_html_e( 'Limited availability', 'aggressive-ads' ); ?></li>
	<li class="aggr-calendar__key aggr-calendar__key--sold"><?php esc_html_e( 'Sold out', 'aggressive-ads' ); ?></li>
</ul>
