<?php
/**
 * One variant's own delivery dates, inside the campaign's.
 *
 * Both fields are optional and empty means *inherit that end from the
 * campaign*, which is why both are always submitted: an empty box is a value
 * here, not an omission. `Assignment_Rules::window_fits()` treats zero as
 * inherit on each end independently, and refuses a window that widens rather
 * than clamping it — a campaign sold for June must not carry a creative running
 * into July, and silently moving somebody's date is worse than refusing it.
 *
 * The `min`/`max` attributes mirror the campaign's window so the browser can
 * say so first. They are a convenience, never the check: the server refuses the
 * same widening whether or not a browser was involved.
 *
 * @var array<string, mixed> $aggr_creative One uploaded creative, with its assignment attached.
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;

$aggr_assignment_id = (int) ( $aggr_creative['assignment_id'] ?? 0 );

if ( $aggr_assignment_id < 1 ) {
	return;
}

$aggr_window_start = 'aggr-starts-' . $aggr_assignment_id;
$aggr_window_end   = 'aggr-ends-' . $aggr_assignment_id;
$aggr_parent_start = (string) ( $aggr_campaign['start_date'] ?? '' );
$aggr_parent_end   = (string) ( $aggr_campaign['end_date'] ?? '' );
?>
<form
	class="aggr-variant-window"
	method="post"
	action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
	data-aggr-save="<?php echo esc_attr( 'aggr-save-window-' . $aggr_assignment_id ); ?>"
>
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WINDOW_ACTION ); ?>">
	<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $aggr_assignment_id ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( (string) (int) ( $aggr_creative['revision'] ?? 0 ) ); ?>">
	<?php wp_nonce_field( Creative_Actions::window_nonce_action( $aggr_assignment_id ) ); ?>

	<fieldset class="aggr-variant-window__fields">
		<legend class="aggr-variant-window__legend">
			<?php esc_html_e( 'Runs between', 'aggressive-ads' ); ?>
		</legend>

		<label class="aggr-variant-window__label" for="<?php echo esc_attr( $aggr_window_start ); ?>">
			<?php esc_html_e( 'From', 'aggressive-ads' ); ?>
		</label>
		<input
			class="aggr-variant-window__input"
			id="<?php echo esc_attr( $aggr_window_start ); ?>"
			type="date"
			name="starts_on"
			value="<?php echo esc_attr( (string) ( $aggr_creative['starts_on'] ?? '' ) ); ?>"
			min="<?php echo esc_attr( $aggr_parent_start ); ?>"
			max="<?php echo esc_attr( $aggr_parent_end ); ?>"
		>

		<label class="aggr-variant-window__label" for="<?php echo esc_attr( $aggr_window_end ); ?>">
			<?php esc_html_e( 'To', 'aggressive-ads' ); ?>
		</label>
		<input
			class="aggr-variant-window__input"
			id="<?php echo esc_attr( $aggr_window_end ); ?>"
			type="date"
			name="ends_on"
			value="<?php echo esc_attr( (string) ( $aggr_creative['ends_on'] ?? '' ) ); ?>"
			min="<?php echo esc_attr( $aggr_parent_start ); ?>"
			max="<?php echo esc_attr( $aggr_parent_end ); ?>"
		>
	</fieldset>

	<p class="aggr-variant-window__hint">
		<?php esc_html_e( 'Leave a date empty to follow the campaign’s own dates.', 'aggressive-ads' ); ?>
	</p>

	<button class="aggr-button aggr-button--secondary" type="submit">
		<?php esc_html_e( 'Save dates', 'aggressive-ads' ); ?>
	</button>
</form>
