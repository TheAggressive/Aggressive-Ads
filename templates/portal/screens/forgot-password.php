<?php
/**
 * Password-recovery request contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Password_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;

$aggr_notice = Password_Actions::request_notice();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state, validated against the site host before output.
$aggr_redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
$aggr_redirect = '' === $aggr_redirect ? '' : wp_validate_redirect( $aggr_redirect, '' );
?>
<div class="aggr-signin">
	<div class="aggr-signin__brand">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</div>

	<div class="aggr-panel">
		<h1 class="aggr-panel__head"><?php esc_html_e( 'Reset your password', 'aggressive-ads' ); ?></h1>

		<?php if ( '' !== $aggr_notice ) : ?>
			<div class="aggr-alert <?php echo esc_attr( 'sent' === $aggr_notice ? 'aggr-alert--success' : 'aggr-alert--error' ); ?>" role="<?php echo esc_attr( 'sent' === $aggr_notice ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Password_Actions::request_message( $aggr_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'sent' !== $aggr_notice ) : ?>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Password_Actions::REQUEST_ACTION ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $aggr_redirect ); ?>">
				<?php wp_nonce_field( Password_Actions::REQUEST_ACTION ); ?>

				<div class="aggr-field">
					<label for="aggr-recovery-email"><?php esc_html_e( 'Work email', 'aggressive-ads' ); ?></label>
					<input id="aggr-recovery-email" name="email" type="email" autocomplete="email" maxlength="100" required>
				</div>

				<button class="aggr-button" type="submit"><?php esc_html_e( 'Email password link', 'aggressive-ads' ); ?></button>
			</form>
		<?php endif; ?>

		<p class="aggr-signin__aside">
			<a href="<?php echo esc_url( '' === $aggr_redirect ? Routes::url( Request::ROUTE_LOGIN ) : add_query_arg( 'redirect_to', $aggr_redirect, Routes::url( Request::ROUTE_LOGIN ) ) ); ?>"><?php esc_html_e( 'Back to sign in', 'aggressive-ads' ); ?></a>
		</p>
	</div>
</div>
