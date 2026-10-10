<?php
/**
 * One ad in the page sketch: its file when it has one, a dashed marker when not.
 *
 * Both are drawn at the placement's own aspect ratio, so a missing 728×90 holds
 * the same room the finished ad will.
 *
 * @var array{width: int, height: int, preview: string} $aggr_tile The tile to draw.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_tile_ratio = $aggr_tile['width'] . ' / ' . $aggr_tile['height'];
$aggr_tile_size  = $aggr_tile['width'] . ' × ' . $aggr_tile['height'];
?>
<?php if ( '' !== $aggr_tile['preview'] ) : ?>
	<img class="aggr-pagepreview__slot" src="<?php echo esc_url( $aggr_tile['preview'] ); ?>" alt="" loading="lazy" style="aspect-ratio: <?php echo esc_attr( $aggr_tile_ratio ); ?>">
<?php else : ?>
	<span class="aggr-pagepreview__slot aggr-pagepreview__slot--empty" style="aspect-ratio: <?php echo esc_attr( $aggr_tile_ratio ); ?>">
		<?php
		echo esc_html(
			sprintf(
				/* translators: %s: a size, e.g. 300 × 250. The ad for it is not uploaded yet. */
				__( '%s · needs a file', 'aggressive-ads' ),
				$aggr_tile_size
			)
		);
		?>
	</span>
<?php endif; ?>
