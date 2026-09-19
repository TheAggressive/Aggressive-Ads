<?php
/**
 * What removing an ad does to the same file on other placements.
 *
 * Each placement's copy is a creative of its own, so removing this one leaves
 * the others running. Said here, where the choice is made, and offered as a
 * box to tick rather than done: the advertiser who put one file on two sizes
 * may be replacing it on only one of them.
 *
 * Scope is inherited from the remove dialog.
 *
 * @var array<string, mixed> $aggr_creative The ad being removed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_same_file = is_array( $aggr_creative['same_file'] ?? null ) ? $aggr_creative['same_file'] : array();

if ( array() === $aggr_same_file ) {
	return;
}
?>
<fieldset class="aggr-same-file-remove">
	<legend class="aggr-hint"><?php esc_html_e( 'The same file is on other placements. Removing it here leaves them as they are unless you tick them.', 'aggressive-ads' ); ?></legend>
	<?php foreach ( $aggr_same_file as $aggr_same ) : ?>
		<?php $aggr_same_id = 'aggr-also-remove-' . (int) $aggr_creative['id'] . '-' . (int) $aggr_same['id']; ?>
		<?php if ( true === $aggr_same['approved'] ) : ?>
			<?php // A published ad is not removed from this dialog, so it is not offered. ?>
			<p class="aggr-hint">
				<?php
				/* translators: %s: a placement's name, e.g. Break. */
				echo esc_html( sprintf( __( '%s keeps its copy: it has been approved.', 'aggressive-ads' ), (string) $aggr_same['placement'] ) );
				?>
			</p>
		<?php else : ?>
			<div class="aggr-field aggr-field--inline">
				<input id="<?php echo esc_attr( $aggr_same_id ); ?>" type="checkbox" name="also_remove[]" value="<?php echo esc_attr( (string) (int) $aggr_same['id'] ); ?>">
				<label for="<?php echo esc_attr( $aggr_same_id ); ?>">
					<?php
					/* translators: %s: a placement's name, e.g. Break. */
					echo esc_html( sprintf( __( 'Also remove it from %s', 'aggressive-ads' ), (string) $aggr_same['placement'] ) );
					?>
				</label>
			</div>
		<?php endif; ?>
	<?php endforeach; ?>
</fieldset>
