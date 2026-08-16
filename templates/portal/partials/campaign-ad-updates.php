<?php
/**
 * Advertiser “Your ads” cards and replacement dialogs for scheduled/live campaigns.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed>       $aggr_campaign         Campaign row.
 * @var list<array<string, mixed>> $aggr_creatives        Creatives.
 * @var list<array<string, mixed>> $aggr_creative_updates Replacement history rows.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;

$aggr_campaign         = isset( $aggr_campaign ) && is_array( $aggr_campaign ) ? $aggr_campaign : array();
$aggr_creatives        = isset( $aggr_creatives ) && is_array( $aggr_creatives ) ? $aggr_creatives : array();
$aggr_creative_updates = isset( $aggr_creative_updates ) && is_array( $aggr_creative_updates ) ? $aggr_creative_updates : array();

if ( true !== ( $aggr_campaign['can_request_updates'] ?? false ) ) {
	return;
}

/*
 * Replace forms live in dialogs outside .aggr-shell (via wp_footer) so
 * inert on the page root does not trap the dialog itself. Update is an
 * in-page hash link for no-JS (:target); Interactivity preventDefault and
 * opens the shared dialog store when modules load.
 */
$aggr_overlays = array();
?>
<section
	class="aggr-panel"
	aria-labelledby="aggr-update-creatives-heading"
>
	<h2 id="aggr-update-creatives-heading" class="aggr-panel__head"><?php esc_html_e( 'Your ads', 'aggressive-ads' ); ?></h2>
	<p><?php esc_html_e( 'Select an ad to change its ad creative or destination. The current ad keeps running until staff approve its replacement.', 'aggressive-ads' ); ?></p>

	<div class="aggr-creative-grid">
		<?php foreach ( $aggr_creatives as $aggr_creative ) : ?>
			<?php
			$aggr_pending_update = null;

			foreach ( $aggr_creative_updates as $aggr_update ) {
				if ( (int) $aggr_update['creative_id'] === (int) $aggr_creative['id'] && 'pending' === (string) $aggr_update['state'] ) {
					$aggr_pending_update = $aggr_update;
					break;
				}
			}
			?>
			<?php if ( is_array( $aggr_pending_update ) ) : ?>
			<article class="aggr-creative aggr-creative--pending">
				<div class="aggr-creative__summary">
					<div class="aggr-creative__preview">
						<img src="<?php echo esc_url( (string) $aggr_pending_update['preview'] ); ?>" alt="" loading="lazy">
					</div>
					<div class="aggr-creative__meta">
						<strong><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></strong>
						<span><?php echo esc_html( (string) $aggr_creative['dimensions'] ); ?></span>
						<p><span class="aggr-pill aggr-pill--pending"><?php esc_html_e( 'Waiting for review', 'aggressive-ads' ); ?></span></p>
					</div>
				</div>
				<div class="aggr-creative__body">
					<p class="aggr-table__url"><?php echo esc_html( (string) $aggr_pending_update['click_url'] ); ?></p>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WITHDRAW_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
						<input type="hidden" name="replacement_id" value="<?php echo esc_attr( (string) $aggr_pending_update['id'] ); ?>">
						<?php wp_nonce_field( Creative_Actions::withdraw_nonce_action( (int) $aggr_pending_update['id'] ) ); ?>
						<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Withdraw update', 'aggressive-ads' ); ?></button>
					</form>
				</div>
			</article>
			<?php else : ?>
				<?php
				$aggr_dialog_id     = 'aggr-replace-' . (int) $aggr_creative['id'];
				$aggr_preview_id    = 'aggr-preview-' . (int) $aggr_creative['id'];
				$aggr_preview_label = sprintf(
					/* translators: %s: placement name. */
					__( 'View larger preview of %s', 'aggressive-ads' ),
					(string) $aggr_creative['placement']
				);
				$aggr_overlays[] = array(
					'kind'       => 'preview',
					'id'         => $aggr_preview_id,
					'creative'   => $aggr_creative,
					'placement'  => (string) $aggr_creative['placement'],
					'close_href' => 'aggr-update-creatives-heading',
				);
				$aggr_overlays[] = array(
					'kind'       => 'replace',
					'id'         => $aggr_dialog_id,
					'creative'   => $aggr_creative,
					'placement'  => (string) $aggr_creative['placement'],
					'close_href' => 'aggr-update-creatives-heading',
				);
				?>
			<article class="aggr-creative aggr-creative--editable">
				<div class="aggr-creative__summary">
					<a
						class="aggr-creative__preview"
						href="#<?php echo esc_attr( $aggr_preview_id ); ?>"
						aria-haspopup="dialog"
						aria-controls="<?php echo esc_attr( $aggr_preview_id ); ?>"
						aria-expanded="false"
						aria-label="<?php echo esc_attr( $aggr_preview_label ); ?>"
					>
						<img src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>" alt="" loading="lazy">
					</a>
					<div class="aggr-creative__meta">
						<strong><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></strong>
						<span><?php echo esc_html( (string) $aggr_creative['dimensions'] ); ?></span>
						<a
							class="aggr-creative__action"
							href="#<?php echo esc_attr( $aggr_dialog_id ); ?>"
							aria-haspopup="dialog"
							aria-controls="<?php echo esc_attr( $aggr_dialog_id ); ?>"
							aria-expanded="false"
						><?php esc_html_e( 'Update', 'aggressive-ads' ); ?></a>
					</div>
				</div>
			</article>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>
<?php
require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php';
