<?php
/**
 * Sign-in contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Login_Actions;
use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Workflow\Advertiser_Registration;

$laao_ads_notice = Login_Actions::request_notice();

// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state, re-validated against the site host on submission.
$laao_ads_redirect       = isset( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
$laao_ads_redirect       = '' === $laao_ads_redirect ? Routes::url() : wp_validate_redirect( $laao_ads_redirect, Routes::url() );
$laao_ads_signup_enabled = Plugin::instance()->container()->get( Advertiser_Registration::class )->is_enabled();
?>
<div class="laao-ads-signin">
	<div class="laao-ads-signin__brand">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</div>

	<div class="laao-ads-panel">
		<h1 class="laao-ads-panel__head"><?php esc_html_e( 'Sign in', 'laao-advertiser-portal' ); ?></h1>

		<?php if ( '' !== $laao_ads_notice ) : ?>
			<div class="laao-ads-alert <?php echo esc_attr( in_array( $laao_ads_notice, array( 'password_set', 'pending' ), true ) ? 'laao-ads-alert--success' : 'laao-ads-alert--error' ); ?>" role="<?php echo esc_attr( in_array( $laao_ads_notice, array( 'password_set', 'pending' ), true ) ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Login_Actions::notice_message( $laao_ads_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Login_Actions::LOGIN_ACTION ); ?>">
			<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $laao_ads_redirect ); ?>">
			<?php wp_nonce_field( Login_Actions::LOGIN_ACTION ); ?>

			<div class="laao-ads-field">
				<label for="laao-ads-log"><?php esc_html_e( 'Work email', 'laao-advertiser-portal' ); ?></label>
				<input id="laao-ads-log" name="log" type="email" autocomplete="username" required autocapitalize="none" spellcheck="false">
			</div>

			<div class="laao-ads-field">
				<label for="laao-ads-pwd"><?php esc_html_e( 'Password', 'laao-advertiser-portal' ); ?></label>
				<input id="laao-ads-pwd" name="pwd" type="password" autocomplete="current-password" required>
			</div>

			<div class="laao-ads-field laao-ads-field--inline">
				<input id="laao-ads-remember" name="rememberme" type="checkbox" value="forever">
				<label for="laao-ads-remember"><?php esc_html_e( 'Keep me signed in', 'laao-advertiser-portal' ); ?></label>
			</div>

			<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Sign in', 'laao-advertiser-portal' ); ?></button>
		</form>

		<p class="laao-ads-signin__aside">
			<a href="<?php echo esc_url( add_query_arg( 'redirect_to', $laao_ads_redirect, Routes::url( Request::ROUTE_FORGOT_PASSWORD ) ) ); ?>">
				<?php esc_html_e( 'Forgotten your password?', 'laao-advertiser-portal' ); ?>
			</a>
		</p>

		<?php if ( $laao_ads_signup_enabled ) : ?>
			<p class="laao-ads-signin__aside">
				<?php esc_html_e( 'New advertiser?', 'laao-advertiser-portal' ); ?>
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_SIGNUP ) ); ?>"><?php esc_html_e( 'Create an account', 'laao-advertiser-portal' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
