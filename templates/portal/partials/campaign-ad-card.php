<?php
/**
 * One ad: its artwork, what can be done to it, and where it goes.
 *
 * Drawn by creation's size cards, by the running-campaign edit flow and by
 * the campaign page's "Your ads" panel. It was three copies of one card; the
 * advertiser was meant to see one card, and three copies are how two of them
 * stop matching the third. Each caller passes the actions it offers and, as
 * a partial, whatever it adds below the file line.
 *
 * Scope is inherited from the caller.
 *
 * @var array<string, mixed>                  $aggr_creative     The ad.
 * @var string                                $aggr_card_image   The artwork to show: the ad's, or its pending update's.
 * @var string                                $aggr_card_link    Where it goes.
 * @var array<int, array{0: string, 1: string, 2: bool}> $aggr_card_actions Label, dialog id, destructive.
 * @var string                                $aggr_card_extras  A partial under this directory, or empty.
 * @var string                                $aggr_card_place   The placement it runs in, which names its actions.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="aggr-uploaded">
	<?php
	/*
	 * Beneath the artwork, not on it.
	 *
	 * They were text over a gradient, which is a bet on what the advertiser
	 * uploaded: a scrim tuned for a dark banner is unreadable over a light one,
	 * and a creative is whatever somebody sends. Off the image the contrast is
	 * the page's own and known, nothing covers the artwork, and there is no
	 * reveal to get right for keyboards and touchscreens because nothing is
	 * hidden.
	 */
	?>
	<div class="aggr-uploaded__figure">
		<div class="aggr-uploaded__thumb">
			<img class="aggr-uploaded__image" src="<?php echo esc_url( $aggr_card_image ); ?>" alt="<?php echo esc_attr( (string) ( $aggr_creative['alt_text'] ?? '' ) ); ?>" loading="lazy">
		</div>

		<div class="aggr-creative-actions">
			<?php foreach ( $aggr_card_actions as $aggr_card_action ) : ?>
				<a
					class="aggr-card-action<?php echo $aggr_card_action[2] ? ' aggr-card-action--danger' : ''; ?>"
					href="#<?php echo esc_attr( $aggr_card_action[1] ); ?>"
					aria-haspopup="dialog"
					aria-controls="<?php echo esc_attr( $aggr_card_action[1] ); ?>"
					aria-expanded="false"
				>
					<?php echo esc_html( $aggr_card_action[0] ); ?>
					<?php // "Preview" three times over is three links nobody can tell apart out of context. ?>
					<span class="aggr-sr"><?php echo esc_html( sprintf( /* translators: %s: the placement an ad runs in. */ __( '(%s)', 'aggressive-ads' ), $aggr_card_place ) ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</div>
	<div class="aggr-uploaded__details">
		<?php
		/*
		 * Destination first, filename after.
		 *
		 * The filename was the bold line and the destination was unlabelled grey
		 * text that read as debris. It is the wrong way round: nobody checks what
		 * the file was called, and the address every click goes to is both the
		 * thing that defines the creative and the thing most likely to be wrong.
		 */
		?>
		<p class="aggr-uploaded__destination">
			<span class="aggr-uploaded__destination-label"><?php esc_html_e( 'Destination', 'aggressive-ads' ); ?></span>
			<span class="aggr-uploaded__destination-value" id="<?php echo esc_attr( 'aggr-destination-value-' . (int) $aggr_creative['id'] ); ?>"><?php echo esc_html( $aggr_card_link ); ?></span>
		</p>
		<p class="aggr-uploaded__meta">
			<?php
			/*
			 * The file and its size only. It also said "goes to shared link" or
			 * "has its own link", which asked the reader to remember what the
			 * shared link was; the destination line above shows the link itself.
			 */
			echo esc_html(
				sprintf(
					/* translators: 1: file name. 2: file size, e.g. 54 KB. */
					__( '%1$s · %2$s', 'aggressive-ads' ),
					(string) $aggr_creative['name'],
					size_format( (int) $aggr_creative['bytes'] )
				)
			);
			?>
		</p>

		<?php
		if ( '' !== $aggr_card_extras ) {
			require AGGR_PLUGIN_DIR . 'templates/portal/partials/' . $aggr_card_extras;
		}
		?>
	</div>
</div>
