<?php
/**
 * Help contents.
 *
 * The status glossary and the creative limits are derived, never written out
 * again here: the labels come from the registered statuses and the sizes from
 * the active placements, so changing a rule updates this page by itself. Help
 * maintained by hand is help that goes wrong, and wrong help costs more than
 * none because people act on it.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Size_Template;
use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;

$aggr_help          = Plugin::instance()->container()->get( View_Data::class )->help();
$aggr_steps_help    = array(
	array( __( 'Choose a package and dates', 'aggressive-ads' ), __( 'The package sets the price and where your ads appear.', 'aggressive-ads' ) ),
	array( __( 'Add your ads', 'aggressive-ads' ), __( 'One file per size. They all link to one address unless you change one.', 'aggressive-ads' ) ),
	array( __( 'Review and submit', 'aggressive-ads' ), __( 'Check everything on one page, then send it to the review team.', 'aggressive-ads' ) ),
	array( __( 'Review', 'aggressive-ads' ), __( 'The team checks the artwork, the links and the dates.', 'aggressive-ads' ) ),
	array( __( 'Scheduled', 'aggressive-ads' ), __( 'Once approved it starts by itself on your start date.', 'aggressive-ads' ) ),
	array( __( 'Live, then complete', 'aggressive-ads' ), __( 'It stops on its end date. Run it again from your campaign list.', 'aggressive-ads' ) ),
);
$aggr_statuses_half = (int) ceil( count( $aggr_help['statuses'] ) / 2 );
?>
<div class="aggr-pagehead">
	<div>
		<p class="aggr-eyebrow"><?php esc_html_e( 'Support', 'aggressive-ads' ); ?></p>
		<h1 class="aggr-title"><?php esc_html_e( 'Help', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'How advertising here works, and what your artwork needs to be.', 'aggressive-ads' ); ?></p>
	</div>

	<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Go to your campaigns', 'aggressive-ads' ); ?>
	</a>
</div>

<div class="aggr-columns">
	<div class="aggr-columns__main">
		<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-help-flow">
			<h2 id="aggr-help-flow" class="aggr-step-card__title"><?php esc_html_e( 'How a campaign runs', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Three steps from you, then the review team takes it from there.', 'aggressive-ads' ); ?></p>

			<?php
			/*
			 * The wizard's three steps, then the review team's. Written to match
			 * the wizard as it is: this list once described five steps long after
			 * the wizard stopped asking for them.
			 */
			?>
			<ol class="aggr-flow">
				<?php foreach ( $aggr_steps_help as $aggr_help_step ) : ?>
					<li class="aggr-flow__step">
						<strong><?php echo esc_html( $aggr_help_step[0] ); ?></strong>
						<span><?php echo esc_html( $aggr_help_step[1] ); ?></span>
					</li>
				<?php endforeach; ?>
			</ol>

			<p class="aggr-callout">
				<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M4 6h16v12H4z"/><path d="M4 7l8 6 8-6"/></svg>
				<span><?php esc_html_e( 'We email you when changes are requested, and when your campaign is approved, starts and finishes.', 'aggressive-ads' ); ?></span>
			</p>
		</section>

		<section id="aggr-help-artwork" class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-help-artwork-heading">
			<h2 id="aggr-help-artwork-heading" class="aggr-step-card__title"><?php esc_html_e( 'What your artwork needs', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Each ad must be exactly the size of its placement.', 'aggressive-ads' ); ?></p>

			<?php // Derived from the upload rules, so a rule change updates this strip by itself. ?>
			<dl class="aggr-specs">
				<div>
					<dt><?php esc_html_e( 'Formats', 'aggressive-ads' ); ?></dt>
					<dd><?php echo esc_html( implode( ' · ', $aggr_help['file_types'] ) ); ?></dd>
					<dd class="aggr-hint"><?php esc_html_e( 'Images only', 'aggressive-ads' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Dimensions', 'aggressive-ads' ); ?></dt>
					<dd><?php esc_html_e( 'Exact pixel size', 'aggressive-ads' ); ?></dd>
					<dd class="aggr-hint"><?php esc_html_e( 'Checked as you upload', 'aggressive-ads' ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'File size', 'aggressive-ads' ); ?></dt>
					<dd>
						<?php
						printf(
							/* translators: %s: the largest file any placement accepts, e.g. 2 MB. */
							esc_html__( 'Up to %s', 'aggressive-ads' ),
							esc_html( (string) $aggr_help['max_size'] )
						);
						?>
					</dd>
					<dd class="aggr-hint"><?php esc_html_e( 'Most placements ask for less', 'aggressive-ads' ); ?></dd>
				</div>
			</dl>

			<?php if ( array() !== $aggr_help['placements'] ) : ?>
				<div class="aggr-tablewrap" role="region" aria-label="<?php esc_attr_e( 'Artwork requirements table', 'aggressive-ads' ); ?>" tabindex="0">
					<table class="aggr-table">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Placement', 'aggressive-ads' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Required size', 'aggressive-ads' ); ?></th>
								<th scope="col"><?php esc_html_e( 'Largest file', 'aggressive-ads' ); ?></th>
								<th scope="col"><span class="aggr-sr"><?php esc_html_e( 'Template', 'aggressive-ads' ); ?></span></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $aggr_help['placements'] as $aggr_placement ) : ?>
								<?php $aggr_help_template = Size_Template::svg( (string) $aggr_placement['size'] ); ?>
								<tr>
									<td class="aggr-table__primary"><?php echo esc_html( (string) $aggr_placement['name'] ); ?></td>
									<td class="aggr-table__mono"><?php echo esc_html( str_replace( 'x', ' × ', (string) $aggr_placement['size'] ) . ' px' ); ?></td>
									<td class="aggr-table__mono"><?php echo esc_html( (string) $aggr_placement['max_size'] ); ?></td>
									<td class="aggr-table__next">
										<?php if ( '' !== $aggr_help_template ) : ?>
											<a download="<?php echo esc_attr( 'ad-template-' . (string) $aggr_placement['size'] . '.svg' ); ?>" href="<?php echo esc_url( 'data:image/svg+xml;charset=utf-8,' . rawurlencode( $aggr_help_template ), array( 'data' ) ); ?>">
												<?php esc_html_e( 'Template', 'aggressive-ads' ); ?><span class="aggr-sr"> <?php echo esc_html( (string) $aggr_placement['name'] ); ?></span>
											</a>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</section>

		<section class="aggr-panel aggr-panel--padded" aria-labelledby="aggr-help-statuses">
			<h2 id="aggr-help-statuses" class="aggr-step-card__title"><?php esc_html_e( 'What each status means', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'The label on every campaign, and what it asks of you.', 'aggressive-ads' ); ?></p>

			<div class="aggr-glossary">
				<?php foreach ( array_chunk( $aggr_help['statuses'], max( 1, $aggr_statuses_half ) ) as $aggr_status_column ) : ?>
					<dl>
						<?php foreach ( $aggr_status_column as $aggr_status ) : ?>
							<div>
								<dt>
									<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_status['pill'] ); ?>">
										<?php echo esc_html( (string) $aggr_status['label'] ); ?>
									</span>
								</dt>
								<dd><?php echo esc_html( (string) $aggr_status['description'] ); ?></dd>
							</div>
						<?php endforeach; ?>
					</dl>
				<?php endforeach; ?>
			</div>
		</section>
	</div>

	<aside class="aggr-columns__side">
		<section class="aggr-summary" aria-labelledby="aggr-help-contact">
			<h2 id="aggr-help-contact" class="aggr-step-card__title"><?php esc_html_e( 'Still stuck?', 'aggressive-ads' ); ?></h2>
			<p class="aggr-hint"><?php esc_html_e( 'Email us and we will help.', 'aggressive-ads' ); ?></p>
			<?php if ( '' !== (string) $aggr_help['contact'] ) : ?>
				<a class="aggr-button" href="<?php echo esc_url( 'mailto:' . $aggr_help['contact'] ); ?>"><?php echo esc_html( (string) $aggr_help['contact'] ); ?></a>
			<?php endif; ?>
			<a class="aggr-panel__link" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>"><?php esc_html_e( 'Go to your campaigns', 'aggressive-ads' ); ?></a>
		</section>
	</aside>
</div>
