<?php
/**
 * Portal dialog overlays — one host, three bodies, shared chrome.
 *
 * Included from a campaign screen with `$aggr_overlays`. Enqueues the dialog
 * store and prints in wp_footer, outside .aggr-shell, so inert on the page
 * root does not trap the dialog. No-JS uses :target on each overlay id.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign Campaign row.
 * @var list<array{kind: string, id: string, creative: array<string, mixed>, placement?: string, close_href?: string}> $aggr_overlays Dialog specs. close_href is an element id, without '#'.
 * @var bool                 $aggr_overlay_print When true, render markup (footer). When absent, enqueue.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Creative_Actions;

$aggr_campaign = isset( $aggr_campaign ) && is_array( $aggr_campaign ) ? $aggr_campaign : array();
$aggr_overlays = isset( $aggr_overlays ) && is_array( $aggr_overlays ) ? $aggr_overlays : array();

if ( array() === $aggr_overlays ) {
	return;
}

if ( true !== ( $aggr_overlay_print ?? false ) ) {
	$aggr_dialog_state = array();

	foreach ( $aggr_overlays as $aggr_queued ) {
		if ( ! is_array( $aggr_queued ) ) {
			continue;
		}

		$aggr_dialog_id = (string) ( $aggr_queued['id'] ?? '' );

		if ( '' === $aggr_dialog_id ) {
			continue;
		}

		$aggr_dialog_state[ $aggr_dialog_id ] = array(
			'isOpen'            => false,
			'animationDuration' => 200,
		);
	}

	if ( array() === $aggr_dialog_state ) {
		return;
	}

	Plugin::instance()->container()->get( Assets::class )->enqueue_dialog( $aggr_dialog_state );

	$aggr_campaign_for_footer = $aggr_campaign;
	$aggr_overlays_for_footer = $aggr_overlays;

	add_action(
		'wp_footer',
		static function () use ( $aggr_campaign_for_footer, $aggr_overlays_for_footer ): void {
			$aggr_campaign      = $aggr_campaign_for_footer;
			$aggr_overlays      = $aggr_overlays_for_footer;
			$aggr_overlay_print = true;
			require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-overlays.php';
		},
		5
	);

	return;
}
?>
<div
	class="aggr-overlays"
	data-wp-interactive="<?php echo esc_attr( Assets::DIALOG_STORE ); ?>"
>
	<?php foreach ( $aggr_overlays as $aggr_overlay ) : ?>
		<?php
		if ( ! is_array( $aggr_overlay ) ) {
			continue;
		}

		$aggr_kind      = (string) ( $aggr_overlay['kind'] ?? '' );
		$aggr_dialog_id = (string) ( $aggr_overlay['id'] ?? '' );
		$aggr_creative  = is_array( $aggr_overlay['creative'] ?? null ) ? $aggr_overlay['creative'] : array();
		$aggr_placement = (string) ( $aggr_overlay['placement'] ?? ( $aggr_creative['placement'] ?? '' ) );

		if ( '' === $aggr_dialog_id || array() === $aggr_creative || ! in_array( $aggr_kind, array( 'preview', 'remove', 'replace' ), true ) ) {
			continue;
		}

		$aggr_close_href     = (string) ( $aggr_overlay['close_href'] ?? 'aggr-details-heading' );
		$aggr_label_id       = $aggr_dialog_id . '-label';
		$aggr_is_preview     = 'preview' === $aggr_kind;
		$aggr_dialog_context = wp_interactivity_data_wp_context(
			array(
				'dialogId' => $aggr_dialog_id,
			)
		);
		$aggr_opened_label   = match ( $aggr_kind ) {
			'preview' => sprintf(
				/* translators: %s: placement name. */
				__( 'Preview dialog opened for %s', 'aggressive-ads' ),
				$aggr_placement
			),
			'remove'  => sprintf(
				/* translators: %s: placement name. */
				__( 'Remove creative dialog opened for %s', 'aggressive-ads' ),
				$aggr_placement
			),
			default   => sprintf(
				/* translators: %s: placement name. */
				__( 'Update ad dialog opened for %s', 'aggressive-ads' ),
				$aggr_placement
			),
		};
	?>
		<div
			id="<?php echo esc_attr( $aggr_dialog_id ); ?>"
			class="aggr-overlay<?php echo $aggr_is_preview ? ' aggr-overlay--preview' : ''; ?>"
			data-dialog-id="<?php echo esc_attr( $aggr_dialog_id ); ?>"
			<?php echo $aggr_dialog_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
			data-wp-init="actions.init"
		>
			<div
				class="aggr-overlay__backdrop"
				data-aggr-dialog-close
				aria-hidden="true"
			></div>

			<div
				class="aggr-overlay__panel"
				role="dialog"
				aria-modal="true"
				aria-labelledby="<?php echo esc_attr( $aggr_label_id ); ?>"
				tabindex="-1"
			>
				<div class="aggr-overlay__header">
					<h2 id="<?php echo esc_attr( $aggr_label_id ); ?>" class="aggr-overlay__title">
						<?php
						if ( 'preview' === $aggr_kind ) {
							printf(
								/* translators: %s: placement name. */
								esc_html__( 'Preview %s', 'aggressive-ads' ),
								esc_html( $aggr_placement )
							);
						} elseif ( 'remove' === $aggr_kind ) {
							esc_html_e( 'Remove this creative?', 'aggressive-ads' );
						} else {
							printf(
								/* translators: %s: placement name. */
								esc_html__( 'Update %s', 'aggressive-ads' ),
								esc_html( $aggr_placement )
							);
						}
						?>
					</h2>
					<a
						class="aggr-overlay__close"
						href="#<?php echo esc_attr( $aggr_close_href ); ?>"
						aria-label="<?php esc_attr_e( 'Close', 'aggressive-ads' ); ?>"
						data-aggr-dialog-close
					>
						<?php
						$aggr_icon = 'close';
						require AGGR_PLUGIN_DIR . 'templates/portal/partials/icon.php';
						?>
					</a>
				</div>

				<div class="aggr-overlay__body">
					<?php if ( 'preview' === $aggr_kind ) : ?>
						<img
							class="aggr-overlay__preview-image"
							src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>"
							alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>"
						>
					<?php elseif ( 'remove' === $aggr_kind ) : ?>
						<p class="aggr-hint">
							<?php esc_html_e( 'This removes the file from the campaign. You can upload a replacement afterwards.', 'aggressive-ads' ); ?>
						</p>

						<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::REMOVE_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) ( $aggr_campaign['id'] ?? '' ) ); ?>">
							<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>">
							<?php wp_nonce_field( Creative_Actions::remove_nonce_action( (int) $aggr_creative['id'] ) ); ?>

							<div class="aggr-overlay__actions">
								<a
									class="aggr-button aggr-button--secondary"
									href="#<?php echo esc_attr( $aggr_close_href ); ?>"
									data-aggr-dialog-close
								>
									<?php esc_html_e( 'Cancel', 'aggressive-ads' ); ?>
								</a>
								<button class="aggr-button aggr-button--danger" type="submit"><?php esc_html_e( 'Remove creative', 'aggressive-ads' ); ?></button>
							</div>
						</form>
					<?php else : ?>
						<p class="aggr-hint">
							<?php esc_html_e( 'The current ad keeps running until staff approve this replacement.', 'aggressive-ads' ); ?>
						</p>

						<form class="aggr-form" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="post" enctype="multipart/form-data">
							<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::REPLACE_ACTION ); ?>">
							<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) ( $aggr_campaign['id'] ?? '' ) ); ?>">
							<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>">
							<?php wp_nonce_field( Creative_Actions::replace_nonce_action( (int) $aggr_creative['id'] ) ); ?>

							<div class="aggr-field">
								<label for="aggr-replacement-file-<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>"><?php esc_html_e( 'Replacement ad creative', 'aggressive-ads' ); ?></label>
								<input id="aggr-replacement-file-<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required>
								<p class="aggr-hint">
									<?php
									printf(
										/* translators: %s: required creative dimensions, for example 728x90. */
										esc_html__( 'Exactly %s. JPEG, PNG, GIF, or WebP.', 'aggressive-ads' ),
										esc_html( (string) $aggr_creative['size'] )
									);
									?>
								</p>
							</div>

							<div class="aggr-field">
								<label for="aggr-replacement-url-<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'aggressive-ads' ); ?></label>
								<input id="aggr-replacement-url-<?php echo esc_attr( (string) $aggr_creative['id'] ); ?>" name="click_url" type="url" value="<?php echo esc_attr( (string) $aggr_creative['click_url'] ); ?>" required>
							</div>

							<div class="aggr-overlay__actions">
								<a
									class="aggr-button aggr-button--secondary"
									href="#<?php echo esc_attr( $aggr_close_href ); ?>"
									data-aggr-dialog-close
								>
									<?php esc_html_e( 'Cancel', 'aggressive-ads' ); ?>
								</a>
								<button class="aggr-button" type="submit"><?php esc_html_e( 'Submit replacement for review', 'aggressive-ads' ); ?></button>
							</div>
						</form>
					<?php endif; ?>
				</div>
			</div>

			<div
				class="aggr-overlay__announcer"
				data-label="<?php echo esc_attr( $aggr_opened_label ); ?>"
				aria-live="polite"
			></div>
		</div>
		<?php
		unset( $aggr_dialog_context, $aggr_opened_label, $aggr_label_id, $aggr_is_preview );
		?>
	<?php endforeach; ?>
</div>
