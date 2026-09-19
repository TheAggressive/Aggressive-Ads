<?php
/**
 * Says that a size holding several ads rotates them.
 *
 * Two ads under one heading look like a mistake — an upload that happened
 * twice — until something says they take turns. The share control on each
 * card only makes sense once that is known, so this comes before them.
 *
 * Scope is inherited from the caller.
 *
 * @var array<string, mixed> $aggr_slot The placement and the creatives on it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_rotation_count = count( $aggr_slot['creatives'] );

if ( $aggr_rotation_count < 2 ) {
	return;
}
?>
<div class="aggr-rotation">
	<span class="aggr-pill aggr-pill--neutral">
		<?php
		printf(
			/* translators: %d: how many ads are on this size. */
			esc_html( _n( '%d ad rotating', '%d ads rotating', $aggr_rotation_count, 'aggressive-ads' ) ),
			(int) $aggr_rotation_count
		);
		?>
	</span>
	<p class="aggr-rotation__note">
		<?php esc_html_e( 'Each visitor sees one of these. The shares below decide how often each is chosen.', 'aggressive-ads' ); ?>
	</p>

	<?php
	/*
	 * The split itself, one slice per ad, in the order the cards are in.
	 *
	 * Drawn by the server so it is right on arrival and without script, and
	 * moved by the slider as it is dragged — the whole point of the bar is
	 * that a share is a proportion, which a column of numbers never shows.
	 * `aria-hidden`: every slice is the number its own card already states.
	 */
	$aggr_rotation_shares = array();

	foreach ( $aggr_slot['creatives'] as $aggr_rotation_creative ) {
		$aggr_rotation_share = $aggr_rotation_creative['share'] ?? null;

		if ( null === $aggr_rotation_share ) {
			$aggr_rotation_shares = array();

			break;
		}

		$aggr_rotation_shares[ (int) $aggr_rotation_creative['id'] ] = (int) round( (float) $aggr_rotation_share * 100 );
	}
	?>
	<?php if ( array() !== $aggr_rotation_shares ) : ?>
		<span class="aggr-rotation__bar" aria-hidden="true">
			<?php foreach ( $aggr_rotation_shares as $aggr_rotation_id => $aggr_rotation_percent ) : ?>
				<span
					class="aggr-rotation__slice"
					data-aggr-share-slice="<?php echo esc_attr( (string) $aggr_rotation_id ); ?>"
					style="--aggr-slice: <?php echo esc_attr( (string) $aggr_rotation_percent ); ?>"
				></span>
			<?php endforeach; ?>
		</span>
	<?php endif; ?>
</div>
