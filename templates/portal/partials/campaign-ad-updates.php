<?php
/**
 * Advertiser “Your ads” cards and replacement dialogs for scheduled/live campaigns.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array<string, mixed>       $laao_ads_campaign         Campaign row.
 * @var list<array<string, mixed>> $laao_ads_creatives        Creatives.
 * @var list<array<string, mixed>> $laao_ads_creative_updates Replacement history rows.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Assets\Assets;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Creative_Actions;

$laao_ads_campaign         = isset( $laao_ads_campaign ) && is_array( $laao_ads_campaign ) ? $laao_ads_campaign : array();
$laao_ads_creatives        = isset( $laao_ads_creatives ) && is_array( $laao_ads_creatives ) ? $laao_ads_creatives : array();
$laao_ads_creative_updates = isset( $laao_ads_creative_updates ) && is_array( $laao_ads_creative_updates ) ? $laao_ads_creative_updates : array();

if ( true !== ( $laao_ads_campaign['can_request_updates'] ?? false ) ) {
	return;
}

/*
 * Replace forms live in dialogs outside .laao-ads-shell (via wp_footer) so
 * inert on the page root does not trap the dialog itself. Update is an
 * in-page hash link for no-JS (:target); Interactivity preventDefault and
 * opens the shared dialog store when modules load.
 */
$laao_ads_replace_dialogs = array();
?>
<section
	class="laao-ads-panel"
	aria-labelledby="laao-ads-update-creatives-heading"
>
	<h2 id="laao-ads-update-creatives-heading" class="laao-ads-panel__head"><?php esc_html_e( 'Your ads', 'laao-advertiser-portal' ); ?></h2>
	<p><?php esc_html_e( 'Select an ad to change its image or destination. The current ad keeps running until staff approve its replacement.', 'laao-advertiser-portal' ); ?></p>

	<div class="laao-ads-creative-grid">
		<?php foreach ( $laao_ads_creatives as $laao_ads_creative ) : ?>
			<?php
			$laao_ads_pending_update = null;

			foreach ( $laao_ads_creative_updates as $laao_ads_update ) {
				if ( (int) $laao_ads_update['creative_id'] === (int) $laao_ads_creative['id'] && 'pending' === (string) $laao_ads_update['state'] ) {
					$laao_ads_pending_update = $laao_ads_update;
					break;
				}
			}
			?>
			<?php if ( is_array( $laao_ads_pending_update ) ) : ?>
			<article class="laao-ads-creative laao-ads-creative--pending">
				<div class="laao-ads-creative__summary">
					<div class="laao-ads-creative__preview">
						<img src="<?php echo esc_url( (string) $laao_ads_pending_update['preview'] ); ?>" alt="" loading="lazy">
					</div>
					<div class="laao-ads-creative__meta">
						<strong><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></strong>
						<span><?php echo esc_html( (string) $laao_ads_creative['dimensions'] ); ?></span>
						<p><span class="laao-ads-pill laao-ads-pill--pending"><?php esc_html_e( 'Waiting for review', 'laao-advertiser-portal' ); ?></span></p>
					</div>
				</div>
				<div class="laao-ads-creative__body">
					<p class="laao-ads-table__url"><?php echo esc_html( (string) $laao_ads_pending_update['click_url'] ); ?></p>
					<form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post">
						<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::WITHDRAW_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
						<input type="hidden" name="replacement_id" value="<?php echo esc_attr( (string) $laao_ads_pending_update['id'] ); ?>">
						<?php wp_nonce_field( Creative_Actions::withdraw_nonce_action( (int) $laao_ads_pending_update['id'] ) ); ?>
						<button class="laao-ads-button laao-ads-button--secondary" type="submit"><?php esc_html_e( 'Withdraw update', 'laao-advertiser-portal' ); ?></button>
					</form>
				</div>
			</article>
			<?php else : ?>
				<?php
				$laao_ads_dialog_id         = 'laao-ads-replace-' . (int) $laao_ads_creative['id'];
				$laao_ads_replace_dialogs[] = array(
					'id'       => $laao_ads_dialog_id,
					'creative' => $laao_ads_creative,
				);
				?>
			<article class="laao-ads-creative laao-ads-creative--editable">
				<div class="laao-ads-creative__summary">
					<div class="laao-ads-creative__preview">
						<img src="<?php echo esc_url( (string) $laao_ads_creative['preview'] ); ?>" alt="" loading="lazy">
					</div>
					<div class="laao-ads-creative__meta">
						<strong><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></strong>
						<span><?php echo esc_html( (string) $laao_ads_creative['dimensions'] ); ?></span>
						<a
							class="laao-ads-creative__action"
							href="#<?php echo esc_attr( $laao_ads_dialog_id ); ?>"
							aria-haspopup="dialog"
							aria-controls="<?php echo esc_attr( $laao_ads_dialog_id ); ?>"
							aria-expanded="false"
						><?php esc_html_e( 'Update', 'laao-advertiser-portal' ); ?></a>
					</div>
				</div>
			</article>
			<?php endif; ?>
		<?php endforeach; ?>
	</div>
</section>
<?php
if ( array() === $laao_ads_replace_dialogs ) {
	return;
}

$laao_ads_dialog_state = array();

foreach ( $laao_ads_replace_dialogs as $laao_ads_replace_dialog ) {
	$laao_ads_dialog_state[ $laao_ads_replace_dialog['id'] ] = array(
		'isOpen'            => false,
		'animationDuration' => 200,
	);
}

Plugin::instance()->container()->get( Assets::class )->enqueue_dialog( $laao_ads_dialog_state );

$laao_ads_campaign_for_dialogs = $laao_ads_campaign;
$laao_ads_dialogs_for_footer   = $laao_ads_replace_dialogs;

add_action(
	'wp_footer',
	static function () use ( $laao_ads_campaign_for_dialogs, $laao_ads_dialogs_for_footer ): void {
		$laao_ads_campaign = $laao_ads_campaign_for_dialogs;
		$laao_ads_dialogs  = $laao_ads_dialogs_for_footer;
		require LAAO_ADS_PLUGIN_DIR . 'templates/portal/partials/creative-replace-dialogs.php';
	},
	5
);
