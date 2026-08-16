<?php
/**
 * Edits that are with the review team.
 *
 * Shown instead of the edit screen once a proposal has been submitted: a
 * reviewer must not be deciding a change the advertiser is still moving.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign The campaign being viewed.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;

$aggr_pending     = is_array( $aggr_campaign['pending_edits'] ?? null ) ? $aggr_campaign['pending_edits'] : array();
$aggr_campaign_id = (int) $aggr_campaign['id'];

if ( array() === $aggr_pending ) {
	return;
}
?>
<section class="aggr-panel" aria-labelledby="aggr-pending-changes-heading">
	<h2 id="aggr-pending-changes-heading" class="aggr-panel__head">
		<?php esc_html_e( 'Edits awaiting review', 'aggressive-ads' ); ?>
	</h2>

	<div class="aggr-alert aggr-alert--info" role="status">
		<p><?php esc_html_e( 'These edits are with the review team. Your campaign keeps running unchanged until they are approved.', 'aggressive-ads' ); ?></p>
	</div>

	<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Edits awaiting review', 'aggressive-ads' ); ?>" tabindex="0">
		<table class="aggr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Field', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Currently', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Requested', 'aggressive-ads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aggr_pending as $aggr_change ) : ?>
					<tr>
						<td class="aggr-table__primary"><?php echo esc_html( (string) $aggr_change['label'] ); ?></td>
						<td><?php echo esc_html( (string) $aggr_change['from'] ); ?></td>
						<td><?php echo esc_html( (string) $aggr_change['to'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_CANCEL ); ?>">
		<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign_id ); ?>">
		<?php wp_nonce_field( Campaign_Actions::cancel_changes_nonce_action( $aggr_campaign_id ) ); ?>
		<button class="aggr-button aggr-button--secondary" type="submit">
			<?php esc_html_e( 'Withdraw and keep editing', 'aggressive-ads' ); ?>
		</button>
	</form>
</section>
