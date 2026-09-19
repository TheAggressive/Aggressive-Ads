<?php
/**
 * The Destination card: one link for every ad, its check and its tags.
 *
 * Creation's card, drawn by creation and by the running-campaign edit flow.
 * Two copies of it would be how one of them stops checking links.
 *
 * **What differs is where the link goes.** While drafting it is saved on the
 * campaign as it is typed, by autosave. On a running campaign it is part of a
 * proposal: the form stages it with the rest of the edit, and the check stages
 * it first and then asks about the staged link — the route still never takes
 * a URL.
 *
 * Scope is inherited from the step that requires it.
 *
 * @var array<string, mixed>      $aggr_campaign   The campaign.
 * @var string                    $aggr_dest_mode  `draft` or `edit`.
 * @var string                    $aggr_dest_link  The link the field shows.
 * @var int                       $aggr_dest_used  Ads that go to it.
 * @var int                       $aggr_dest_total Ads, or sizes while drafting.
 * @var array<string, mixed>|null $aggr_dest_check The last check of this link.
 * @var string                    $aggr_dest_next  The edit step to move to on saving.
 * @var string                    $aggr_wizard_id        Autosave id, while drafting.
 * @var string                    $aggr_autosave_context Autosave store context attribute, while drafting.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Domain\Click_Macros;
use Aggressive\Ads\Domain\Link_Check_Rules;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\REST\Api;

$aggr_dest_editing   = 'edit' === $aggr_dest_mode;
$aggr_dest_next      = isset( $aggr_dest_next ) ? (string) $aggr_dest_next : 'review';
$aggr_error_for      = isset( $aggr_error_for ) ? (string) $aggr_error_for : '';
$aggr_dest_check_url = rest_url( Api::NAMESPACE . '/campaigns/' . (int) $aggr_campaign['id'] . '/link-check' );

// Which stored link, never a link: the one staged in the proposal.
if ( $aggr_dest_editing ) {
	$aggr_dest_check_url = add_query_arg( 'proposed', '1', $aggr_dest_check_url );
}

/*
 * One link for every ad. Every size card starts from it and can still change
 * its own. The check and the tag builder beside it are conveniences: the link
 * is saved and the step is finished without either.
 */
?>
<section class="aggr-ads-section" aria-labelledby="aggr-ads-link-heading">
	<div class="aggr-ads-section__head">
		<div>
			<p class="aggr-eyebrow"><?php esc_html_e( '01 · Destination', 'aggressive-ads' ); ?></p>
			<h3 id="aggr-ads-link-heading"><?php esc_html_e( 'Where should people go when they click?', 'aggressive-ads' ); ?></h3>
			<p class="aggr-hint">
				<?php
				echo $aggr_dest_editing
					? esc_html__( 'One link for every ad. Changing it moves every ad that uses it once the review team accepts it; an ad with its own link keeps it.', 'aggressive-ads' )
					: esc_html__( 'One link for every ad. Any single size can still have its own.', 'aggressive-ads' );
				?>
			</p>
		</div>
	</div>
	<form
		<?php echo $aggr_dest_editing ? 'id="aggr-changes-form" data-aggr-stage="1"' : ''; ?>
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		<?php if ( ! $aggr_dest_editing ) : ?>
			data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
			<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
			data-aggr-autosave="<?php echo esc_attr( $aggr_wizard_id ); ?>"
			data-wp-init="actions.init"
		<?php endif; ?>
	>
		<?php if ( $aggr_dest_editing ) : ?>
			<?php // A proposal, staged like every other step of the edit flow. ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="next_step" value="<?php echo esc_attr( $aggr_dest_next ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::changes_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
		<?php endif; ?>

		<?php
		/*
		 * The field, its check and its tags, from the partial an ad's own
		 * destination dialog draws as well. One copy: a fix to the checking,
		 * the tidying or the tag builder has to reach both, and two copies is
		 * how it reaches one.
		 */
		$aggr_dest_field_id    = 'aggr-campaign-link';
		$aggr_dest_field_name  = 'default_click_url';
		$aggr_dest_field_value = $aggr_dest_link;
		$aggr_dest_field_label = __( 'Destination link for every ad', 'aggressive-ads' );
		$aggr_dest_field_check = $aggr_dest_check;
		$aggr_dest_field_error = 'aggr-campaign-link' === $aggr_error_for;
		$aggr_dest_field_used  = sprintf(
			/* translators: 1: sizes whose ad goes to this link. 2: sizes in the package. */
			_n( 'Used by %1$d of %2$d ad', 'Used by %1$d of %2$d ads', $aggr_dest_total, 'aggressive-ads' ),
			(int) $aggr_dest_used,
			(int) $aggr_dest_total
		);
		$aggr_dest_field_save      = $aggr_dest_editing ? '' : __( 'Save link', 'aggressive-ads' );
		$aggr_dest_field_check_url = $aggr_dest_check_url;

		require AGGR_PLUGIN_DIR . 'templates/portal/partials/destination-field.php';
		?>
	</form>
</section>
