<?php
/**
 * Password-recovery request contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Password_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;

$laao_ads_notice = Password_Actions::request_notice();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only navigation state, validated against the site host before output.
$laao_ads_redirect = isset( $_GET['redirect_to'] ) && is_string( $_GET['redirect_to'] ) ? sanitize_text_field( wp_unslash( $_GET['redirect_to'] ) ) : '';
$laao_ads_redirect = '' === $laao_ads_redirect ? '' : wp_validate_redirect( $laao_ads_redirect, '' );
?>
<div class="laao-ads-signin">
	<div class="laao-ads-signin__brand">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</div>

	<div class="laao-ads-panel">
		<h1 class="laao-ads-panel__head"><?php esc_html_e( 'Reset your password', 'laao-advertiser-portal' ); ?></h1>

		<?php if ( '' !== $laao_ads_notice ) : ?>
			<div class="laao-ads-alert <?php echo esc_attr( 'sent' === $laao_ads_notice ? 'laao-ads-alert--success' : 'laao-ads-alert--error' ); ?>" role="<?php echo esc_attr( 'sent' === $laao_ads_notice ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Password_Actions::request_message( $laao_ads_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( 'sent' !== $laao_ads_notice ) : ?>
			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Password_Actions::REQUEST_ACTION ); ?>">
				<input type="hidden" name="redirect_to" value="<?php echo esc_attr( $laao_ads_redirect ); ?>">
				<?php wp_nonce_field( Password_Actions::REQUEST_ACTION ); ?>

				<div class="laao-ads-field">
					<label for="laao-ads-recovery-email"><?php esc_html_e( 'Work email', 'laao-advertiser-portal' ); ?></label>
					<input id="laao-ads-recovery-email" name="email" type="email" autocomplete="email" maxlength="100" required>
				</div>

				<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Email password link', 'laao-advertiser-portal' ); ?></button>
			</form>
		<?php endif; ?>

		<p class="laao-ads-signin__aside">
			<a href="<?php echo esc_url( '' === $laao_ads_redirect ? Routes::url( Request::ROUTE_LOGIN ) : add_query_arg( 'redirect_to', $laao_ads_redirect, Routes::url( Request::ROUTE_LOGIN ) ) ); ?>"><?php esc_html_e( 'Back to sign in', 'laao-advertiser-portal' ); ?></a>
		</p>
	</div>
</div>
