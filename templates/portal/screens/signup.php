<?php
/**
 * Advertiser signup contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\Signup_Actions;
use Aggressive\Ads\Workflow\Advertiser_Registration;

$aggr_registration = Plugin::instance()->container()->get( Advertiser_Registration::class );
$aggr_notice       = Signup_Actions::request_notice();
$aggr_invite_token = Signup_Actions::request_invite_token();
$aggr_enabled      = $aggr_registration->is_enabled() || '' !== $aggr_invite_token;
$aggr_complete     = 'sent' === $aggr_notice;
?>
<div class="aggr-signin aggr-signup">
	<div class="aggr-signin__brand">
		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/brand.php'; ?>
	</div>

	<div class="aggr-panel">
		<h1 class="aggr-panel__head"><?php esc_html_e( 'Create an advertiser account', 'aggressive-ads' ); ?></h1>

		<?php if ( '' !== $aggr_invite_token && '' === $aggr_notice ) : ?>
			<div class="aggr-alert" role="status">
				<p><?php esc_html_e( 'Complete your organization invitation. Use the work email address that received the invitation.', 'aggressive-ads' ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( '' !== $aggr_notice ) : ?>
			<div class="aggr-alert <?php echo esc_attr( 'sent' === $aggr_notice ? 'aggr-alert--success' : 'aggr-alert--error' ); ?>" role="<?php echo esc_attr( 'sent' === $aggr_notice ? 'status' : 'alert' ); ?>">
				<p><?php echo esc_html( Signup_Actions::notice_message( $aggr_notice ) ); ?></p>
			</div>
		<?php endif; ?>

		<?php if ( $aggr_enabled && ! $aggr_complete ) : ?>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Signup_Actions::SIGNUP_ACTION ); ?>">
				<input type="hidden" name="invite_token" value="<?php echo esc_attr( $aggr_invite_token ); ?>">
				<?php wp_nonce_field( Signup_Actions::SIGNUP_ACTION ); ?>

				<div class="aggr-formgrid">
					<div class="aggr-field">
						<label for="aggr-first-name"><?php esc_html_e( 'First name', 'aggressive-ads' ); ?></label>
						<input id="aggr-first-name" name="first_name" type="text" autocomplete="given-name" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_PERSON_NAME ); ?>" required>
					</div>

					<div class="aggr-field">
						<label for="aggr-last-name"><?php esc_html_e( 'Last name', 'aggressive-ads' ); ?></label>
						<input id="aggr-last-name" name="last_name" type="text" autocomplete="family-name" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_PERSON_NAME ); ?>" required>
					</div>
				</div>

				<?php if ( '' === $aggr_invite_token ) : ?>
					<div class="aggr-field">
						<label for="aggr-organization"><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></label>
						<p id="aggr-organization-hint" class="aggr-hint"><?php esc_html_e( 'We check for a matching organization. An owner must approve access before you can join an existing one.', 'aggressive-ads' ); ?></p>
						<input id="aggr-organization" class="aggr-input--uppercase" name="organization_name" type="text" autocomplete="organization" aria-describedby="aggr-organization-hint" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_ORG_NAME ); ?>" autocapitalize="characters" required>
					</div>
				<?php endif; ?>

				<div class="aggr-field">
					<label for="aggr-email"><?php esc_html_e( 'Work email', 'aggressive-ads' ); ?></label>
					<p id="aggr-email-hint" class="aggr-hint"><?php esc_html_e( 'We will send a one-time password setup link to this address.', 'aggressive-ads' ); ?></p>
					<input id="aggr-email" name="email" type="email" autocomplete="email" aria-describedby="aggr-email-hint" maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_EMAIL ); ?>" required>
				</div>

				<div class="aggr-form-trap" aria-hidden="true">
					<label for="aggr-company-website"><?php esc_html_e( 'Company website', 'aggressive-ads' ); ?></label>
					<input id="aggr-company-website" name="company_website" type="text" tabindex="-1" autocomplete="off">
				</div>

				<button class="aggr-button" type="submit"><?php esc_html_e( 'Create account', 'aggressive-ads' ); ?></button>
			</form>
		<?php elseif ( ! $aggr_enabled && 'unavailable' !== $aggr_notice ) : ?>
			<div class="aggr-alert aggr-alert--error" role="status">
				<p><?php echo esc_html( Signup_Actions::notice_message( 'unavailable' ) ); ?></p>
			</div>
		<?php endif; ?>

		<p class="aggr-signin__aside">
			<?php esc_html_e( 'Already have an account?', 'aggressive-ads' ); ?>
			<a href="<?php echo esc_url( Routes::url( Request::ROUTE_LOGIN ) ); ?>"><?php esc_html_e( 'Sign in', 'aggressive-ads' ); ?></a>
		</p>
	</div>
</div>
