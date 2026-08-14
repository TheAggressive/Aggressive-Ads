<?php
/**
 * Sign-in contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Login_Actions;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Advertiser_Registration;

$aggr_notice = Login_Actions::request_notice();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state, re-validated against the site host on submission.
$aggr_redirect       = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
$aggr_redirect       = '' === $aggr_redirect ? Routes::url() : wp_validate_redirect( $aggr_redirect, Routes::url() );
$aggr_signup_enabled = Plugin::instance()->container()->get( Advertiser_Registration::class )->is_enabled();
?>
<div class="aggr-signin">
	<div class="aggr-signin__brand">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</div>

	<div class="aggr-panel">
		<h1 class="aggr-panel__head"><?php esc_html_e( 'Sign in', 'aggressive-ads' ); ?></h1>

		<?php if ( '' !== $aggr_notice ) : ?>
			<div class="aggr-alert <?php echo esc_attr( in_array( $aggr_notice, array( 'password_set', 'pending' ), true ) ? 'aggr-alert--success' : 'aggr-alert--error' ); ?>" role="<?php echo esc_attr( in_array( $aggr_notice, array( 'password_set', 'pending' ), true ) ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Login_Actions::notice_message( $aggr_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Login_Actions::LOGIN_ACTION ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $aggr_redirect ); ?>">
			<?php wp_nonce_field( Login_Actions::LOGIN_ACTION ); ?>

			<div class="aggr-field">
				<label for="aggr-log"><?php esc_html_e( 'Work email', 'aggressive-ads' ); ?></label>
				<input id="aggr-log" name="log" type="email" autocomplete="username" required autocapitalize="none" spellcheck="false">
			</div>

			<div class="aggr-field">
				<label for="aggr-pwd"><?php esc_html_e( 'Password', 'aggressive-ads' ); ?></label>
				<input id="aggr-pwd" name="pwd" type="password" autocomplete="current-password" required>
			</div>

			<div class="aggr-field aggr-field--inline">
				<input id="aggr-remember" name="rememberme" type="checkbox" value="forever">
				<label for="aggr-remember"><?php esc_html_e( 'Keep me signed in', 'aggressive-ads' ); ?></label>
			</div>

			<button class="aggr-button" type="submit"><?php esc_html_e( 'Sign in', 'aggressive-ads' ); ?></button>
		</form>

		<p class="aggr-signin__aside">
			<a href="<?php echo esc_url( add_query_arg( 'redirect_to', $aggr_redirect, Routes::url( Request::ROUTE_FORGOT_PASSWORD ) ) ); ?>">
				<?php esc_html_e( 'Forgotten your password?', 'aggressive-ads' ); ?>
			</a>
		</p>

		<?php if ( $aggr_signup_enabled ) : ?>
			<p class="aggr-signin__aside">
				<?php esc_html_e( 'New advertiser?', 'aggressive-ads' ); ?>
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_SIGNUP ) ); ?>"><?php esc_html_e( 'Create an account', 'aggressive-ads' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
