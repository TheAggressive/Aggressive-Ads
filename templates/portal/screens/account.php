<?php
/**
 * Account contents.
 *
 * The only place a portal user can manage their own login: Admin_Guard sends
 * them away from wp-admin, so /wp-admin/profile.php is unreachable for them.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Account_Actions;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_account = Plugin::instance()->container()->get( View_Data::class )->account();
$laao_ads_notice  = Account_Actions::request_notice();
$laao_ads_error   = Account_Actions::request_error_code();
?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php esc_html_e( 'Account', 'laao-advertiser-portal' ); ?></h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'Your name, sign-in details and password.', 'laao-advertiser-portal' ); ?></p>
	</div>
</div>

<?php if ( 'error' === $laao_ads_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--error" role="alert">
		<p><?php echo esc_html( Account_Actions::error_message( $laao_ads_error ) ); ?></p>
	</div>
<?php elseif ( 'saved' === $laao_ads_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--success" role="status">
		<p><?php esc_html_e( 'Your details were saved.', 'laao-advertiser-portal' ); ?></p>
	</div>
<?php elseif ( 'password_sent' === $laao_ads_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--success" role="status">
		<p><?php esc_html_e( 'Check your email for a link to set a new password.', 'laao-advertiser-portal' ); ?></p>
	</div>
<?php endif; ?>

<section class="laao-ads-panel" aria-labelledby="laao-ads-account-details">
	<h2 id="laao-ads-account-details" class="laao-ads-panel__head"><?php esc_html_e( 'Your details', 'laao-advertiser-portal' ); ?></h2>

	<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Account_Actions::SAVE_ACTION ); ?>">
		<?php wp_nonce_field( Account_Actions::SAVE_ACTION ); ?>

		<div class="laao-ads-field">
			<label for="laao-ads-display-name"><?php esc_html_e( 'Name to display', 'laao-advertiser-portal' ); ?></label>
			<input
				id="laao-ads-display-name"
				name="display_name"
				type="text"
				value="<?php echo esc_attr( (string) $laao_ads_account['display_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				required
				<?php echo 'laao_ads_display_name_required' === $laao_ads_error ? 'aria-invalid="true"' : ''; ?>
			>
		</div>

		<div class="laao-ads-field">
			<label for="laao-ads-first-name"><?php esc_html_e( 'First name', 'laao-advertiser-portal' ); ?></label>
			<input
				id="laao-ads-first-name"
				name="first_name"
				type="text"
				value="<?php echo esc_attr( (string) $laao_ads_account['first_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				autocomplete="given-name"
			>
		</div>

		<div class="laao-ads-field">
			<label for="laao-ads-last-name"><?php esc_html_e( 'Last name', 'laao-advertiser-portal' ); ?></label>
			<input
				id="laao-ads-last-name"
				name="last_name"
				type="text"
				value="<?php echo esc_attr( (string) $laao_ads_account['last_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				autocomplete="family-name"
			>
		</div>

		<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Save details', 'laao-advertiser-portal' ); ?></button>
	</form>
</section>

<section class="laao-ads-panel" aria-labelledby="laao-ads-account-signin">
	<h2 id="laao-ads-account-signin" class="laao-ads-panel__head"><?php esc_html_e( 'Signing in', 'laao-advertiser-portal' ); ?></h2>

	<dl class="laao-ads-facts">
		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Username', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( (string) $laao_ads_account['login'] ); ?></dd>
		</div>

		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Email', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( (string) $laao_ads_account['email'] ); ?></dd>
		</div>

		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Organization', 'laao-advertiser-portal' ); ?></dt>
			<dd>
				<?php
				echo '' === (string) $laao_ads_account['org_name']
					? esc_html__( 'Not linked yet', 'laao-advertiser-portal' )
					: esc_html( (string) $laao_ads_account['org_name'] );
				?>
			</dd>
		</div>
	</dl>

	<div class="laao-ads-panel__foot">
		<p>
			<?php esc_html_e( 'Your username and email address cannot be changed here. Get in touch and we will update them for you.', 'laao-advertiser-portal' ); ?>
		</p>

		<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Account_Actions::PASSWORD_ACTION ); ?>">
			<?php wp_nonce_field( Account_Actions::PASSWORD_ACTION ); ?>

			<p class="laao-ads-hint">
				<?php esc_html_e( 'We will email you a link to set a new password. The link can only be used once.', 'laao-advertiser-portal' ); ?>
			</p>

			<button class="laao-ads-button laao-ads-button--secondary" type="submit">
				<?php esc_html_e( 'Email me a password reset link', 'laao-advertiser-portal' ); ?>
			</button>
		</form>
	</div>
</section>
