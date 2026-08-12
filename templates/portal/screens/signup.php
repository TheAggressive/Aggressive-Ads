<?php
/**
 * Advertiser signup contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Portal\Signup_Actions;
use LAAO_Advertiser_Portal\Workflow\Advertiser_Registration;

$laao_ads_registration = Plugin::instance()->container()->get( Advertiser_Registration::class );
$laao_ads_notice       = Signup_Actions::request_notice();
$laao_ads_enabled      = $laao_ads_registration->is_enabled();
$laao_ads_complete     = 'sent' === $laao_ads_notice;
$laao_ads_invite_token = Signup_Actions::request_invite_token();
?>
<div class="laao-ads-signin laao-ads-signup">
	<div class="laao-ads-signin__brand">
		<span class="laao-ads-brand__mark">LAAO</span>
		<span class="laao-ads-brand__sub"><?php esc_html_e( 'Advertiser Portal', 'laao-advertiser-portal' ); ?></span>
	</div>

	<div class="laao-ads-panel">
		<h1 class="laao-ads-panel__head"><?php esc_html_e( 'Create an advertiser account', 'laao-advertiser-portal' ); ?></h1>

		<?php if ( '' !== $laao_ads_invite_token && '' === $laao_ads_notice ) : ?>
			<div class="laao-ads-alert" role="status">
				<p><?php esc_html_e( 'Complete your organization invitation. Use the work email address that received the invitation.', 'laao-advertiser-portal' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $laao_ads_notice ) : ?>
			<div class="laao-ads-alert <?php echo esc_attr( 'sent' === $laao_ads_notice ? 'laao-ads-alert--success' : 'laao-ads-alert--error' ); ?>" role="<?php echo esc_attr( 'sent' === $laao_ads_notice ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Signup_Actions::notice_message( $laao_ads_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $laao_ads_enabled && ! $laao_ads_complete ) : ?>
			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Signup_Actions::SIGNUP_ACTION ); ?>">
				<input type="hidden" name="invite_token" value="<?php echo esc_attr( $laao_ads_invite_token ); ?>">
				<?php wp_nonce_field( Signup_Actions::SIGNUP_ACTION ); ?>

				<div class="laao-ads-formgrid">
					<div class="laao-ads-field">
						<label for="laao-ads-first-name"><?php esc_html_e( 'First name', 'laao-advertiser-portal' ); ?></label>
						<input id="laao-ads-first-name" name="first_name" type="text" autocomplete="given-name" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_PERSON_NAME ); ?>" required>
					</div>

					<div class="laao-ads-field">
						<label for="laao-ads-last-name"><?php esc_html_e( 'Last name', 'laao-advertiser-portal' ); ?></label>
						<input id="laao-ads-last-name" name="last_name" type="text" autocomplete="family-name" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_PERSON_NAME ); ?>" required>
					</div>
				</div>

				<?php if ( '' === $laao_ads_invite_token ) : ?>
					<div class="laao-ads-field">
						<label for="laao-ads-organization"><?php esc_html_e( 'Organization', 'laao-advertiser-portal' ); ?></label>
						<p id="laao-ads-organization-hint" class="laao-ads-hint"><?php esc_html_e( 'We check for a matching organization. An owner must approve access before you can join an existing one.', 'laao-advertiser-portal' ); ?></p>
						<input id="laao-ads-organization" class="laao-ads-input--uppercase" name="organization_name" type="text" autocomplete="organization" aria-describedby="laao-ads-organization-hint" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_ORG_NAME ); ?>" autocapitalize="characters" required>
					</div>
				<?php endif; ?>

				<div class="laao-ads-field">
					<label for="laao-ads-email"><?php esc_html_e( 'Work email', 'laao-advertiser-portal' ); ?></label>
					<p id="laao-ads-email-hint" class="laao-ads-hint"><?php esc_html_e( 'We will send a one-time password setup link to this address.', 'laao-advertiser-portal' ); ?></p>
					<input id="laao-ads-email" name="email" type="email" autocomplete="email" aria-describedby="laao-ads-email-hint" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_EMAIL ); ?>" required>
				</div>

				<div class="laao-ads-form-trap" aria-hidden="true">
					<label for="laao-ads-company-website"><?php esc_html_e( 'Company website', 'laao-advertiser-portal' ); ?></label>
					<input id="laao-ads-company-website" name="company_website" type="text" tabindex="-1" autocomplete="off">
				</div>

				<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Create account', 'laao-advertiser-portal' ); ?></button>
			</form>
		<?php elseif ( ! $laao_ads_enabled && 'unavailable' !== $laao_ads_notice ) : ?>
			<div class="laao-ads-alert laao-ads-alert--error" role="status">
				<p><?php echo esc_html( Signup_Actions::notice_message( 'unavailable' ) ); ?></p>
			</div>
		<?php endif; ?>

		<p class="laao-ads-signin__aside">
			<?php esc_html_e( 'Already have an account?', 'laao-advertiser-portal' ); ?>
			<a href="<?php echo esc_url( Routes::url( Request::ROUTE_LOGIN ) ); ?>"><?php esc_html_e( 'Sign in', 'laao-advertiser-portal' ); ?></a>
		</p>
	</div>
</div>
