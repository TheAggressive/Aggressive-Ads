<?php
/**
 * Pause or resume one variant's delivery.
 *
 * Rendered only for a creative that actually has an assignment, and only while
 * that assignment is somewhere it can be paused from or resumed to. A creative
 * still awaiting review has nothing delivering to stop, and a withdrawn or
 * finished one is terminal — `Assignment_Rules::transitions()` is the authority
 * on both, and this asks it rather than listing statuses of its own.
 *
 * The button posts an *intent*, not a status. See
 * `Creative_Actions::process_status()` for why that distinction is the security
 * boundary rather than a naming preference.
 *
 * @var array<string, mixed> $aggr_creative One uploaded creative, with its assignment attached.
 * @var array<string, mixed> $aggr_campaign The campaign being edited.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Assignment_Rules;
use Aggressive\Ads\Portal\Creative_Actions;

$aggr_status        = (string) ( $aggr_creative['status'] ?? '' );
$aggr_assignment_id = (int) ( $aggr_creative['assignment_id'] ?? 0 );

if ( $aggr_assignment_id < 1 || '' === $aggr_status ) {
	return;
}

$aggr_paused = Assignment_Rules::PAUSED === $aggr_status;
$aggr_intent = $aggr_paused ? 'resume' : 'pause';
$aggr_target = $aggr_paused ? Assignment_Rules::LIVE : Assignment_Rules::PAUSED;

// The domain decides whether this button exists at all.
if ( ! Assignment_Rules::can_transition( $aggr_status, $aggr_target ) ) {
	return;
}
?>
<form class="aggr-variant-status" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::STATUS_ACTION ); ?>">
	<input type="hidden" name="assignment_id" value="<?php echo esc_attr( (string) $aggr_assignment_id ); ?>">
	<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) (int) $aggr_campaign['id'] ); ?>">
	<input type="hidden" name="revision" value="<?php echo esc_attr( (string) (int) ( $aggr_creative['revision'] ?? 0 ) ); ?>">
	<input type="hidden" name="intent" value="<?php echo esc_attr( $aggr_intent ); ?>">
	<?php wp_nonce_field( Creative_Actions::status_nonce_action( $aggr_assignment_id ) ); ?>

	<button class="aggr-button aggr-button--secondary" type="submit">
		<?php
		echo esc_html(
			$aggr_paused
				? __( 'Resume this creative', 'aggressive-ads' )
				: __( 'Pause this creative', 'aggressive-ads' )
		);
		?>
	</button>

	<?php if ( $aggr_paused ) : ?>
		<p class="aggr-variant-status__note">
			<?php esc_html_e( 'Paused. The other creatives on this placement take its share.', 'aggressive-ads' ); ?>
		</p>
	<?php endif; ?>
</form>
