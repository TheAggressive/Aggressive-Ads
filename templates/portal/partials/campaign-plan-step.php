<?php
/**
 * The wizard's first step: what to buy, and when it runs.
 *
 * The package and the dates share a step because the one prices the other. A
 * fixed package sells a number of days, so an end-date field beside it asked
 * the advertiser for arithmetic the server then had to check. For a fixed
 * package the end is derived and stated; only a custom package asks for one.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<string, mixed>             $aggr_campaign         The campaign being edited.
 * @var array<int, array<string, mixed>> $aggr_packages         Package options.
 * @var int                              $aggr_package_id       The campaign's saved package, or zero.
 * @var string                           $aggr_error_for        Field the current error belongs to.
 * @var string                           $aggr_min_start_date   Earliest date the start input offers.
 * @var string                           $aggr_wizard_id        Store instance id.
 * @var string                           $aggr_autosave_context Autosave store context attribute.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Domain\Campaign_Rules;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\Portal\Date_Input;

/*
 * The package this form will post: the saved one, or else the catalogue
 * default. Whether the end date is asked for follows it, so the form a browser
 * without script submits describes the schedule the server is about to derive.
 */
$aggr_selected_package_id = $aggr_package_id;
$aggr_selected_days       = 0;

foreach ( $aggr_packages as $aggr_package ) {
	if ( 0 === $aggr_selected_package_id && (bool) $aggr_package['is_default'] ) {
		$aggr_selected_package_id = (int) $aggr_package['id'];
	}
}

foreach ( $aggr_packages as $aggr_package ) {
	if ( (int) $aggr_package['id'] === $aggr_selected_package_id ) {
		$aggr_selected_days = (int) $aggr_package['duration_days'];
	}
}

$aggr_fixed_run   = $aggr_selected_days > 0;
$aggr_start_ts    = Date_Input::parse( (string) $aggr_campaign['start_date'], false );
$aggr_run_end     = $aggr_fixed_run && is_int( $aggr_start_ts ) ? Campaign_Rules::fixed_end_ts( $aggr_start_ts, $aggr_selected_days, wp_timezone()->getName() ) : 0;
$aggr_date_errors = in_array( $aggr_error_for, array( 'aggr-start-date', 'aggr-end-date' ), true );
?>
<form
	id="aggr-plan-form"
	class="aggr-form"
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
	<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
	data-aggr-autosave="<?php echo esc_attr( $aggr_wizard_id ); ?>"
	data-wp-init="actions.init"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
	<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
	<?php wp_nonce_field( Campaign_Nonces::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

	<?php
	/*
	 * Not `required`, deliberately. A draft has to be savable before anything
	 * is decided so somebody can come back to it; the package is enforced at
	 * review, where Review_Readiness points the error straight back here.
	 */
	?>
	<div class="aggr-step-card">
	<fieldset id="aggr-packages" class="aggr-fieldset" <?php echo 'aggr-packages' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>>
		<legend>
			<span class="aggr-eyebrow" aria-hidden="true"><?php esc_html_e( '01 · Package', 'aggressive-ads' ); ?></span>
			<span class="aggr-step-card__title"><?php esc_html_e( 'Choose a package', 'aggressive-ads' ); ?></span>
		</legend>
		<p id="aggr-packages-hint" class="aggr-hint"><?php esc_html_e( 'Its price and ad sizes are locked into this campaign when you choose it.', 'aggressive-ads' ); ?></p>

		<?php if ( array() === $aggr_packages ) : ?>
			<div class="aggr-empty">
				<p class="aggr-empty__title"><?php esc_html_e( 'No packages are available', 'aggressive-ads' ); ?></p>
				<p><?php esc_html_e( 'The catalogue is not configured yet. Your draft is safe; please return later or get in touch.', 'aggressive-ads' ); ?></p>
			</div>
		<?php else : ?>
			<div class="aggr-choicegrid">
				<?php foreach ( $aggr_packages as $aggr_package ) : ?>
					<label class="aggr-choice aggr-choice--package">
						<input
							type="radio"
							name="package_id"
							value="<?php echo esc_attr( (string) $aggr_package['id'] ); ?>"
							data-aggr-duration-days="<?php echo esc_attr( (string) (int) $aggr_package['duration_days'] ); ?>"
							aria-describedby="aggr-packages-hint"
							<?php checked( (int) $aggr_package['id'], $aggr_selected_package_id ); ?>
						>
						<span class="aggr-choice__text">
							<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_package['name'] ); ?></span>
							<?php if ( (bool) $aggr_package['is_default'] ) : ?>
								<span class="aggr-package__badge"><?php esc_html_e( 'Recommended', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
							<span class="aggr-package__price"><?php echo esc_html( (string) $aggr_package['price'] ); ?></span>
							<span class="aggr-package__meta">
								<?php
								printf(
									/* translators: 1: duration, e.g. 30 days. 2: number of ad sizes. */
									esc_html( _n( '%1$s · %2$d size', '%1$s · %2$d sizes', count( $aggr_package['sizes'] ), 'aggressive-ads' ) ),
									esc_html( (string) $aggr_package['duration'] ),
									(int) count( $aggr_package['sizes'] )
								);
								?>
							</span>
							<span class="aggr-package__sizes" aria-hidden="true">
								<?php foreach ( $aggr_package['sizes'] as $aggr_package_size ) : ?>
									<span class="aggr-package__size">
										<span class="aggr-package__shape" style="width: <?php echo esc_attr( (string) (int) $aggr_package_size['width'] ); ?>px; height: <?php echo esc_attr( (string) (int) $aggr_package_size['height'] ); ?>px"></span>
										<?php echo esc_html( (string) $aggr_package_size['label'] ); ?>
									</span>
								<?php endforeach; ?>
							</span>
							<span class="aggr-package__places"><?php echo esc_html( implode( ', ', $aggr_package['placements'] ) ); ?></span>
						</span>
					</label>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</fieldset>
	</div>

	<div class="aggr-step-card">
	<fieldset id="aggr-schedule" class="aggr-fieldset" <?php echo $aggr_date_errors ? 'aria-invalid="true"' : ''; ?>>
		<legend>
			<span class="aggr-eyebrow" aria-hidden="true"><?php esc_html_e( '02 · Schedule', 'aggressive-ads' ); ?></span>
			<span class="aggr-step-card__title"><?php esc_html_e( 'When should it run?', 'aggressive-ads' ); ?></span>
		</legend>
		<p class="aggr-hint">
			<?php
			echo $aggr_fixed_run
				? esc_html__( 'The package sets how long it runs. The start day counts as the first day.', 'aggressive-ads' )
				: esc_html__( 'A custom schedule: choose a start and an end.', 'aggressive-ads' );
			?>
		</p>

		<div class="aggr-formgrid">
			<div class="aggr-field">
				<label for="aggr-start-date"><?php esc_html_e( 'Start date', 'aggressive-ads' ); ?></label>
				<p id="aggr-start-hint" class="aggr-hint"><?php esc_html_e( 'The campaign begins at the start of this day.', 'aggressive-ads' ); ?></p>
				<div class="aggr-date-input">
				<input
					id="aggr-start-date"
					name="start_date"
					type="date"
					value="<?php echo esc_attr( (string) $aggr_campaign['start_date'] ); ?>"
					min="<?php echo esc_attr( $aggr_min_start_date ); ?>"
					required
					aria-describedby="aggr-start-hint aggr-run-through<?php echo 'aggr-start-date' === $aggr_error_for ? ' aggr-campaign-error' : ''; ?>"
					<?php echo 'aggr-start-date' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
				>
					<span class="aggr-date-input__note" aria-hidden="true" data-aggr-zone-note data-aggr-edge="start" data-aggr-for="aggr-start-date" data-aggr-zone="<?php echo esc_attr( wp_timezone_string() ); ?>"><?php echo esc_html( Date_Input::edge_label( (string) $aggr_campaign['start_date'], false ) ); ?></span>
				</div>
			</div>

			<?php
			/*
			 * Rendered for every package and disabled for a fixed one, rather
			 * than left out. A disabled control is not posted, so the server
			 * derives the end; and switching to a custom package only has to
			 * enable it, not build a field the page never had.
			 */
			?>
			<div class="aggr-field" data-aggr-end-field <?php echo $aggr_fixed_run ? 'hidden' : ''; ?>>
				<label for="aggr-end-date"><?php esc_html_e( 'End date', 'aggressive-ads' ); ?></label>
				<p id="aggr-end-hint" class="aggr-hint"><?php esc_html_e( 'The campaign runs through the end of this day.', 'aggressive-ads' ); ?></p>
				<div class="aggr-date-input">
				<input
					id="aggr-end-date"
					name="end_date"
					type="date"
					value="<?php echo esc_attr( (string) $aggr_campaign['end_date'] ); ?>"
					aria-describedby="aggr-end-hint<?php echo 'aggr-end-date' === $aggr_error_for ? ' aggr-campaign-error' : ''; ?>"
					<?php echo $aggr_fixed_run ? 'disabled' : 'required'; ?>
					<?php echo 'aggr-end-date' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
				>
					<span class="aggr-date-input__note" aria-hidden="true" data-aggr-zone-note data-aggr-edge="end" data-aggr-for="aggr-end-date" data-aggr-fallback="aggr-start-date" data-aggr-zone="<?php echo esc_attr( wp_timezone_string() ); ?>"><?php echo esc_html( Date_Input::edge_label( '' !== (string) $aggr_campaign['end_date'] ? (string) $aggr_campaign['end_date'] : (string) $aggr_campaign['start_date'], true ) ); ?></span>
				</div>
			</div>
		</div>

		<p id="aggr-run-through" class="aggr-hint" data-aggr-run-through aria-live="polite" <?php echo 0 === $aggr_run_end ? 'hidden' : ''; ?>>
			<?php
			if ( $aggr_run_end > 0 ) {
				printf(
					/* translators: %s: the campaign's last day, e.g. October 30, 2026. */
					esc_html__( 'Runs through %s.', 'aggressive-ads' ),
					esc_html( (string) wp_date( (string) get_option( 'date_format', 'F j, Y' ), $aggr_run_end ) )
				);
			}
			?>
		</p>

		<?php
		$aggr_cal_start_id = 'aggr-start-date';
		$aggr_cal_end_id   = 'aggr-end-date';
		$aggr_cal_start    = (string) $aggr_campaign['start_date'];
		$aggr_cal_end      = $aggr_run_end > 0 ? (string) wp_date( 'Y-m-d', $aggr_run_end, wp_timezone() ) : (string) $aggr_campaign['end_date'];
		$aggr_cal_min      = $aggr_min_start_date;
		$aggr_cal_fixed    = $aggr_fixed_run;
		$aggr_cal_locked   = false;

		require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-calendar.php';
		?>
	</fieldset>
	</div>

	<?php
	/*
	 * Two labels, both true, and the right one is chosen before paint.
	 *
	 * Without JavaScript this button is the save, and "Save and continue"
	 * describes it exactly. With autosave running the step is already stored
	 * by the time anyone reaches it, so all that is left is to move on.
	 */
	?>
	<div class="aggr-form__actions">
		<button class="aggr-button aggr-wizard__primary" type="submit">
			<span class="aggr-noscript-label"><?php esc_html_e( 'Save and continue', 'aggressive-ads' ); ?></span>
			<span class="aggr-script-label"><?php esc_html_e( 'Continue to ads', 'aggressive-ads' ); ?></span>
		</button>
	</div>
</form>
