<?php
/**
 * What a draft's ad card adds below the shared card: why it was turned
 * down, its share and status among variants, and its own destination and
 * run dates, each behind a dialog.
 *
 * Scope is inherited from `campaign-ad-card.php`.
 *
 * @var array<string, mixed> $aggr_creative        The ad.
 * @var string               $aggr_destination_dlg Its destination dialog id.
 * @var string               $aggr_window_id       Its run-dates dialog id.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( true === $aggr_creative['rejected'] ) : ?>
	<?php
	/*
	 * A creative turned down while the campaign was
	 * running is still here when the advertiser comes
	 * back to edit, and it still will not serve. Saying
	 * why here as well as on the running view is what
	 * makes the next upload the right one.
	 */
	?>
	<p><span class="aggr-pill aggr-pill--danger"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
	<?php if ( '' !== (string) $aggr_creative['notes'] ) : ?>
		<p><?php echo esc_html( (string) $aggr_creative['notes'] ); ?></p>
	<?php endif; ?>
<?php endif; ?>
<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-share.php'; ?>
<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-variant-status.php'; ?>
<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-ad-history.php'; ?>

<?php
/*
 * Two edits, two dialogs, one row. Removal is not
 * among them any more: it lives on the artwork it
 * destroys, where the thing being removed is in
 * front of you when you ask for it.
 */
?>
<div class="aggr-uploaded__actions">
	<a
		class="aggr-card-action"
		href="#<?php echo esc_attr( $aggr_destination_dlg ); ?>"
		aria-haspopup="dialog"
		aria-controls="<?php echo esc_attr( $aggr_destination_dlg ); ?>"
		aria-expanded="false"
	><?php esc_html_e( 'Edit destination', 'aggressive-ads' ); ?></a>

	<?php
	/*
	 * The trigger says whether there is anything
	 * behind it.
	 *
	 * The fold this replaces showed a saved window
	 * without being opened. A dialog cannot, so the
	 * label has to carry it — otherwise dates
	 * somebody set are indistinguishable from dates
	 * nobody set, which is the whole reason the
	 * fold used to open itself.
	 */
	$aggr_has_window = '' !== (string) ( $aggr_creative['starts_on'] ?? '' )
		|| '' !== (string) ( $aggr_creative['ends_on'] ?? '' );
	?>
	<a
		class="aggr-card-action<?php echo $aggr_has_window ? ' aggr-card-action--set' : ''; ?>"
		href="#<?php echo esc_attr( $aggr_window_id ); ?>"
		aria-haspopup="dialog"
		aria-controls="<?php echo esc_attr( $aggr_window_id ); ?>"
		aria-expanded="false"
	>
		<span id="<?php echo esc_attr( 'aggr-window-label-' . (int) ( $aggr_creative['assignment_id'] ?? 0 ) ); ?>">
			<?php
			echo esc_html(
				$aggr_has_window
					? __( 'Custom run dates set', 'aggressive-ads' )
					: __( 'Add custom run dates', 'aggressive-ads' )
			);
			?>
		</span>
	</a>
</div>
