<?php
/**
 * Organization contents.
 *
 * Read-only. Renaming, inviting and removing are Phase 8, and each needs an
 * authorization answer this screen does not have yet: "may this member remove
 * that member?" is a different question from "may they see the portal?".
 *
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_org = Plugin::instance()->container()->get( View_Data::class )->organization();

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

<section class="laao-ads-panel" aria-labelledby="laao-ads-org-people">
	<h2 id="laao-ads-org-people" class="laao-ads-panel__head"><?php esc_html_e( 'People', 'laao-advertiser-portal' ); ?></h2>

	<div class="laao-ads-tablewrap">
		<table class="laao-ads-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Name', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Email', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Role', 'laao-advertiser-portal' ); ?></th>
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
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>

	<p class="laao-ads-panel__foot">
		<?php esc_html_e( 'To add or remove someone, get in touch — we will make the change for you.', 'laao-advertiser-portal' ); ?>
	</p>
</section>
