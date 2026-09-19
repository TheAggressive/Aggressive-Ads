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
</div>
