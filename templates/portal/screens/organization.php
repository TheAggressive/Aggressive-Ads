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
use Aggressive\Ads\Portal\Portal_Notice;
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
<?php
if ( '' !== $aggr_org_notice ) {
	Portal_Notice::add(
		Organization_Actions::notice_message( $aggr_org_notice ),
		in_array( $aggr_org_notice, array( 'error', 'rate_limited', 'name_taken' ), true ) ? 'error' : 'success'
	);
}
?>

<div class="aggr-pagehead">
	<div>
		<p class="aggr-eyebrow"><?php esc_html_e( 'Settings', 'aggressive-ads' ); ?></p>
		<div class="aggr-pagehead__heading">
			<h1 class="aggr-title"><?php echo esc_html( (string) $aggr_org['name'] ); ?></h1>
			<span class="aggr-pill aggr-pill--<?php echo true === $aggr_org['active'] ? 'live' : 'danger'; ?>">
				<?php
				echo true === $aggr_org['active']
					? esc_html__( 'Active', 'aggressive-ads' )
					: esc_html__( 'Suspended', 'aggressive-ads' );
				?>
			</span>
		</div>
		<p class="aggr-lede"><?php esc_html_e( 'Who can work on this organization’s campaigns.', 'aggressive-ads' ); ?></p>
	</div>
</div>

<?php if ( true !== $aggr_org['active'] ) : ?>
	<section class="aggr-notice">
		<h2 class="aggr-notice__head"><?php esc_html_e( 'This organization cannot submit campaigns', 'aggressive-ads' ); ?></h2>
		<p><?php esc_html_e( 'Existing campaigns are unaffected. Please get in touch to discuss reactivating the account.', 'aggressive-ads' ); ?></p>
	</section>
<?php endif; ?>

<?php
/*
 * The people this organization is made of on the left, and what the owner can
 * do about them on the right: the list is what somebody came to read, and the
 * forms are what they occasionally act on.
 */
?>
<div class="aggr-columns">
	<div class="aggr-columns__main">
		<section class="aggr-panel" aria-labelledby="aggr-org-people">
			<div class="aggr-panel__headrow aggr-panel__headrow--hinted">
				<div>
					<h2 id="aggr-org-people" class="aggr-panel__head"><?php esc_html_e( 'People', 'aggressive-ads' ); ?></h2>
					<p class="aggr-panel__hint"><?php esc_html_e( 'Everyone here can create, edit and submit this organization’s campaigns.', 'aggressive-ads' ); ?></p>
				</div>
				<span class="aggr-table__mono">
					<?php
					printf(
						/* translators: %s: number of people in the organization. */
						esc_html( _n( '%s person', '%s people', count( $aggr_org['members'] ), 'aggressive-ads' ) ),
						esc_html( number_format_i18n( count( $aggr_org['members'] ) ) )
					);
					?>
				</span>
			</div>

			<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Organization members table', 'aggressive-ads' ); ?>" tabindex="0">
				<table class="aggr-table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Name', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Email', 'aggressive-ads' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Role', 'aggressive-ads' ); ?></th>
							<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
								<th scope="col"><?php esc_html_e( 'Actions', 'aggressive-ads' ); ?></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $aggr_org['members'] as $aggr_member ) : ?>
							<?php
							$aggr_member_words    = preg_split( '/\s+/', trim( (string) $aggr_member['name'] ) );
							$aggr_member_initials = '';

							foreach ( array_slice( is_array( $aggr_member_words ) ? $aggr_member_words : array(), 0, 2 ) as $aggr_member_word ) {
								$aggr_member_initials .= mb_strtoupper( mb_substr( $aggr_member_word, 0, 1 ) );
							}
							?>
							<tr>
								<td class="aggr-table__primary">
									<span class="aggr-person">
										<span class="aggr-person__initials" aria-hidden="true"><?php echo esc_html( $aggr_member_initials ); ?></span>
										<span><?php echo esc_html( (string) $aggr_member['name'] ); ?></span>
										<?php if ( true === $aggr_member['is_you'] ) : ?>
											<span class="aggr-eyebrow"><?php esc_html_e( 'You', 'aggressive-ads' ); ?></span>
										<?php endif; ?>
									</span>
								</td>
								<td class="aggr-table__mono"><?php echo esc_html( (string) $aggr_member['email'] ); ?></td>
								<td>
									<?php if ( true === $aggr_member['is_owner'] ) : ?>
										<span class="aggr-pill aggr-pill--owner"><?php esc_html_e( 'Owner', 'aggressive-ads' ); ?></span>
									<?php else : ?>
										<span class="aggr-pill aggr-pill--neutral"><?php esc_html_e( 'Member', 'aggressive-ads' ); ?></span>
									<?php endif; ?>
								</td>
								<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
									<td>
										<?php if ( true !== $aggr_member['is_owner'] ) : ?>
											<div class="aggr-actions">
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::TRANSFER_ACTION ); ?>">
													<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $aggr_member['id'] ); ?>">
													<?php wp_nonce_field( Organization_Actions::TRANSFER_ACTION ); ?>
													<button class="aggr-link-button" type="submit">
														<?php esc_html_e( 'Make owner', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_member['name'] ); ?></span>
													</button>
												</form>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::REMOVE_ACTION ); ?>">
													<input type="hidden" name="user_id" value="<?php echo esc_attr( (string) $aggr_member['id'] ); ?>">
													<?php wp_nonce_field( Organization_Actions::REMOVE_ACTION ); ?>
													<button class="aggr-link-button aggr-link-button--danger" type="submit">
														<?php esc_html_e( 'Remove', 'aggressive-ads' ); ?><span class="aggr-sr">: <?php echo esc_html( (string) $aggr_member['name'] ); ?></span>
													</button>
												</form>
											</div>
										<?php else : ?>
											<span class="aggr-hint">—</span>
										<?php endif; ?>
									</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</section>

		<?php if ( true === $aggr_org['can_manage_members'] && array() !== $aggr_org['pending_access'] ) : ?>
			<section class="aggr-panel" aria-labelledby="aggr-org-pending">
				<div class="aggr-panel__headrow aggr-panel__headrow--hinted">
					<div>
						<h2 id="aggr-org-pending" class="aggr-panel__head"><?php esc_html_e( 'Pending access', 'aggressive-ads' ); ?></h2>
						<p class="aggr-panel__hint"><?php esc_html_e( 'Name matches are suggestions only. Check the email before granting access.', 'aggressive-ads' ); ?></p>
					</div>
					<span class="aggr-pill aggr-pill--pending">
						<?php
						printf(
							/* translators: %s: number of pending requests and invitations. */
							esc_html__( '%s waiting', 'aggressive-ads' ),
							esc_html( number_format_i18n( count( $aggr_org['pending_access'] ) ) )
						);
						?>
					</span>
				</div>

				<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Pending invitations table', 'aggressive-ads' ); ?>" tabindex="0">
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
								<?php $aggr_is_request = Org_Access_Repository::KIND_REQUEST === $aggr_access['kind']; ?>
								<tr>
									<td>
										<span class="aggr-table__mono aggr-table__strong"><?php echo esc_html( (string) $aggr_access['email'] ); ?></span>
										<span class="aggr-table__sub aggr-table__sub--plain">
											<?php echo $aggr_is_request ? esc_html__( 'Asked to join', 'aggressive-ads' ) : esc_html__( 'Invited', 'aggressive-ads' ); ?>
										</span>
									</td>
									<td>
										<span class="aggr-pill aggr-pill--<?php echo $aggr_is_request ? 'pending' : 'neutral'; ?>">
											<?php echo $aggr_is_request ? esc_html__( 'Access request', 'aggressive-ads' ) : esc_html__( 'Invitation', 'aggressive-ads' ); ?>
										</span>
									</td>
									<td class="aggr-table__mono">
										<?php
										$aggr_expiry = $aggr_is_request ? '—' : wp_date( 'M j', (int) $aggr_access['expires_at_ts'] );
										echo esc_html( is_string( $aggr_expiry ) ? $aggr_expiry : '—' );
										?>
									</td>
									<td>
										<div class="aggr-actions">
											<?php if ( $aggr_is_request ) : ?>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::APPROVE_ACTION ); ?>">
													<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $aggr_access['id'] ); ?>">
													<?php wp_nonce_field( Organization_Actions::APPROVE_ACTION ); ?>
													<button class="aggr-button aggr-button--secondary aggr-button--small" type="submit"><?php esc_html_e( 'Approve', 'aggressive-ads' ); ?></button>
												</form>
											<?php endif; ?>

											<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
												<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::DENY_ACTION ); ?>">
												<input type="hidden" name="access_id" value="<?php echo esc_attr( (string) $aggr_access['id'] ); ?>">
												<?php wp_nonce_field( Organization_Actions::DENY_ACTION ); ?>
												<button class="aggr-link-button aggr-link-button--danger" type="submit">
													<?php echo $aggr_is_request ? esc_html__( 'Deny', 'aggressive-ads' ) : esc_html__( 'Revoke', 'aggressive-ads' ); ?>
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

		<p class="aggr-callout">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/></svg>
			<span><?php esc_html_e( 'Only the owner can rename the organization, invite people, approve requests, remove members, or transfer ownership.', 'aggressive-ads' ); ?></span>
		</p>
	</div>

	<aside class="aggr-columns__side">
		<section class="aggr-summary" aria-labelledby="aggr-org-summary">
			<div class="aggr-summary__head">
				<h2 id="aggr-org-summary" class="aggr-eyebrow"><?php esc_html_e( 'Summary', 'aggressive-ads' ); ?></h2>
				<span class="aggr-pill aggr-pill--<?php echo true === $aggr_org['active'] ? 'live' : 'danger'; ?>">
					<?php echo true === $aggr_org['active'] ? esc_html__( 'Active', 'aggressive-ads' ) : esc_html__( 'Suspended', 'aggressive-ads' ); ?>
				</span>
			</div>

			<dl class="aggr-bigfacts">
				<div>
					<dt><?php esc_html_e( 'People', 'aggressive-ads' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( count( $aggr_org['members'] ) ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Campaigns', 'aggressive-ads' ); ?></dt>
					<dd><?php echo esc_html( number_format_i18n( (int) $aggr_org['campaigns'] ) ); ?></dd>
				</div>
			</dl>
		</section>

		<?php if ( true === $aggr_org['can_manage_members'] ) : ?>
			<section class="aggr-summary" aria-labelledby="aggr-org-invite">
				<div>
					<h2 id="aggr-org-invite" class="aggr-step-card__title"><?php esc_html_e( 'Invite a person', 'aggressive-ads' ); ?></h2>
					<p class="aggr-hint"><?php esc_html_e( 'A single-use invitation that expires after three days. They join only once they accept it.', 'aggressive-ads' ); ?></p>
				</div>

				<form class="aggr-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="<?php echo esc_attr( Organization_Actions::INVITE_ACTION ); ?>">
					<?php wp_nonce_field( Organization_Actions::INVITE_ACTION ); ?>

					<div class="aggr-field">
						<label for="aggr-invite-email"><?php esc_html_e( 'Work email', 'aggressive-ads' ); ?></label>
						<input id="aggr-invite-email" name="email" type="email" autocomplete="email" maxlength="100" placeholder="name@company.com" required>
					</div>

					<button class="aggr-button" type="submit"><?php esc_html_e( 'Send invitation', 'aggressive-ads' ); ?></button>
				</form>
			</section>

			<section class="aggr-summary" aria-labelledby="aggr-org-name">
				<h2 id="aggr-org-name" class="aggr-step-card__title"><?php esc_html_e( 'Organization name', 'aggressive-ads' ); ?></h2>

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
							aria-describedby="aggr-organization-name-hint"
							required
						>
						<p id="aggr-organization-name-hint" class="aggr-hint"><?php esc_html_e( 'Stored in uppercase. A name another organization already uses is refused.', 'aggressive-ads' ); ?></p>
					</div>

					<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save name', 'aggressive-ads' ); ?></button>
				</form>
			</section>
		<?php endif; ?>
	</aside>
</div>
