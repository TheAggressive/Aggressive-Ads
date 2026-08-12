<?php
/**
 * Organization contents.
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Organization_Actions;
use LAAO_Advertiser_Portal\Portal\View_Data;
use LAAO_Advertiser_Portal\Repository\Org_Access_Repository;
use LAAO_Advertiser_Portal\Repository\Org_Repository;

$laao_ads_org        = Plugin::instance()->container()->get( View_Data::class )->organization();
$laao_ads_org_notice = Organization_Actions::request_notice();

if ( null === $laao_ads_org ) :
	?>
	<div class="laao-ads-pagehead">
		<div>
			<h1 class="laao-ads-title"><?php esc_html_e( 'Organization', 'laao-advertiser-portal' ); ?></h1>
			<p class="laao-ads-lede"><?php esc_html_e( 'Your account is not linked to an advertising organization yet. Get in touch and we will connect it.', 'laao-advertiser-portal' ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;
?>
<?php if ( '' !== $laao_ads_org_notice ) : ?>
	<div class="laao-ads-alert <?php echo esc_attr( in_array( $laao_ads_org_notice, array( 'error', 'rate_limited', 'name_taken' ), true ) ? 'laao-ads-alert--error' : 'laao-ads-alert--success' ); ?>" role="status">
		<p><?php echo esc_html( Organization_Actions::notice_message( $laao_ads_org_notice ) ); ?></p>
	</div>
<?php endif; ?>

<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php echo esc_html( (string) $laao_ads_org['name'] ); ?></h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'Who can work on this organization’s campaigns.', 'laao-advertiser-portal' ); ?></p>
	</div>

	<span class="laao-ads-pill laao-ads-pill--<?php echo true === $laao_ads_org['active'] ? 'live' : 'danger'; ?>">
		<?php
		echo true === $laao_ads_org['active']
			? esc_html__( 'Active', 'laao-advertiser-portal' )
			: esc_html__( 'Suspended', 'laao-advertiser-portal' );
		?>
	</span>
</div>

<?php if ( true !== $laao_ads_org['active'] ) : ?>
	<section class="laao-ads-notice">
		<h2 class="laao-ads-notice__head"><?php esc_html_e( 'This organization cannot submit campaigns', 'laao-advertiser-portal' ); ?></h2>
		<p><?php esc_html_e( 'Existing campaigns are unaffected. Please get in touch to discuss reactivating the account.', 'laao-advertiser-portal' ); ?></p>
	</section>
<?php endif; ?>

<section class="laao-ads-panel" aria-labelledby="laao-ads-org-summary">
	<h2 id="laao-ads-org-summary" class="laao-ads-panel__head"><?php esc_html_e( 'Summary', 'laao-advertiser-portal' ); ?></h2>

	<dl class="laao-ads-facts">
		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'People', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( count( $laao_ads_org['members'] ) ) ); ?></dd>
		</div>

		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Campaigns', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $laao_ads_org['campaigns'] ) ); ?></dd>
		</div>
	</dl>
</section>

<?php if ( true === $laao_ads_org['can_manage_members'] ) : ?>
	<section class="laao-ads-panel" aria-labelledby="laao-ads-org-name">
		<h2 id="laao-ads-org-name" class="laao-ads-panel__head"><?php esc_html_e( 'Organization name', 'laao-advertiser-portal' ); ?></h2>
		<p class="laao-ads-hint"><?php esc_html_e( 'Names are stored in uppercase. Exact matches of another organization’s name are refused so two tenants cannot claim the same identity.', 'laao-advertiser-portal' ); ?></p>

		<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::RENAME_ACTION ); ?>">
			<?php wp_nonce_field( Organization_Actions::RENAME_ACTION ); ?>

			<div class="laao-ads-field">
				<label for="laao-ads-organization-name"><?php esc_html_e( 'Display name', 'laao-advertiser-portal' ); ?></label>
				<input
					id="laao-ads-organization-name"
					name="organization_name"
					type="text"
					value="<?php echo esc_attr( (string) $laao_ads_org['name'] ); ?>"
					maxlength="<?php echo esc_attr( (string) Org_Repository::MAX_NAME_LENGTH ); ?>"
					required
				>
			</div>

			<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Save name', 'laao-advertiser-portal' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<section class="laao-ads-panel" aria-labelledby="laao-ads-org-people">
	<h2 id="laao-ads-org-people" class="laao-ads-panel__head"><?php esc_html_e( 'People', 'laao-advertiser-portal' ); ?></h2>

	<div class="laao-ads-tablewrap">
		<table class="laao-ads-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'laao-advertiser-portal' ); ?></th>
					<?php if ( true === $laao_ads_org['can_manage_members'] ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'laao-advertiser-portal' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $laao_ads_org['members'] as $laao_ads_member ) : ?>
					<tr>
						<td class="laao-ads-table__primary">
							<?php echo esc_html( (string) $laao_ads_member['name'] ); ?>
							<?php if ( true === $laao_ads_member['is_you'] ) : ?>
								<span class="laao-ads-pill laao-ads-pill--neutral"><?php esc_html_e( 'You', 'laao-advertiser-portal' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="laao-ads-table__url"><?php echo esc_html( (string) $laao_ads_member['email'] ); ?></td>
						<td>
							<?php
							echo true === $laao_ads_member['is_owner']
								? esc_html__( 'Owner', 'laao-advertiser-portal' )
								: esc_html__( 'Member', 'laao-advertiser-portal' );
							?>
						</td>
						<?php if ( true === $laao_ads_org['can_manage_members'] ) : ?>
							<td>
								<?php if ( true !== $laao_ads_member['is_owner'] ) : ?>
									<div class="laao-ads-actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::TRANSFER_ACTION ); ?>">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $laao_ads_member['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::TRANSFER_ACTION ); ?>
											<button class="laao-ads-button laao-ads-button--secondary laao-ads-button--small" type="submit">
												<?php esc_html_e( 'Make owner', 'laao-advertiser-portal' ); ?>
											</button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::REMOVE_ACTION ); ?>">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $laao_ads_member['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::REMOVE_ACTION ); ?>
											<button class="laao-ads-button laao-ads-button--secondary laao-ads-button--small" type="submit">
												<?php esc_html_e( 'Remove', 'laao-advertiser-portal' ); ?>
											</button>
										</form>
									</div>
								<?php endif; ?>
							</td>
						<?php endif; ?>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

</section>

<?php if ( true === $laao_ads_org['can_manage_members'] ) : ?>
	<section class="laao-ads-panel" aria-labelledby="laao-ads-org-invite">
		<h2 id="laao-ads-org-invite" class="laao-ads-panel__head"><?php esc_html_e( 'Invite a person', 'laao-advertiser-portal' ); ?></h2>
		<p class="laao-ads-hint"><?php esc_html_e( 'We will email a single-use invitation that expires after three days. Membership is granted only after the recipient completes it.', 'laao-advertiser-portal' ); ?></p>

		<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::INVITE_ACTION ); ?>">
			<?php wp_nonce_field( Organization_Actions::INVITE_ACTION ); ?>

			<div class="laao-ads-field">
				<label for="laao-ads-invite-email"><?php esc_html_e( 'Work email', 'laao-advertiser-portal' ); ?></label>
				<input id="laao-ads-invite-email" name="email" type="email" autocomplete="email" maxlength="100" required>
			</div>

			<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Send invitation', 'laao-advertiser-portal' ); ?></button>
		</form>
	</section>

	<?php if ( array() !== $laao_ads_org['pending_access'] ) : ?>
		<section class="laao-ads-panel" aria-labelledby="laao-ads-org-pending">
			<h2 id="laao-ads-org-pending" class="laao-ads-panel__head"><?php esc_html_e( 'Pending access', 'laao-advertiser-portal' ); ?></h2>
			<p class="laao-ads-hint"><?php esc_html_e( 'Name matches are suggestions only. Review the email before granting access.', 'laao-advertiser-portal' ); ?></p>

			<div class="laao-ads-tablewrap">
				<table class="laao-ads-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Email', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Expires', 'laao-advertiser-portal' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'laao-advertiser-portal' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $laao_ads_org['pending_access'] as $laao_ads_access ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $laao_ads_access['email'] ); ?></td>
								<td>
									<?php echo Org_Access_Repository::KIND_REQUEST === $laao_ads_access['kind'] ? esc_html__( 'Access request', 'laao-advertiser-portal' ) : esc_html__( 'Invitation', 'laao-advertiser-portal' ); ?>
								</td>
							<td>
								<?php
								$laao_ads_expiry = wp_date( get_option( 'date_format' ), (int) $laao_ads_access['expires_at_ts'] );
								echo esc_html( is_string( $laao_ads_expiry ) ? $laao_ads_expiry : '—' );
								?>
							</td>
								<td>
									<div class="laao-ads-actions">
										<?php if ( Org_Access_Repository::KIND_REQUEST === $laao_ads_access['kind'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::APPROVE_ACTION ); ?>">
								<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $laao_ads_access['id'] ); ?>">
												<?php wp_nonce_field( Organization_Actions::APPROVE_ACTION ); ?>
												<button class="laao-ads-button laao-ads-button--small" type="submit"><?php esc_html_e( 'Approve', 'laao-advertiser-portal' ); ?></button>
											</form>
										<?php endif; ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::DENY_ACTION ); ?>">
							<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $laao_ads_access['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::DENY_ACTION ); ?>
							<button class="laao-ads-button laao-ads-button--secondary laao-ads-button--small" type="submit">
												<?php echo Org_Access_Repository::KIND_REQUEST === $laao_ads_access['kind'] ? esc_html__( 'Deny', 'laao-advertiser-portal' ) : esc_html__( 'Revoke', 'laao-advertiser-portal' ); ?>
											</button>
										</form>
									</div>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>
	<?php endif; ?>
<?php else : ?>
	<p class="laao-ads-panel__foot"><?php esc_html_e( 'Only the organization owner can rename the organization, invite people, approve requests, remove members, or transfer ownership.', 'laao-advertiser-portal' ); ?></p>
<?php endif; ?>
