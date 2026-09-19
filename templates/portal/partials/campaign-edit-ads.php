<?php
/**
 * A running campaign's ads, as creation's size cards with an Update.
 *
 * The edit flow's Ads step is creation's: one card per size, the artwork on
 * it, where it goes. What a running campaign can do differs, and only that
 * differs. An ad that is serving is not replaced or removed in place — Update
 * sends a replacement, artwork or link, to the review team while the current
 * one keeps running. A size with no ad takes one, which is reviewed before it
 * serves. A size a proposed package or placement change would add is shown
 * too, so the advertiser sees what the change asks of them before they submit
 * it.
 *
 * Scope is inherited from the edit flow.
 *
 * @var array<string, mixed>             $aggr_campaign         The campaign.
 * @var array<int, array<string, mixed>> $aggr_creative_updates Replacement history rows.
 * @var bool                             $aggr_can_update       Whether the ads can be replaced.
 * @var string                           $aggr_ads_eyebrow      The card's number, e.g. "02 · Ads".
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;

$aggr_edit_slots  = is_array( $aggr_campaign['edit_slots'] ?? null ) ? $aggr_campaign['edit_slots'] : array();
$aggr_shared_link = (string) ( $aggr_campaign['edit_link'] ?? '' );
$aggr_updates     = isset( $aggr_creative_updates ) && is_array( $aggr_creative_updates ) ? $aggr_creative_updates : array();
$aggr_overlays    = array();

// The one form a refused upload reopens, as the wizard does.
$aggr_creative_error_for = isset( $aggr_creative_error_for ) ? (string) $aggr_creative_error_for : '';
?>
<section class="aggr-ads-section">
	<div class="aggr-ads-section__head">
		<div>
			<p class="aggr-eyebrow"><?php echo esc_html( $aggr_ads_eyebrow ); ?></p>
			<h3 id="aggr-update-creatives-heading"><?php esc_html_e( 'Your ads', 'aggressive-ads' ); ?></h3>
			<p class="aggr-hint"><?php esc_html_e( 'Select Update to send new artwork or a link of its own for one ad. The current ad keeps running until the review team accepts the new one.', 'aggressive-ads' ); ?></p>
		</div>
	</div>
</section>

<noscript><style>.aggr-portal .aggr-upload-form button[type="submit"][hidden]{display:inline-flex!important}</style></noscript>
<div class="aggr-upload-list">
	<?php foreach ( $aggr_edit_slots as $aggr_slot ) : ?>
		<?php
		$aggr_slot_dims = array();
		$aggr_slot_w    = 1 === preg_match( '/^(\d+)x(\d+)$/', (string) $aggr_slot['size'], $aggr_slot_dims ) ? (int) $aggr_slot_dims[1] : 0;
		$aggr_slot_h    = $aggr_slot_w > 0 ? (int) $aggr_slot_dims[2] : 0;
		$aggr_slot_wide = $aggr_slot_h >= 200 && $aggr_slot_w >= 2 * $aggr_slot_h;
		$aggr_slot_new  = true === ( $aggr_slot['proposed'] ?? false );
		$aggr_slot_gone = true === ( $aggr_slot['leaving'] ?? false );
		$aggr_slot_none = array() === $aggr_slot['creatives'];
		?>
		<section class="aggr-upload-card<?php echo $aggr_slot_wide ? ' aggr-upload-card--wide' : ''; ?>" aria-labelledby="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
			<div class="aggr-upload-card__head">
				<div>
					<h3 id="aggr-slot-<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>"><?php echo esc_html( (string) $aggr_slot['name'] ); ?></h3>
					<p class="aggr-upload-card__dims"><?php echo esc_html( $aggr_slot_w > 0 ? $aggr_slot_w . ' × ' . $aggr_slot_h . ' px' : (string) $aggr_slot['size'] ); ?></p>
				</div>
				<?php // A badge only where it asks for something, as on creation's cards. ?>
				<?php if ( $aggr_slot_new ) : ?>
					<span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Added by your change', 'aggressive-ads' ); ?></span>
				<?php elseif ( $aggr_slot_gone ) : ?>
					<span class="aggr-pill aggr-pill--neutral"><?php esc_html_e( 'Removed by your change', 'aggressive-ads' ); ?></span>
				<?php elseif ( $aggr_slot_none ) : ?>
					<span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Needs a file', 'aggressive-ads' ); ?></span>
				<?php endif; ?>
			</div>

			<?php if ( $aggr_slot_new ) : ?>
				<?php if ( $aggr_slot_w > 0 ) : ?>
					<div class="aggr-upload-card__stage" aria-hidden="true">
						<span class="aggr-upload-card__frame" style="aspect-ratio: <?php echo esc_attr( $aggr_slot_w . ' / ' . $aggr_slot_h ); ?>; --aggr-frame-width: <?php echo esc_attr( (string) min( 20, max( 3, round( $aggr_slot_w / 36, 1 ) ) ) ); ?>rem"><?php echo esc_html( $aggr_slot_w . ' × ' . $aggr_slot_h ); ?></span>
					</div>
				<?php endif; ?>
				<?php
				/*
				 * Said, not offered. The placement is not the campaign's until
				 * the change is approved, and an upload form here would be
				 * refused by the server for exactly that reason.
				 */
				?>
				<p class="aggr-hint"><?php esc_html_e( 'Your change adds this size. Once the review team accepts it, add an ad for it here; it is reviewed before it runs.', 'aggressive-ads' ); ?></p>
			<?php elseif ( $aggr_slot_none ) : ?>
				<?php if ( $aggr_slot_w > 0 ) : ?>
					<div class="aggr-upload-card__stage" aria-hidden="true">
						<span class="aggr-upload-card__frame" style="aspect-ratio: <?php echo esc_attr( $aggr_slot_w . ' / ' . $aggr_slot_h ); ?>; --aggr-frame-width: <?php echo esc_attr( (string) min( 20, max( 3, round( $aggr_slot_w / 36, 1 ) ) ) ); ?>rem"><?php echo esc_html( $aggr_slot_w . ' × ' . $aggr_slot_h ); ?></span>
					</div>
				<?php endif; ?>
				<p class="aggr-hint"><?php esc_html_e( 'This size has no ad, so nothing runs here yet. The ad you add is reviewed before it runs.', 'aggressive-ads' ); ?></p>
				<?php
				$aggr_upload_from_edit = true;

				require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-upload-form.php';
				?>
			<?php else : ?>
				<?php foreach ( $aggr_slot['creatives'] as $aggr_creative ) : ?>
					<?php
					$aggr_creative_key = (int) $aggr_creative['id'];
					$aggr_pending      = null;

					foreach ( $aggr_updates as $aggr_update ) {
						if ( (int) $aggr_update['creative_id'] === $aggr_creative_key && 'pending' === (string) $aggr_update['state'] ) {
							$aggr_pending = $aggr_update;
							break;
						}
					}

					$aggr_preview_id = 'aggr-preview-' . $aggr_creative_key;
					$aggr_replace_id = 'aggr-replace-' . $aggr_creative_key;
					$aggr_overlays[] = array(
						'kind'       => 'preview',
						'id'         => $aggr_preview_id,
						'creative'   => $aggr_creative,
						'placement'  => (string) $aggr_slot['name'],
						'close_href' => 'aggr-slot-' . (int) $aggr_slot['id'],
					);

					if ( $aggr_can_update && null === $aggr_pending ) {
						$aggr_overlays[] = array(
							'kind'       => 'replace',
							'id'         => $aggr_replace_id,
							'creative'   => $aggr_creative,
							'placement'  => (string) $aggr_slot['name'],
							'close_href' => 'aggr-slot-' . (int) $aggr_slot['id'],
						);
					}
					?>
					<div class="aggr-uploaded">
						<div class="aggr-uploaded__figure">
							<div class="aggr-uploaded__thumb">
								<img class="aggr-uploaded__image" src="<?php echo esc_url( (string) ( null === $aggr_pending ? $aggr_creative['preview'] : $aggr_pending['preview'] ) ); ?>" alt="<?php echo esc_attr( (string) ( $aggr_creative['alt_text'] ?? '' ) ); ?>" loading="lazy">
							</div>

							<div class="aggr-creative-actions">
								<a
									class="aggr-card-action"
									href="#<?php echo esc_attr( $aggr_preview_id ); ?>"
									aria-haspopup="dialog"
									aria-controls="<?php echo esc_attr( $aggr_preview_id ); ?>"
									aria-expanded="false"
								><?php esc_html_e( 'Preview', 'aggressive-ads' ); ?></a>
								<?php if ( $aggr_can_update && null === $aggr_pending ) : ?>
									<a
										class="aggr-card-action"
										href="#<?php echo esc_attr( $aggr_replace_id ); ?>"
										aria-haspopup="dialog"
										aria-controls="<?php echo esc_attr( $aggr_replace_id ); ?>"
										aria-expanded="false"
									><?php esc_html_e( 'Update', 'aggressive-ads' ); ?></a>
								<?php endif; ?>
							</div>
						</div>
						<div class="aggr-uploaded__details">
							<p class="aggr-uploaded__destination">
								<span class="aggr-uploaded__destination-label"><?php esc_html_e( 'Goes to', 'aggressive-ads' ); ?></span>
								<span class="aggr-uploaded__destination-value"><?php echo esc_html( (string) ( null === $aggr_pending ? $aggr_creative['click_url'] : $aggr_pending['click_url'] ) ); ?></span>
							</p>
							<p class="aggr-uploaded__meta">
								<?php
								echo esc_html(
									sprintf(
										/* translators: 1: file name. 2: file size, e.g. 54 KB. 3: where it links, e.g. goes to shared link. */
										__( '%1$s · %2$s · %3$s', 'aggressive-ads' ),
										(string) $aggr_creative['name'],
										size_format( (int) $aggr_creative['bytes'] ),
										'' !== $aggr_shared_link && (string) $aggr_creative['click_url'] === $aggr_shared_link
											? __( 'goes to shared link', 'aggressive-ads' )
											: __( 'has its own link', 'aggressive-ads' )
									)
								);
								?>
							</p>

							<?php if ( null !== $aggr_pending ) : ?>
								<p><span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Update waiting for review', 'aggressive-ads' ); ?></span></p>
								<p class="aggr-hint"><?php esc_html_e( 'The ad above is the update. The current one keeps running until the review team accepts it.', 'aggressive-ads' ); ?></p>
								<form class="aggr-uploaded__actions" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
									<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WITHDRAW_ACTION ); ?>">
									<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
									<input type="hidden" name="replacement_id" value="<?php echo esc_attr( (string) $aggr_pending['id'] ); ?>">
									<?php wp_nonce_field( Creative_Actions::withdraw_nonce_action( (int) $aggr_pending['id'] ) ); ?>
									<button class="aggr-card-action" type="submit"><?php esc_html_e( 'Withdraw update', 'aggressive-ads' ); ?></button>
								</form>
							<?php elseif ( true === $aggr_creative['rejected'] ) : ?>
								<?php // The reason, to the person it was written for, and what to do about it. ?>
								<p><span class="aggr-pill aggr-pill--danger"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
								<?php if ( '' !== (string) $aggr_creative['notes'] ) : ?>
									<p><?php echo esc_html( (string) $aggr_creative['notes'] ); ?></p>
								<?php endif; ?>
								<p class="aggr-hint"><?php esc_html_e( 'This ad is not running. Select Update to supply a replacement.', 'aggressive-ads' ); ?></p>
							<?php elseif ( true !== $aggr_creative['approved'] ) : ?>
								<p><span class="aggr-pill aggr-pill--pending"><?php echo esc_html( (string) $aggr_creative['state_text'] ); ?></span></p>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</section>
	<?php endforeach; ?>
</div>
<?php
require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php';
