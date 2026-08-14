<?php
/**
 * One-time set-password contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Password_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Password_Reset;

$aggr_key       = Password_Actions::link_argument( 'key', 100 );
$aggr_login     = Password_Actions::link_argument( 'login', 60 );
$aggr_notice    = Password_Actions::set_notice();
$aggr_passwords = Plugin::instance()->container()->get( Password_Reset::class );
$aggr_user      = $aggr_passwords->validate( $aggr_key, $aggr_login );
$aggr_valid     = ! is_wp_error( $aggr_user );
?>
<div class="aggr-signin">
	<div class="aggr-signin__brand">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</div>

	<div class="aggr-panel">
		<h1 class="aggr-panel__head"><?php esc_html_e( 'Choose your password', 'aggressive-ads' ); ?></h1>

		<?php if ( ! $aggr_valid ) : ?>
			<div class="aggr-alert aggr-alert--error" role="alert">
				<p><?php esc_html_e( 'This password link is invalid or has expired. Request a new one.', 'aggressive-ads' ); ?></p>
			</div>
		<?php elseif ( 'invalid_password' === $aggr_notice ) : ?>
			<div class="aggr-alert aggr-alert--error" role="alert">
				<p><?php esc_html_e( 'Use matching passwords of at least 12 characters.', 'aggressive-ads' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $aggr_valid ) : ?>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Password_Actions::SET_ACTION ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $aggr_key ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( $aggr_login ); ?>">
				<?php wp_nonce_field( Password_Actions::SET_ACTION ); ?>

				<p class="aggr-hint"><?php esc_html_e( 'Use at least 12 characters. A passphrase is easier to remember and harder to guess.', 'aggressive-ads' ); ?></p>

				<div class="aggr-field">
					<label for="aggr-new-password"><?php esc_html_e( 'New password', 'aggressive-ads' ); ?></label>
					<input id="aggr-new-password" name="password" type="password" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Password_Reset::MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( (string) Password_Reset::MAX_LENGTH ); ?>" required>
				</div>

				<div class="aggr-field">
					<label for="aggr-confirm-password"><?php esc_html_e( 'Confirm new password', 'aggressive-ads' ); ?></label>
					<input id="aggr-confirm-password" name="password_confirmation" type="password" autocomplete="new-password" minlength="<?php echo esc_attr( (string) Password_Reset::MIN_LENGTH ); ?>" maxlength="<?php echo esc_attr( (string) Password_Reset::MAX_LENGTH ); ?>" required>
				</div>

				<button class="aggr-button" type="submit"><?php esc_html_e( 'Set password', 'aggressive-ads' ); ?></button>
			</form>
		<?php else : ?>
			<p class="aggr-signin__aside">
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_FORGOT_PASSWORD ) ); ?>"><?php esc_html_e( 'Request a new password link', 'aggressive-ads' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
</div>
