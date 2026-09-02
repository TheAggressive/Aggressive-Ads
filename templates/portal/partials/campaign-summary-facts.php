<?php
/**
 * The read-only summary facts for one campaign.
 *
 * Split out of `campaign.php` when adding the conversions row pushed that file
 * past the 1,000-line gate. The gate's remedy is always to split by
 * responsibility, and this block has one: it is the campaign's own numbers,
 * rendered after the wizard is done with it.
 *
 * Delivery metrics appear only when `Reporting_Read` attached them, which it
 * does only while the Reporting module is on — so the keys being absent is the
 * gate, not a missing value. Within them, "Not measured" and a zero are
 * different answers and are kept apart: a campaign that ran before conversion
 * tracking did not convert nobody, it was not being counted.
 *
 * @package Aggressive\Ads
 *
 * @var array<string, mixed> $aggr_campaign  Campaign row, metrics attached when Reporting is on.
 * @var array<int, mixed>    $aggr_creatives Creatives on the campaign.
 * @var array<int, string>   $aggr_places    Placement names, already resolved.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
	<dl class="aggr-facts">
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Placements', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				echo esc_html(
					array() === $aggr_places
						? __( 'None selected', 'aggressive-ads' )
						: implode( ', ', $aggr_places )
				);
				?>
			</dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Creatives', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( count( $aggr_creatives ) ) ); ?></dd>
		</div>

		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Revision', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['revision'] ) ); ?></dd>
		</div>

		<?php if ( isset( $aggr_campaign['impressions'], $aggr_campaign['clicks'] ) ) : ?>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Impressions', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['impressions'] ) ); ?></dd>
		</div>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Clicks', 'aggressive-ads' ); ?></dt>
			<dd><?php echo esc_html( number_format_i18n( (int) $aggr_campaign['clicks'] ) ); ?></dd>
		</div>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'CTR', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				$aggr_ctr = $aggr_campaign['ctr'] ?? null;
				echo esc_html(
					is_float( $aggr_ctr )
						? sprintf(
							/* translators: %s: click-through rate as a percentage, e.g. 1.2. */
							__( '%s%%', 'aggressive-ads' ),
							number_format_i18n( $aggr_ctr * 100, 1 )
						)
						: __( '—', 'aggressive-ads' )
				);
				?>
			</dd>
		</div>
		<div class="aggr-fact">
			<dt><?php esc_html_e( 'Conversions', 'aggressive-ads' ); ?></dt>
			<dd>
				<?php
				// Null is a campaign no day of which was measured, not one
				// that converted nobody. See Delivery_View_Data::format_count().
				$aggr_conversions = $aggr_campaign['conversions'] ?? null;
				echo esc_html(
					is_int( $aggr_conversions )
						? number_format_i18n( $aggr_conversions )
						: __( 'Not measured', 'aggressive-ads' )
				);
				?>
			</dd>
		</div>
		<?php endif; ?>
	</dl>
