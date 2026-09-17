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
use Aggressive\Ads\Portal\Portal_Notice;
use Aggressive\Ads\Portal\Account_Actions;
use Aggressive\Ads\Portal\Email_Change_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Workflow\Advertiser_Registration;

$aggr_view         = Plugin::instance()->container()->get( View_Data::class );
$aggr_account      = $aggr_view->account();
$aggr_notice       = Account_Actions::request_notice();
$aggr_error        = Account_Actions::request_error_code();
$aggr_email_notice = Email_Change_Actions::account_notice();
// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only PRG display state shared with Account_Actions notices.
$aggr_email_error = isset( $_GET['aggr_error'] ) ? sanitize_key( wp_unslash( $_GET['aggr_error'] ) ) : '';
?>
<div class="aggr-pagehead">
	<div>
		<p class="aggr-eyebrow"><?php esc_html_e( 'Settings', 'aggressive-ads' ); ?></p>
		<h1 class="aggr-title"><?php esc_html_e( 'Account', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'Your name, sign-in details and password.', 'aggressive-ads' ); ?></p>
	</div>
</div>

<?php
if ( 'error' === $aggr_notice ) {
	Portal_Notice::add( Account_Actions::error_message( $aggr_error ), 'error' );
} elseif ( 'saved' === $aggr_notice ) {
	Portal_Notice::add( __( 'Your details were saved.', 'aggressive-ads' ), 'success' );
} elseif ( 'password_sent' === $aggr_notice ) {
	Portal_Notice::add(
		__( 'Check your email for a link to set a new password.', 'aggressive-ads' ),
		'success'
	);
} elseif ( 'email_error' === $aggr_email_notice ) {
	Portal_Notice::add( Email_Change_Actions::error_message( $aggr_email_error ), 'error' );
} elseif ( '' !== $aggr_email_notice ) {
	Portal_Notice::add(
		Email_Change_Actions::account_notice_message( $aggr_email_notice ),
		'rate_limited' === $aggr_email_notice ? 'error' : 'success'
	);
}
?>

<?php
// The profile card's role, read from the one place membership is decided.
$aggr_account_org   = $aggr_view->organization();
$aggr_account_owner = false;

foreach ( null === $aggr_account_org ? array() : $aggr_account_org['members'] as $aggr_account_member ) {
	if ( true === $aggr_account_member['is_you'] ) {
		$aggr_account_owner = true === $aggr_account_member['is_owner'];
	}
}

$aggr_account_words    = preg_split( '/\s+/', trim( (string) $aggr_account['display_name'] ) );
$aggr_account_initials = '';

foreach ( array_slice( is_array( $aggr_account_words ) ? $aggr_account_words : array(), 0, 2 ) as $aggr_account_word ) {
	$aggr_account_initials .= mb_strtoupper( mb_substr( $aggr_account_word, 0, 1 ) );
}
?>
<div class="aggr-columns">
<div class="aggr-columns__main">
<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-account-details">
	<h2 id="aggr-account-details" class="aggr-step-card__title"><?php esc_html_e( 'Your details', 'aggressive-ads' ); ?></h2>
	<p class="aggr-hint"><?php esc_html_e( 'How your name appears to your team and the review team.', 'aggressive-ads' ); ?></p>

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

		<div class="aggr-formgrid">
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
		</div>

		<div>
			<button class="aggr-button" type="submit"><?php esc_html_e( 'Save details', 'aggressive-ads' ); ?></button>
		</div>
	</form>
</section>

<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-account-signin">
	<h2 id="aggr-account-signin" class="aggr-step-card__title"><?php esc_html_e( 'Signing in', 'aggressive-ads' ); ?></h2>
	<p class="aggr-hint"><?php esc_html_e( 'The address you sign in with and where we send notices.', 'aggressive-ads' ); ?></p>

	<dl class="aggr-bigfacts aggr-bigfacts--text">
		<div>
			<dt><?php esc_html_e( 'Email', 'aggressive-ads' ); ?></dt>
			<dd class="aggr-table__mono"><?php echo esc_html( (string) $aggr_account['email'] ); ?></dd>
		</div>
		<div>
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
			<div>
				<button class="aggr-button aggr-button--secondary" type="submit">
					<?php esc_html_e( 'Cancel email change', 'aggressive-ads' ); ?>
				</button>
			</div>
		</form>
	<?php else : ?>
		<form class="aggr-form aggr-inline-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Email_Change_Actions::REQUEST_ACTION ); ?>">
			<?php wp_nonce_field( Email_Change_Actions::REQUEST_ACTION ); ?>

			<div class="aggr-field">
				<label for="aggr-new-email"><?php esc_html_e( 'New email address', 'aggressive-ads' ); ?></label>
				<input
					id="aggr-new-email"
					name="new_email"
					type="email"
					autocomplete="email"
					placeholder="you@company.com"
					maxlength="<?php echo esc_attr( (string) Advertiser_Registration::MAX_EMAIL ); ?>"
					aria-describedby="aggr-new-email-hint"
					required
				>
			</div>

			<button class="aggr-button aggr-button--secondary" type="submit">
				<?php esc_html_e( 'Send confirmation link', 'aggressive-ads' ); ?>
			</button>
		</form>
		<p id="aggr-new-email-hint" class="aggr-hint"><?php esc_html_e( 'We email a one-time link to the new address. Your current address stays active until you confirm it while signed in.', 'aggressive-ads' ); ?></p>
	<?php endif; ?>
</section>

<section class="aggr-panel aggr-panel--padded aggr-password" aria-labelledby="aggr-account-password">
	<span class="aggr-password__icon" aria-hidden="true">
		<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
	</span>
	<div class="aggr-password__text">
		<h2 id="aggr-account-password" class="aggr-step-card__title"><?php esc_html_e( 'Password', 'aggressive-ads' ); ?></h2>
		<p class="aggr-hint"><?php esc_html_e( 'We email you a link to set a new one. It works once.', 'aggressive-ads' ); ?></p>
	</div>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( Account_Actions::PASSWORD_ACTION ); ?>">
		<?php wp_nonce_field( Account_Actions::PASSWORD_ACTION ); ?>
		<button class="aggr-button aggr-button--secondary" type="submit">
			<?php esc_html_e( 'Email me a reset link', 'aggressive-ads' ); ?>
		</button>
	</form>
</section>
</div>

<aside class="aggr-columns__side">
	<section class="aggr-summary aggr-profile" aria-label="<?php esc_attr_e( 'Your profile', 'aggressive-ads' ); ?>">
		<span class="aggr-profile__initials" aria-hidden="true"><?php echo esc_html( $aggr_account_initials ); ?></span>
		<p class="aggr-profile__name"><?php echo esc_html( (string) $aggr_account['display_name'] ); ?></p>
		<p class="aggr-table__mono"><?php echo esc_html( (string) $aggr_account['email'] ); ?></p>
		<dl class="aggr-summary__rows">
			<div>
				<dt><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></dt>
				<dd><?php echo esc_html( '' === (string) $aggr_account['org_name'] ? __( 'Not linked yet', 'aggressive-ads' ) : (string) $aggr_account['org_name'] ); ?></dd>
			</div>
			<?php if ( null !== $aggr_account_org ) : ?>
				<div>
					<dt><?php esc_html_e( 'Role', 'aggressive-ads' ); ?></dt>
					<dd><?php echo $aggr_account_owner ? esc_html__( 'Owner', 'aggressive-ads' ) : esc_html__( 'Member', 'aggressive-ads' ); ?></dd>
				</div>
			<?php endif; ?>
		</dl>
	</section>
</aside>
</div>
