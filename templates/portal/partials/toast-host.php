<?php
/**
 * The region every portal notice is written into.
 *
 * Its own partial because both layouts need it and neither should own it: the
 * bare auth pages use `base-bare.php`, and a notice raised on one of those had
 * nowhere to go until this was shared.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Portal_Notice;

?>
<div class="aggr-toasts">
	<?php
	/*
	 * Two regions, not one.
	 *
	 * A reader has to be interrupted by a refusal and must not be interrupted
	 * by "Saved." — and a single region cannot be both, because `aria-live`
	 * belongs to the container rather than to what is put inside it. Splitting
	 * them also keeps any role off the notices themselves: a live region
	 * nested in a live region is how one message gets announced twice.
	 */
	$aggr_queued = Portal_Notice::pending();

	foreach ( array( 'polite', 'assertive' ) as $aggr_region ) :
		$aggr_region_levels = 'polite' === $aggr_region
			? array( 'success', 'info' )
			: array( 'error', 'warning' );
		?>
		<div
			class="aggr-toasts__region"
			role="<?php echo 'polite' === $aggr_region ? 'status' : 'alert'; ?>"
			aria-live="<?php echo esc_attr( $aggr_region ); ?>"
		>
			<?php
			foreach ( $aggr_queued as $aggr_notice_item ) {
				if ( ! in_array( $aggr_notice_item['level'], $aggr_region_levels, true ) ) {
					continue;
				}

				$aggr_toast_text  = $aggr_notice_item['text'];
				$aggr_toast_level = $aggr_notice_item['level'];
				$aggr_toast_href  = $aggr_notice_item['href'];

				require AGGR_PLUGIN_DIR . 'templates/portal/partials/toast.php';
			}
			?>
		</div>
		<?php
	endforeach;
	?>
</div>
