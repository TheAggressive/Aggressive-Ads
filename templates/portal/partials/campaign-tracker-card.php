<?php
/**
 * The optional click tracker, folded under the destination.
 *
 * Most campaigns have none, so it is a folded edit and not a section: drawing a
 * third field on every campaign for the few that need it would be a question
 * every advertiser has to read past. It opens by itself when a tracker is set or
 * the last save refused one — a link that lands on a closed fold looks like a
 * link that did nothing.
 *
 * **On a draft, its own form and its own save, not the destination's autosave.**
 * The autosave's script is written around one link field and its status; teaching
 * it a second would put a third party's address behind a latch built for
 * something else. This posts the same save action with only this field, which
 * `Campaign_Editor` validates through `Click_Tracker_Validation` like every other
 * write. The `autosave_rev` it carries is kept current by the autosave script,
 * which updates every such input on the page and holds other forms' submits
 * until its own write has landed.
 *
 * **On a running campaign, the destination form's save.** The Ads step already
 * stages a proposal through `aggr-changes-form`; a second form here would be a
 * second write of the same proposal. The field rides that form (`form=`), so
 * one "Save and continue" sends the landing page and the tracker together, and
 * neither reaches live traffic until staff accept the proposal.
 *
 * @var array<string, mixed> $aggr_campaign  The campaign.
 * @var string               $aggr_error_for The field the last refusal belongs to.
 * @var string               $aggr_dest_mode `draft` or `edit`.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_dest_editing  = 'edit' === ( $aggr_dest_mode ?? 'draft' );
$aggr_tracker       = $aggr_dest_editing
	? (string) ( $aggr_campaign['edit_tracker'] ?? '' )
	: (string) ( $aggr_campaign['click_tracker'] ?? '' );
$aggr_tracker_error = 'aggr-campaign-tracker' === ( $aggr_error_for ?? '' );
?>
<details class="aggr-tracker" <?php echo '' !== $aggr_tracker || $aggr_tracker_error ? 'open' : ''; ?>>
	<summary><?php esc_html_e( 'Use an ad-server click tracker (optional)', 'aggressive-ads' ); ?></summary>
	<?php if ( ! $aggr_dest_editing ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
	<?php endif; ?>

		<div class="aggr-field">
			<label for="aggr-campaign-tracker"><?php esc_html_e( 'Tracking link', 'aggressive-ads' ); ?></label>
			<p id="aggr-campaign-tracker-hint" class="aggr-hint">
				<?php
				echo $aggr_dest_editing
					? esc_html__( 'A click goes to this link first, then on to your destination. Put {lpurl} where your tracker expects the destination — exactly once. The campaign keeps using the current tracker until the review team accepts this change.', 'aggressive-ads' )
					: esc_html__( 'A click goes to this link first, then on to your destination. Put {lpurl} where your tracker expects the destination — exactly once. The review team sees this link before approving.', 'aggressive-ads' );
				?>
			</p>
			<input
				id="aggr-campaign-tracker"
				name="click_tracker"
				type="text"
				inputmode="url"
				autocapitalize="none"
				spellcheck="false"
				placeholder="https://tracker.example.com/click?url={lpurl}"
				value="<?php echo esc_attr( $aggr_tracker ); ?>"
				aria-describedby="aggr-campaign-tracker-hint"
				<?php echo $aggr_dest_editing ? 'form="aggr-changes-form"' : ''; ?>
				<?php echo $aggr_tracker_error ? 'aria-invalid="true"' : ''; ?>
			>
		</div>

	<?php if ( ! $aggr_dest_editing ) : ?>
		<div class="aggr-form__actions">
			<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save tracker', 'aggressive-ads' ); ?></button>
		</div>
		</form>
	<?php endif; ?>
</details>
