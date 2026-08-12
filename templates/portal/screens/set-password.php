<?php
/**
 * One-time set-password contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Password_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Workflow\Password_Reset;

$laao_ads_key       = Password_Actions::link_argument( 'key', 100 );
$laao_ads_login     = Password_Actions::link_argument( 'login', 60 );
$laao_ads_notice    = Password_Actions::set_notice();
$laao_ads_passwords = Plugin::instance()->container()->get( Password_Reset::class );
$laao_ads_user      = $laao_ads_passwords->validate( $laao_ads_key, $laao_ads_login );
$laao_ads_valid     = ! is_wp_error( $laao_ads_user );
?>
<div class="laao-ads-signin">
	<div class="laao-ads-signin__brand">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</div>

	<div class="laao-ads-panel">
		<h1 class="laao-ads-panel__head"><?php esc_html_e( 'Choose your password', 'laao-advertiser-portal' ); ?></h1>

		<?php if ( ! $laao_ads_valid ) : ?>
			<div class="laao-ads-alert laao-ads-alert--error" role="alert">
				<p><?php esc_html_e( 'This password link is invalid or has expired. Request a new one.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php elseif ( 'invalid_password' === $laao_ads_notice ) : ?>
			<div class="laao-ads-alert laao-ads-alert--error" role="alert">
				<p><?php esc_html_e( 'Use matching passwords of at least 12 characters.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $laao_ads_valid ) : ?>
			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Password_Actions::SET_ACTION ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $laao_ads_key ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( $laao_ads_login ); ?>">
				<?php wp_nonce_field( Password_Actions::SET_ACTION ); ?>

				<p class="laao-ads-hint"><?php esc_html_e( 'Use at least 12 characters. A passphrase is easier to remember and harder to guess.', 'laao-advertiser-portal' ); ?></p>

				<div class="laao-ads-field">
					<label for="laao-ads-new-password"><?php esc_html_e( 'New password', 'laao-advertiser-portal' ); ?></label>
					<input id="laao-ads-new-password" name="password" type="password" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Password_Reset::MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( (string) Password_Reset::MAX_LENGTH ); ?>" required>
				</div>

				<div class="laao-ads-field">
					<label for="laao-ads-confirm-password"><?php esc_html_e( 'Confirm new password', 'laao-advertiser-portal' ); ?></label>
					<input id="laao-ads-confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Password_Reset::MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( (string) Password_Reset::MAX_LENGTH ); ?>" required>
				</div>

				<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Set password', 'laao-advertiser-portal' ); ?></button>
			</form>
		<?php else : ?>
			<p class="laao-ads-signin__aside">
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_FORGOT_PASSWORD ) ); ?>"><?php esc_html_e( 'Request a new password link', 'laao-advertiser-portal' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
