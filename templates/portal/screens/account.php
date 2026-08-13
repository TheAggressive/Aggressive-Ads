<?php
/**
 * Account contents.
 *
 * The only place a portal user can manage their own login: Admin_Guard sends
 * them away from wp-admin, so /wp-admin/profile.php is unreachable for them.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Account_Actions;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Workflow\Advertiser_Registration;

$aggr_account      = Plugin::instance()->container()->get( View_Data::class )->account();
$aggr_notice       = Account_Actions::request_notice();
$aggr_error        = Account_Actions::request_error_code();
$aggr_email_notice = Email_Change_Actions::account_notice();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state shared with Account_Actions notices.
$aggr_email_error = isset( $_GET['aggr_error'] ) ? sanitize_key( wp_unslash( $_GET['aggr_error'] ) ) : '';
?>
<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php esc_html_e( 'Account', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'Your name, sign-in details and password.', 'aggressive-ads' ); ?></p>
	</div>
</div>

<?php if ( 'error' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--error" role="alert">
		<p><?php echo esc_html( Account_Actions::error_message( $aggr_error ) ); ?></p>
	</div>
<?php elseif ( 'saved' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--success" role="status">
		<p><?php esc_html_e( 'Your details were saved.', 'aggressive-ads' ); ?></p>
	</div>
<?php elseif ( 'password_sent' === $aggr_notice ) : ?>
	<div class="aggr-alert aggr-alert--success" role="status">
		<p><?php esc_html_e( 'Check your email for a link to set a new password.', 'aggressive-ads' ); ?></p>
	</div>
<?php elseif ( 'email_error' === $aggr_email_notice ) : ?>
	<div class="aggr-alert aggr-alert--error" role="alert">
		<p><?php echo esc_html( Email_Change_Actions::error_message( $aggr_email_error ) ); ?></p>
	</div>
<?php elseif ( '' !== $aggr_email_notice ) : ?>
	<div class="aggr-alert <?php echo esc_attr( 'rate_limited' === $aggr_email_notice ? 'aggr-alert--error' : 'aggr-alert--success' ); ?>" role="status">
		<p><?php echo esc_html( Email_Change_Actions::account_notice_message( $aggr_email_notice ) ); ?></p>
	</div>
<?php endif; ?>

<section class="aggr-panel" aria-labelledby="aggr-account-details">
	<h2 id="aggr-account-details" class="aggr-panel__head"><?php esc_html_e( 'Your details', 'aggressive-ads' ); ?></h2>

	<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Account_Actions::SAVE_ACTION ); ?>">
		<?php wp_nonce_field( Account_Actions::SAVE_ACTION ); ?>

		<div class="aggr-field">
			<label for="aggr-display-name"><?php esc_html_e( 'Name to display', 'aggressive-ads' ); ?></label>
			<input
				id="aggr-display-name"
				name="display_name"
				type="text"
				value="<?php echo esc_attr( (string) $aggr_account['display_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				required
				<?php echo 'aggr_display_name_required' === $aggr_error ? 'aria-invalid="true"' : ''; ?>
			>
		</div>

		<div class="aggr-field">
			<label for="aggr-first-name"><?php esc_html_e( 'First name', 'aggressive-ads' ); ?></label>
			<input
				id="aggr-first-name"
				name="first_name"
				type="text"
				value="<?php echo esc_attr( (string) $aggr_account['first_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				autocomplete="given-name"
			>
		</div>

		<div class="aggr-field">
			<label for="aggr-last-name"><?php esc_html_e( 'Last name', 'aggressive-ads' ); ?></label>
			<input
				id="aggr-last-name"
				name="last_name"
				type="text"
				value="<?php echo esc_attr( (string) $aggr_account['last_name'] ); ?>"
				maxlength="<?php echo esc_attr( (string) Account_Actions::MAX_NAME_LENGTH ); ?>"
				autocomplete="family-name"
			>
		</div>

		<button class="aggr-button" type="submit"><?php esc_html_e( 'Save details', 'aggressive-ads' ); ?></button>
	</form>
</section>

<section class="aggr-panel" aria-labelledby="aggr-account-signin">
	<h2 id="aggr-account-signin" class="aggr-panel__head"><?php esc_html_e( 'Signing in', 'aggressive-ads' ); ?></h2>

	<dl class="aggr-facts">
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Email', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( (string) $aggr_account['email'] ); ?></dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				echo '' === (string) $aggr_account['org_name']
					? esc_html__( 'Not linked yet', 'aggressive-ads' )
					: esc_html( (string) $aggr_account['org_name'] );
				?>
			</dd>
		</div>
	</dl>

	<div class="aggr-panel__foot">
		<?php if ( '' !== (string) $aggr_account['pending_email'] ) : ?>
			<p>
				<?php
				printf(
					/* translators: %s: pending new email address. */
					esc_html__( 'A confirmation is waiting for %s. Check that inbox, or cancel and try again.', 'aggressive-ads' ),
					esc_html( (string) $aggr_account['pending_email'] )
				);
				?>
			</p>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Email_Change_Actions::CANCEL_ACTION ); ?>">
				<?php wp_nonce_field( Email_Change_Actions::CANCEL_ACTION ); ?>
				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php esc_html_e( 'Cancel email change', 'aggressive-ads' ); ?>
				</button>
			</form>
		<?php else : ?>
			<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Email_Change_Actions::REQUEST_ACTION ); ?>">
				<?php wp_nonce_field( Email_Change_Actions::REQUEST_ACTION ); ?>

				<div class="aggr-field">
					<label for="aggr-new-email"><?php esc_html_e( 'New email address', 'aggressive-ads' ); ?></label>
					<input
						id="aggr-new-email"
						name="new_email"
						type="email"
						autocomplete="email"
						maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_EMAIL ); ?>"
						required
					>
				</div>

				<p class="aggr-hint">
					<?php esc_html_e( 'We will email a one-time confirmation link to the new address. You must be signed in to finish, and your current address stays active until then.', 'aggressive-ads' ); ?>
				</p>

				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php esc_html_e( 'Send confirmation link', 'aggressive-ads' ); ?>
				</button>
			</form>
		<?php endif; ?>

		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Account_Actions::PASSWORD_ACTION ); ?>">
			<?php wp_nonce_field( Account_Actions::PASSWORD_ACTION ); ?>

			<p class="aggr-hint">
				<?php esc_html_e( 'We will email you a link to set a new password. The link can only be used once.', 'aggressive-ads' ); ?>
			</p>

			<button class="aggr-button aggr-button--secondary" type="submit">
				<?php esc_html_e( 'Email me a password reset link', 'aggressive-ads' ); ?>
			</button>
		</form>
	</div>
</section>
