<?php
/**
 * One notice, rendered where notices go.
 *
 * Server-rendered rather than announced by script, so a notice that follows a
 * redirect survives having no JavaScript: the message is in the HTML, it is in
 * the live region, and it carries its own dismiss control. The module only
 * adds the timer, and only for a success.
 *
 * **Severity is never carried by colour alone.** Each level has its own left
 * edge and its own leading word, because a reader who cannot separate green
 * from red still has to be able to tell a save from a refusal.
 *
 * Carries no ARIA role of its own. The region it is placed into is the live
 * region — polite for what merely reports, assertive for what has to be acted
 * on — and a role here would nest one live region inside another, which is
 * how a single notice comes to be announced twice.
 *
 * @var string $aggr_toast_text  The sentence to show.
 * @var string $aggr_toast_level success | error | warning | info.
 * @var string $aggr_toast_href  Optional element id to link to, without '#'.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_toast_text  = isset( $aggr_toast_text ) ? (string) $aggr_toast_text : '';
$aggr_toast_level = isset( $aggr_toast_level ) ? (string) $aggr_toast_level : 'info';
$aggr_toast_href  = isset( $aggr_toast_href ) ? (string) $aggr_toast_href : '';

if ( '' === $aggr_toast_text ) {
	return;
}

if ( ! in_array( $aggr_toast_level, array( 'success', 'error', 'warning', 'info' ), true ) ) {
	$aggr_toast_level = 'info';
}

$aggr_toast_label = match ( $aggr_toast_level ) {
	'success' => __( 'Success', 'aggressive-ads' ),
	'error'   => __( 'Error', 'aggressive-ads' ),
	'warning' => __( 'Warning', 'aggressive-ads' ),
	default   => __( 'Notice', 'aggressive-ads' ),
};
?>
<div
	class="aggr-toast aggr-toast--<?php echo esc_attr( $aggr_toast_level ); ?>"
	data-aggr-toast="<?php echo esc_attr( $aggr_toast_level ); ?>"
>
	<p class="aggr-toast__text">
		<span class="aggr-sr"><?php echo esc_html( $aggr_toast_label ); ?>: </span>
		<?php
		/*
		 * A refusal that names a field links to it. Moving these into a
		 * notice that goes away must not cost the one thing the banner did
		 * well: taking somebody to the control that needs their attention.
		 */
		?>
		<?php if ( '' !== $aggr_toast_href ) : ?>
			<a href="#<?php echo esc_attr( $aggr_toast_href ); ?>"><?php echo esc_html( $aggr_toast_text ); ?></a>
		<?php else : ?>
			<?php echo esc_html( $aggr_toast_text ); ?>
		<?php endif; ?>
	</p>

	<button
		class="aggr-toast__close"
		type="button"
		data-aggr-toast-close
		aria-label="<?php esc_attr_e( 'Dismiss this notice', 'aggressive-ads' ); ?>"
	>&times;</button>
</div>
