<?php
/**
 * Organization contents.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Organization_Actions;
use Aggressive\Ads\Portal\View_Data;
use Aggressive\Ads\Repository\Org_Access_Repository;
use Aggressive\Ads\Repository\Org_Repository;

$aggr_org        = Plugin::instance()->container()->get( View_Data::class )->organization();
$aggr_org_notice = Organization_Actions::request_notice();

if ( null === $aggr_org ) :
	?>
	<div class="aggr-pagehead">
		<div>
			<h1 class="aggr-title"><?php esc_html_e( 'Organization', 'aggressive-ads' ); ?></h1>
			<p class="aggr-lede"><?php esc_html_e( 'Your account is not linked to an advertising organization yet. Get in touch and we will connect it.', 'aggressive-ads' ); ?></p>
		</div>
	</div>
	<?php
	return;
endif;
?>
<?php if ( '' !== $aggr_org_notice ) : ?>
	<div class="aggr-alert <?php echo esc_attr( in_array( $aggr_org_notice, array( 'error', 'rate_limited', 'name_taken' ), true ) ? 'aggr-alert--error' : 'aggr-alert--success' ); ?>" role="status">
		<p><?php echo esc_html( Organization_Actions::notice_message( $aggr_org_notice ) ); ?></p>
	</div>
<?php endif; ?>

<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php echo esc_html( (string) $aggr_org['name'] ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'Who can work on this organization’s campaigns.', 'aggressive-ads' ); ?></p>
	</div>

	<span class="aggr-pill aggr-pill--<?php echo true === $aggr_org['active'] ? 'live' : 'danger'; ?>">
		<?php
		echo true === $aggr_org['active']
			? esc_html__( 'Active', 'aggressive-ads' )
			: esc_html__( 'Suspended', 'aggressive-ads' );
		?>
	</span>
</div>

<?php if ( true !== $aggr_org['active'] ) : ?>
	<section class="aggr-notice">
		<h2 class="aggr-notice__head"><?php esc_html_e( 'This organization cannot submit campaigns', 'aggressive-ads' ); ?></h2>
		<p><?php esc_html_e( 'Existing campaigns are unaffected. Please get in touch to discuss reactivating the account.', 'aggressive-ads' ); ?></p>
	</section>
<?php endif; ?>

<section class="aggr-panel" aria-labelledby="aggr-org-summary">
	<h2 id="aggr-org-summary" class="aggr-panel__head"><?php esc_html_e( 'Summary', 'aggressive-ads' ); ?></h2>

	<dl class="aggr-facts">
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'People', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( count( $aggr_org['members'] ) ) ); ?></dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_org['campaigns'] ) ); ?></dd>
		</div>
	</dl>
</section>

<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
	<section class="aggr-panel" aria-labelledby="aggr-org-name">
		<h2 id="aggr-org-name" class="aggr-panel__head"><?php esc_html_e( 'Organization name', 'aggressive-ads' ); ?></h2>
		<p class="aggr-hint"><?php esc_html_e( 'Names are stored in uppercase. Exact matches of another organization’s name are refused so two tenants cannot claim the same identity.', 'aggressive-ads' ); ?></p>

		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::RENAME_ACTION ); ?>">
			<?php wp_nonce_field( Organization_Actions::RENAME_ACTION ); ?>

			<div class="aggr-field">
				<label for="aggr-organization-name"><?php esc_html_e( 'Display name', 'aggressive-ads' ); ?></label>
				<input
					id="aggr-organization-name"
					name="organization_name"
					type="text"
					value="<?php echo esc_attr( (string) $aggr_org['name'] ); ?>"
					maxlength="<?php echo esc_attr( (string) Org_Repository::MAX_NAME_LENGTH ); ?>"
					required
				>
			</div>

			<button class="aggr-button" type="submit"><?php esc_html_e( 'Save name', 'aggressive-ads' ); ?></button>
		</form>
	</section>
<?php endif; ?>

<section class="aggr-panel" aria-labelledby="aggr-org-people">
	<h2 id="aggr-org-people" class="aggr-panel__head"><?php esc_html_e( 'People', 'aggressive-ads' ); ?></h2>

	<div class="aggr-tablewrap">
		<table class="aggr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'aggressive-ads' ); ?></th>
					<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
						<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'aggressive-ads' ); ?></span></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aggr_org['members'] as $aggr_member ) : ?>
					<tr>
						<td class="aggr-table__primary">
							<?php echo esc_html( (string) $aggr_member['name'] ); ?>
							<?php if ( true === $aggr_member['is_you'] ) : ?>
								<span class="aggr-pill aggr-pill--neutral"><?php esc_html_e( 'You', 'aggressive-ads' ); ?></span>
							<?php endif; ?>
						</td>
						<td class="aggr-table__url"><?php echo esc_html( (string) $aggr_member['email'] ); ?></td>
						<td>
							<?php
							echo true === $aggr_member['is_owner']
								? esc_html__( 'Owner', 'aggressive-ads' )
								: esc_html__( 'Member', 'aggressive-ads' );
							?>
						</td>
						<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
							<td>
								<?php if ( true !== $aggr_member['is_owner'] ) : ?>
									<div class="aggr-actions">
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::TRANSFER_ACTION ); ?>">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $aggr_member['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::TRANSFER_ACTION ); ?>
											<button class="aggr-button aggr-button--secondary aggr-button--small" type="submit">
												<?php esc_html_e( 'Make owner', 'aggressive-ads' ); ?>
											</button>
										</form>
										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::REMOVE_ACTION ); ?>">
											<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $aggr_member['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::REMOVE_ACTION ); ?>
											<button class="aggr-button aggr-button--secondary aggr-button--small" type="submit">
												<?php esc_html_e( 'Remove', 'aggressive-ads' ); ?>
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

<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
	<section class="aggr-panel" aria-labelledby="aggr-org-invite">
		<h2 id="aggr-org-invite" class="aggr-panel__head"><?php esc_html_e( 'Invite a person', 'aggressive-ads' ); ?></h2>
		<p class="aggr-hint"><?php esc_html_e( 'We will email a single-use invitation that expires after three days. Membership is granted only after the recipient completes it.', 'aggressive-ads' ); ?></p>

		<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::INVITE_ACTION ); ?>">
			<?php wp_nonce_field( Organization_Actions::INVITE_ACTION ); ?>

			<div class="aggr-field">
				<label for="aggr-invite-email"><?php esc_html_e( 'Work email', 'aggressive-ads' ); ?></label>
				<input id="aggr-invite-email" name="email" type="email" autocomplete="email" maxlength="100" required>
			</div>

			<button class="aggr-button" type="submit"><?php esc_html_e( 'Send invitation', 'aggressive-ads' ); ?></button>
		</form>
	</section>

	<?php if ( array() !== $aggr_org['pending_access'] ) : ?>
		<section class="aggr-panel" aria-labelledby="aggr-org-pending">
			<h2 id="aggr-org-pending" class="aggr-panel__head"><?php esc_html_e( 'Pending access', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Name matches are suggestions only. Review the email before granting access.', 'aggressive-ads' ); ?></p>

			<div class="aggr-tablewrap">
				<table class="aggr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Email', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Expires', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'aggressive-ads' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aggr_org['pending_access'] as $aggr_access ) : ?>
							<tr>
								<td><?php echo esc_html( (string) $aggr_access['email'] ); ?></td>
								<td>
									<?php echo Org_Access_Repository::KIND_REQUEST === $aggr_access['kind'] ? esc_html__( 'Access request', 'aggressive-ads' ) : esc_html__( 'Invitation', 'aggressive-ads' ); ?>
								</td>
							<td>
								<?php
								$aggr_expiry = wp_date( get_option( 'date_format' ), (int) $aggr_access['expires_at_ts'] );
								echo esc_html( is_string( $aggr_expiry ) ? $aggr_expiry : '—' );
								?>
							</td>
								<td>
									<div class="aggr-actions">
										<?php if ( Org_Access_Repository::KIND_REQUEST === $aggr_access['kind'] ) : ?>
											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::APPROVE_ACTION ); ?>">
								<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $aggr_access['id'] ); ?>">
												<?php wp_nonce_field( Organization_Actions::APPROVE_ACTION ); ?>
												<button class="aggr-button aggr-button--small" type="submit"><?php esc_html_e( 'Approve', 'aggressive-ads' ); ?></button>
											</form>
										<?php endif; ?>

										<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
											<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::DENY_ACTION ); ?>">
							<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $aggr_access['id'] ); ?>">
											<?php wp_nonce_field( Organization_Actions::DENY_ACTION ); ?>
							<button class="aggr-button aggr-button--secondary aggr-button--small" type="submit">
												<?php echo Org_Access_Repository::KIND_REQUEST === $aggr_access['kind'] ? esc_html__( 'Deny', 'aggressive-ads' ) : esc_html__( 'Revoke', 'aggressive-ads' ); ?>
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
	<p class="aggr-panel__foot"><?php esc_html_e( 'Only the organization owner can rename the organization, invite people, approve requests, remove members, or transfer ownership.', 'aggressive-ads' ); ?></p>
<?php endif; ?>
