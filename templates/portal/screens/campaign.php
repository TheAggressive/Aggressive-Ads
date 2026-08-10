<?php
/**
 * Campaign detail contents.
 *
 * The campaign itself arrives from templates/portal/campaigns-detail.php, which
 * resolves it before any output so the response status can still be set. This
 * file only renders.
 *
 * @package LAAO_Advertiser_Portal
 *
 * @var array<string, mixed> $laao_ads_campaign The campaign to render.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;

$laao_ads_creatives = is_array( $laao_ads_campaign['creatives'] ) ? $laao_ads_campaign['creatives'] : array();
$laao_ads_notes     = (string) $laao_ads_campaign['review_notes'];
$laao_ads_places    = is_array( $laao_ads_campaign['placements'] ) ? $laao_ads_campaign['placements'] : array();
?>
<nav class="laao-ads-breadcrumb" aria-label="<?php esc_attr_e( 'Breadcrumb', 'laao-advertiser-portal' ); ?>">
	<a href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Campaigns', 'laao-advertiser-portal' ); ?>
	</a>
</nav>

<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php echo esc_html( (string) $laao_ads_campaign['title'] ); ?></h1>
		<p class="laao-ads-lede"><?php echo esc_html( (string) $laao_ads_campaign['dates'] ); ?></p>
	</div>

	<span class="laao-ads-pill laao-ads-pill--<?php echo esc_attr( (string) $laao_ads_campaign['pill'] ); ?>">
		<?php echo esc_html( (string) $laao_ads_campaign['status_text'] ); ?>
	</span>
</div>

<?php if ( '' !== $laao_ads_notes ) : ?>
	<section class="laao-ads-notice" aria-labelledby="laao-ads-notes-heading">
		<h2 id="laao-ads-notes-heading" class="laao-ads-notice__head">
			<?php esc_html_e( 'Notes from the review team', 'laao-advertiser-portal' ); ?>
		</h2>
		<p><?php echo esc_html( $laao_ads_notes ); ?></p>
	</section>
<?php endif; ?>

<section class="laao-ads-panel" aria-labelledby="laao-ads-summary-heading">
	<h2 id="laao-ads-summary-heading" class="laao-ads-panel__head">
		<?php esc_html_e( 'Summary', 'laao-advertiser-portal' ); ?>
	</h2>

	<dl class="laao-ads-facts">
		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Placements', 'laao-advertiser-portal' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					array() === $laao_ads_places
						? __( 'None selected', 'laao-advertiser-portal' )
						: implode( ', ', $laao_ads_places )
				);
				?>
			</dd>
		</div>

		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Creatives', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( count( $laao_ads_creatives ) ) ); ?></dd>
		</div>

		<div class="laao-ads-fact">
			<dt><?php esc_html_e( 'Revision', 'laao-advertiser-portal' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $laao_ads_campaign['revision'] ) ); ?></dd>
		</div>
	</dl>
</section>

<section class="laao-ads-panel" aria-labelledby="laao-ads-creatives-heading">
	<h2 id="laao-ads-creatives-heading" class="laao-ads-panel__head">
		<?php esc_html_e( 'Creatives', 'laao-advertiser-portal' ); ?>
	</h2>

	<?php if ( array() === $laao_ads_creatives ) : ?>
		<div class="laao-ads-empty">
			<p class="laao-ads-empty__title"><?php esc_html_e( 'No creatives yet', 'laao-advertiser-portal' ); ?></p>
			<p><?php esc_html_e( 'A campaign needs at least one creative before it can be submitted.', 'laao-advertiser-portal' ); ?></p>
		</div>
	<?php else : ?>
		<div class="laao-ads-tablewrap">
			<table class="laao-ads-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Placement', 'laao-advertiser-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Size', 'laao-advertiser-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Destination', 'laao-advertiser-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Alt text', 'laao-advertiser-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $laao_ads_creatives as $laao_ads_creative ) : ?>
						<tr>
							<td class="laao-ads-table__primary"><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></td>
							<td>
								<?php
								echo esc_html(
									'' !== $laao_ads_creative['dimensions']
										? (string) $laao_ads_creative['dimensions']
										: (string) $laao_ads_creative['size']
								);
								?>
							</td>
							<td class="laao-ads-table__url">
								<?php echo esc_html( (string) $laao_ads_creative['click_url'] ); ?>
							</td>
							<td>
								<?php
								echo esc_html(
									'' !== $laao_ads_creative['alt_text']
										? (string) $laao_ads_creative['alt_text']
										: __( 'Not set', 'laao-advertiser-portal' )
								);
								?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
	<?php endif; ?>
</section>
