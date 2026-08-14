<?php
/**
 * Confirm a pending email change.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Workflow\Email_Change;

$aggr_key       = Email_Change_Actions::link_argument( 'key', 100 );
$aggr_login     = Email_Change_Actions::link_argument( 'login', 60 );
$aggr_notice    = Email_Change_Actions::confirm_notice();
$aggr_error     = Email_Change_Actions::confirm_error_code();
$aggr_changes   = Plugin::instance()->container()->get( Email_Change::class );
$aggr_user      = wp_get_current_user();
$aggr_signed    = is_user_logged_in() && $aggr_user->ID > 0;
$aggr_match     = $aggr_signed
	&& (string) $aggr_user->user_login === $aggr_login
	&& $aggr_changes->token_matches( (int) $aggr_user->ID, $aggr_key );
$aggr_login_url = add_query_arg(
	'redirect_to',
	rawurlencode(
		add_query_arg(
			array(
				'key'   => $aggr_key,
				'login' => $aggr_login,
			),
			Routes::url( Request::ROUTE_CONFIRM_EMAIL )
		)
	),
	Routes::url( Request::ROUTE_LOGIN )
);
?>
<div class="aggr-signin">
	<div class="aggr-signin__brand">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</div>

	<div class="aggr-panel">
		<h1 class="aggr-panel__head"><?php esc_html_e( 'Confirm your email', 'aggressive-ads' ); ?></h1>

		<?php if ( 'error' === $aggr_notice ) : ?>
			<div class="aggr-alert aggr-alert--error" role="alert">
				<p><?php echo esc_html( Email_Change_Actions::error_message( $aggr_error ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $aggr_signed ) : ?>
			<p class="aggr-hint"><?php esc_html_e( 'Sign in with your current account to finish changing your email address.', 'aggressive-ads' ); ?></p>
			<p class="aggr-signin__aside">
				<a href="<?php echo esc_url( $aggr_login_url ); ?>"><?php esc_html_e( 'Sign in to confirm', 'aggressive-ads' ); ?></a>
			</p>
		<?php elseif ( ! $aggr_match ) : ?>
			<div class="aggr-alert aggr-alert--error" role="alert">
				<p><?php esc_html_e( 'This confirmation link is invalid, has expired, or belongs to a different account.', 'aggressive-ads' ); ?></p>
			</div>
			<p class="aggr-signin__aside">
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_ACCOUNT ) ); ?>"><?php esc_html_e( 'Back to account', 'aggressive-ads' ); ?></a>
			</p>
		<?php else : ?>
			<p class="aggr-hint"><?php esc_html_e( 'Confirm to make this the email address you use to sign in.', 'aggressive-ads' ); ?></p>

			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Email_Change_Actions::CONFIRM_ACTION ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $aggr_key ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( $aggr_login ); ?>">
				<?php wp_nonce_field( Email_Change_Actions::CONFIRM_ACTION ); ?>

				<button class="aggr-button" type="submit"><?php esc_html_e( 'Confirm email change', 'aggressive-ads' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
