<?php
/**
 * The package cards, as creation and editing both draw them.
 *
 * One file because the edit flow is meant to be creation's own screen: an
 * advertiser moving a running campaign to another package chooses from the
 * same cards, with the same prices and sizes, that they bought it from.
 *
 * Scope is inherited from the step that requires it.
 *
 * @var array<int, array<string, mixed>> $aggr_grid_packages Package options.
 * @var int                              $aggr_grid_selected The package this form will post.
 * @var int                              $aggr_grid_current  The package the campaign runs on, or zero while drafting.
 * @var string                           $aggr_grid_hint     Id of the sentence describing the choice.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aggr-choicegrid">
	<?php foreach ( $aggr_grid_packages as $aggr_package ) : ?>
		<label class="aggr-choice aggr-choice--package">
			<input
				type="radio"
				name="package_id"
				value="<?php echo esc_attr( (string) $aggr_package['id'] ); ?>"
				data-aggr-duration-days="<?php echo esc_attr( (string) (int) $aggr_package['duration_days'] ); ?>"
				aria-describedby="<?php echo esc_attr( $aggr_grid_hint ); ?>"
				<?php checked( (int) $aggr_package['id'], $aggr_grid_selected ); ?>
			>
			<span class="aggr-choice__text">
				<span class="aggr-package__name"><?php echo esc_html( (string) $aggr_package['name'] ); ?></span>
				<?php if ( $aggr_grid_current > 0 && (int) $aggr_package['id'] === $aggr_grid_current ) : ?>
					<?php // The one running now, which is what an upgrade is measured from. ?>
					<span class="aggr-package__badge"><?php esc_html_e( 'Your package', 'aggressive-ads' ); ?></span>
				<?php elseif ( 0 === $aggr_grid_current && (bool) $aggr_package['is_default'] ) : ?>
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
