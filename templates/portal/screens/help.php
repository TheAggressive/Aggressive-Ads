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
 * @package LAAO_Advertiser_Portal
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use LAAO_Advertiser_Portal\Plugin;
use LAAO_Advertiser_Portal\Portal\Request;
use LAAO_Advertiser_Portal\Portal\Routes;
use LAAO_Advertiser_Portal\Portal\View_Data;

$laao_ads_help = Plugin::instance()->container()->get( View_Data::class )->help();
?>
<div class="laao-ads-pagehead">
	<div>
		<h1 class="laao-ads-title"><?php esc_html_e( 'Help', 'laao-advertiser-portal' ); ?></h1>
		<p class="laao-ads-lede"><?php esc_html_e( 'How advertising here works, and what your artwork needs to be.', 'laao-advertiser-portal' ); ?></p>
	</div>

	<a class="laao-ads-button" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Go to your campaigns', 'laao-advertiser-portal' ); ?>
	</a>
</div>

<section class="laao-ads-panel" aria-labelledby="laao-ads-help-flow">
	<h2 id="laao-ads-help-flow" class="laao-ads-panel__head"><?php esc_html_e( 'How a campaign runs', 'laao-advertiser-portal' ); ?></h2>

	<div class="laao-ads-prose">
		<ol>
			<li><?php esc_html_e( 'Create a campaign and give it a name.', 'laao-advertiser-portal' ); ?></li>
			<li><?php esc_html_e( 'Choose a package. This sets the price and where your advertisement appears.', 'laao-advertiser-portal' ); ?></li>
			<li><?php esc_html_e( 'Upload one image for each placement, with the address it should link to.', 'laao-advertiser-portal' ); ?></li>
			<li><?php esc_html_e( 'Confirm the destinations and choose your dates.', 'laao-advertiser-portal' ); ?></li>
			<li><?php esc_html_e( 'Submit it. The review team checks the artwork, the links and the dates.', 'laao-advertiser-portal' ); ?></li>
			<li><?php esc_html_e( 'Once approved, it starts automatically on your start date and stops on your end date.', 'laao-advertiser-portal' ); ?></li>
		</ol>

		<p><?php esc_html_e( 'We email you when the review team asks for changes, and when your campaign is approved, starts and finishes.', 'laao-advertiser-portal' ); ?></p>
	</div>
</section>

<section class="laao-ads-panel" aria-labelledby="laao-ads-help-artwork">
	<h2 id="laao-ads-help-artwork" class="laao-ads-panel__head"><?php esc_html_e( 'What your artwork needs', 'laao-advertiser-portal' ); ?></h2>

	<div class="laao-ads-prose">
		<p>
			<?php
			printf(
				/* translators: 1: comma-separated file types, e.g. JPG, PNG. 2: maximum file size, e.g. 2 MB. */
				esc_html__( 'Images only: %1$s, up to %2$s each.', 'laao-advertiser-portal' ),
				esc_html( implode( ', ', $laao_ads_help['file_types'] ) ),
				esc_html( (string) $laao_ads_help['max_size'] )
			);
			?>
		</p>
		<p><?php esc_html_e( 'Each image must be exactly the size of the placement it is for. Every image also needs a short description for people who cannot see it — this is a legal requirement as well as a courtesy.', 'laao-advertiser-portal' ); ?></p>
	</div>

	<?php if ( array() !== $laao_ads_help['placements'] ) : ?>
		<div class="laao-ads-tablewrap">
			<table class="laao-ads-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Placement', 'laao-advertiser-portal' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required size', 'laao-advertiser-portal' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $laao_ads_help['placements'] as $laao_ads_placement ) : ?>
						<tr>
							<td class="laao-ads-table__primary"><?php echo esc_html( (string) $laao_ads_placement['name'] ); ?></td>
							<td>
								<?php
								printf(
									/* translators: %s: image dimensions, e.g. 728x90. */
									esc_html__( '%s pixels', 'laao-advertiser-portal' ),
									esc_html( (string) $laao_ads_placement['size'] )
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

<section class="laao-ads-panel" aria-labelledby="laao-ads-help-statuses">
	<h2 id="laao-ads-help-statuses" class="laao-ads-panel__head"><?php esc_html_e( 'What each status means', 'laao-advertiser-portal' ); ?></h2>

	<div class="laao-ads-tablewrap">
		<table class="laao-ads-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Status', 'laao-advertiser-portal' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it means', 'laao-advertiser-portal' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $laao_ads_help['statuses'] as $laao_ads_status ) : ?>
					<tr>
						<td>
							<span class="laao-ads-pill laao-ads-pill--<?php echo esc_attr( (string) $laao_ads_status['pill'] ); ?>">
								<?php echo esc_html( (string) $laao_ads_status['label'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $laao_ads_status['description'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<?php if ( '' !== (string) $laao_ads_help['contact'] ) : ?>
	<section class="laao-ads-panel" aria-labelledby="laao-ads-help-contact">
		<h2 id="laao-ads-help-contact" class="laao-ads-panel__head"><?php esc_html_e( 'Still stuck?', 'laao-advertiser-portal' ); ?></h2>

		<div class="laao-ads-prose">
			<p>
				<?php esc_html_e( 'Email us and we will help.', 'laao-advertiser-portal' ); ?>
				<a href="<?php echo esc_url( 'mailto:' . $laao_ads_help['contact'] ); ?>">
					<?php echo esc_html( (string) $laao_ads_help['contact'] ); ?>
				</a>
			</p>
		</div>
	</section>
<?php endif; ?>
