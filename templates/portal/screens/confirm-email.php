<?php
/**
 * Confirm a pending email change.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Email_Change_Actions;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Workflow\Email_Change;

$laao_ads_key       = Email_Change_Actions::link_argument( 'key', 100 );
$laao_ads_login     = Email_Change_Actions::link_argument( 'login', 60 );
$laao_ads_notice    = Email_Change_Actions::confirm_notice();
$laao_ads_error     = Email_Change_Actions::confirm_error_code();
$laao_ads_changes   = Plugin::instance()->container()->get( Email_Change::class );
$laao_ads_user      = wp_get_current_user();
$laao_ads_signed    = is_user_logged_in() && $laao_ads_user->ID > 0;
$laao_ads_match     = $laao_ads_signed
	&& (string) $laao_ads_user->user_login === $laao_ads_login
	&& $laao_ads_changes->token_matches( (int) $laao_ads_user->ID, $laao_ads_key );
$laao_ads_login_url = add_query_arg(
	'redirect_to',
	rawurlencode(
		add_query_arg(
			array(
				'key'   => $laao_ads_key,
				'login' => $laao_ads_login,
			),
			Routes::url( Request::ROUTE_CONFIRM_EMAIL )
		)
	),
	Routes::url( Request::ROUTE_LOGIN )
);
?>
<div class="laao-ads-signin">
	<div class="laao-ads-signin__brand">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</div>

	<div class="laao-ads-panel">
		<h1 class="laao-ads-panel__head"><?php esc_html_e( 'Confirm your email', 'laao-advertiser-portal' ); ?></h1>

		<?php if ( 'error' === $laao_ads_notice ) : ?>
			<div class="laao-ads-alert laao-ads-alert--error" role="alert">
				<p><?php echo esc_html( Email_Change_Actions::error_message( $laao_ads_error ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( ! $laao_ads_signed ) : ?>
			<p class="laao-ads-hint"><?php esc_html_e( 'Sign in with your current account to finish changing your email address.', 'laao-advertiser-portal' ); ?></p>
			<p class="laao-ads-signin__aside">
				<a href="<?php echo esc_url( $laao_ads_login_url ); ?>"><?php esc_html_e( 'Sign in to confirm', 'laao-advertiser-portal' ); ?></a>
			</p>
		<?php elseif ( ! $laao_ads_match ) : ?>
			<div class="laao-ads-alert laao-ads-alert--error" role="alert">
				<p><?php esc_html_e( 'This confirmation link is invalid, has expired, or belongs to a different account.', 'laao-advertiser-portal' ); ?></p>
			</div>
			<p class="laao-ads-signin__aside">
				<a href="<?php echo esc_url( Routes::url( Request::ROUTE_ACCOUNT ) ); ?>"><?php esc_html_e( 'Back to account', 'laao-advertiser-portal' ); ?></a>
			</p>
		<?php else : ?>
			<p class="laao-ads-hint"><?php esc_html_e( 'Confirm to make this the email address you use to sign in.', 'laao-advertiser-portal' ); ?></p>

			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Email_Change_Actions::CONFIRM_ACTION ); ?>">
				<input type="hidden" name="key" value="<?php echo esc_attr( $laao_ads_key ); ?>">
				<input type="hidden" name="login" value="<?php echo esc_attr( $laao_ads_login ); ?>">
				<?php wp_nonce_field( Email_Change_Actions::CONFIRM_ACTION ); ?>

				<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Confirm email change', 'laao-advertiser-portal' ); ?></button>
			</form>
		<?php endif; ?>
	</div>
</div>
