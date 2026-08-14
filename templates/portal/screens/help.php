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

use Aggressive\Ads\Plugin;
use Aggressive\Ads\Portal\Request;
use Aggressive\Ads\Portal\Routes;
use Aggressive\Ads\Portal\View_Data;

$aggr_help = Plugin::instance()->container()->get( View_Data::class )->help();
?>
<div class="aggr-pagehead">
	<div>
		<h1 class="aggr-title"><?php esc_html_e( 'Help', 'aggressive-ads' ); ?></h1>
		<p class="aggr-lede"><?php esc_html_e( 'How advertising here works, and what your artwork needs to be.', 'aggressive-ads' ); ?></p>
	</div>

	<a class="aggr-button" href="<?php echo esc_url( Routes::url( Request::ROUTE_CAMPAIGNS ) ); ?>">
		<?php esc_html_e( 'Go to your campaigns', 'aggressive-ads' ); ?>
	</a>
</div>

<section class="aggr-panel" aria-labelledby="aggr-help-flow">
	<h2 id="aggr-help-flow" class="aggr-panel__head"><?php esc_html_e( 'How a campaign runs', 'aggressive-ads' ); ?></h2>

	<div class="aggr-prose">
		<ol>
			<li><?php esc_html_e( 'Create a campaign and give it a name.', 'aggressive-ads' ); ?></li>
			<li><?php esc_html_e( 'Choose a package. This sets the price and where your advertisement appears.', 'aggressive-ads' ); ?></li>
			<li><?php esc_html_e( 'Upload one image for each placement, with the address it should link to.', 'aggressive-ads' ); ?></li>
			<li><?php esc_html_e( 'Confirm the destinations and choose your dates.', 'aggressive-ads' ); ?></li>
			<li><?php esc_html_e( 'Submit it. The review team checks the artwork, the links and the dates.', 'aggressive-ads' ); ?></li>
			<li><?php esc_html_e( 'Once approved, it starts automatically on your start date and stops on your end date.', 'aggressive-ads' ); ?></li>
		</ol>

		<p><?php esc_html_e( 'We email you when the review team asks for changes, and when your campaign is approved, starts and finishes.', 'aggressive-ads' ); ?></p>
	</div>
</section>

<section class="aggr-panel" aria-labelledby="aggr-help-artwork">
	<h2 id="aggr-help-artwork" class="aggr-panel__head"><?php esc_html_e( 'What your artwork needs', 'aggressive-ads' ); ?></h2>

	<div class="aggr-prose">
		<p>
			<?php
			printf(
				/* translators: 1: comma-separated file types, e.g. JPG, PNG. 2: maximum file size, e.g. 2 MB. */
				esc_html__( 'Images only: %1$s, up to %2$s each.', 'aggressive-ads' ),
				esc_html( implode( ', ', $aggr_help['file_types'] ) ),
				esc_html( (string) $aggr_help['max_size'] )
			);
			?>
		</p>
		<p><?php esc_html_e( 'Each image must be exactly the size of the placement it is for. Every image also needs a short description for people who cannot see it — this is a legal requirement as well as a courtesy.', 'aggressive-ads' ); ?></p>
	</div>

	<?php if ( array() !== $aggr_help['placements'] ) : ?>
		<div class="aggr-tablewrap">
			<table class="aggr-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Placement', 'aggressive-ads' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Required size', 'aggressive-ads' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $aggr_help['placements'] as $aggr_placement ) : ?>
						<tr>
							<td class="aggr-table__primary"><?php echo esc_html( (string) $aggr_placement['name'] ); ?></td>
							<td>
								<?php
								printf(
									/* translators: %s: image dimensions, e.g. 728x90. */
									esc_html__( '%s pixels', 'aggressive-ads' ),
									esc_html( (string) $aggr_placement['size'] )
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

<section class="aggr-panel" aria-labelledby="aggr-help-statuses">
	<h2 id="aggr-help-statuses" class="aggr-panel__head"><?php esc_html_e( 'What each status means', 'aggressive-ads' ); ?></h2>

	<div class="aggr-tablewrap">
		<table class="aggr-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Status', 'aggressive-ads' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it means', 'aggressive-ads' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $aggr_help['statuses'] as $aggr_status ) : ?>
					<tr>
						<td>
							<span class="aggr-pill aggr-pill--<?php echo esc_attr( (string) $aggr_status['pill'] ); ?>">
								<?php echo esc_html( (string) $aggr_status['label'] ); ?>
							</span>
						</td>
						<td><?php echo esc_html( (string) $aggr_status['description'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</div>
</section>

<?php if ( '' !== (string) $aggr_help['contact'] ) : ?>
	<section class="aggr-panel" aria-labelledby="aggr-help-contact">
		<h2 id="aggr-help-contact" class="aggr-panel__head"><?php esc_html_e( 'Still stuck?', 'aggressive-ads' ); ?></h2>

		<div class="aggr-prose">
			<p>
				<?php esc_html_e( 'Email us and we will help.', 'aggressive-ads' ); ?>
				<a href="<?php echo esc_url( 'mailto:' . $aggr_help['contact'] ); ?>">
					<?php echo esc_html( (string) $aggr_help['contact'] ); ?>
				</a>
			</p>
		</div>
	</section>
<?php endif; ?>
