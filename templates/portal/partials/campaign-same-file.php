<?php
/**
 * "Same file as …" on an empty size whose twin already has an ad.
 *
 * A package can sell two placements of one size, and the advertiser has one
 * file for both. Offered here, beside the upload form rather than instead of
 * it, because a second placement of a size is as often a different design.
 *
 * A plain form post that works without script. What it makes is a copy — a
 * creative of its own on this placement — so the two can later be replaced or
 * removed apart; `Workflow\Creative_Copies` says why.
 *
 * Scope is inherited from the caller.
 *
 * @var array<string, mixed>             $aggr_slot        The empty placement.
 * @var array<int, array<string, mixed>> $aggr_reuse_slots Every placement on the campaign, with its creatives.
 * @var array<string, mixed>             $aggr_campaign    The campaign being edited.
 * @var bool                             $aggr_upload_from_edit Drawn in a running campaign's edit flow.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Creative_Actions;
use Aggressive\Ads\Portal\Creative_Feedback;

$aggr_reuse_sources = array();

foreach ( $aggr_reuse_slots as $aggr_reuse_slot ) {
	if ( (int) $aggr_reuse_slot['id'] === (int) $aggr_slot['id'] || (string) $aggr_reuse_slot['size'] !== (string) $aggr_slot['size'] ) {
		continue;
	}

	foreach ( $aggr_reuse_slot['creatives'] as $aggr_reuse_creative ) {
		$aggr_reuse_sources[] = array(
			'id'        => (int) $aggr_reuse_creative['id'],
			'placement' => (string) $aggr_reuse_slot['name'],
			'name'      => (string) ( $aggr_reuse_creative['name'] ?? '' ),
		);
	}
}

if ( array() === $aggr_reuse_sources ) {
	return;
}

// A placement rotating several ads names each by file; one ad is named by its placement alone.
$aggr_reuse_count = array_count_values( array_column( $aggr_reuse_sources, 'placement' ) );
?>
<div class="aggr-same-file">
	<p class="aggr-hint"><?php esc_html_e( 'Same size as another placement. Use its file here too, or upload a different one below.', 'aggressive-ads' ); ?></p>
	<?php foreach ( $aggr_reuse_sources as $aggr_reuse_source ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::COPY_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="placement_id" value="<?php echo esc_attr( (string) $aggr_slot['id'] ); ?>">
			<input type="hidden" name="source_id" value="<?php echo esc_attr( (string) $aggr_reuse_source['id'] ); ?>">
			<?php wp_nonce_field( Creative_Actions::copy_nonce_action( (int) $aggr_campaign['id'], (int) $aggr_slot['id'] ) ); ?>
			<?php if ( true === ( $aggr_upload_from_edit ?? false ) ) : ?>
				<input type="hidden" name="<?php echo esc_attr( Creative_Feedback::RETURN_FIELD ); ?>" value="<?php echo esc_attr( Creative_Feedback::RETURN_EDIT ); ?>">
			<?php endif; ?>
			<button class="aggr-button aggr-button--secondary" type="submit">
				<?php
				echo esc_html(
					$aggr_reuse_count[ $aggr_reuse_source['placement'] ] > 1 && '' !== $aggr_reuse_source['name']
						/* translators: 1: a placement's name, e.g. Header. 2: a file name. */
						? sprintf( __( 'Same file as %1$s (%2$s)', 'aggressive-ads' ), $aggr_reuse_source['placement'], $aggr_reuse_source['name'] )
						/* translators: %s: a placement's name, e.g. Header. */
						: sprintf( __( 'Same file as %s', 'aggressive-ads' ), $aggr_reuse_source['placement'] )
				);
				?>
			</button>
		</form>
	<?php endforeach; ?>
</div>
