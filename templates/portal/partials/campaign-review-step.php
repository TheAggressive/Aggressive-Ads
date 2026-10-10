<?php
/**
 * The wizard's last step: a check of everything the review team needs, the ads
 * in place on a page, and the button that sends it.
 *
 * Review and submit used to be two steps, and before that the check was a red
 * box of problems over a grid of summary cards. It is one list now, a row per
 * thing the campaign needs, each saying what it holds or what is missing and
 * linking straight to where it is changed.
 *
 * Scope is inherited from the screen, as it is for every partial here.
 *
 * @var array<string, mixed>             $aggr_campaign        The campaign being edited.
 * @var array<int, array<string, mixed>> $aggr_creatives       Creatives to preview.
 * @var array<int, array<string, mixed>> $aggr_slots           Placements and the creatives on each.
 * @var bool                             $aggr_review_ready    Whether every submission check passes.
 * @var array<int, array<string, mixed>> $aggr_review_problems Advertiser-safe readiness problems.
 * @var string                           $aggr_campaign_url    This campaign's portal URL.
 * @var string                           $aggr_error_for       Field the current error belongs to.
 * @var int                              $aggr_run_days        Days in the run, or zero when open or unset.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;

$aggr_title_problem = 'aggr-title' === $aggr_error_for
	|| in_array( 'title_missing', array_column( $aggr_review_problems, 'code' ), true );

/*
 * Every problem belongs to the row it is about. Grouped by what the code names
 * rather than by the step it links to: a missing link and a missing file both
 * live on the ads step, and they are two different things to fix.
 */
$aggr_check_problems = array(
	'package'     => array(),
	'schedule'    => array(),
	'destination' => array(),
	'tracker'     => array(),
	'ads'         => array(),
	'name'        => array(),
	'account'     => array(),
);

foreach ( $aggr_review_problems as $aggr_problem ) {
	$aggr_code = (string) ( $aggr_problem['code'] ?? '' );
	$aggr_row  = match ( true ) {
		'no_creatives' === $aggr_code, 'placement_uncovered' === $aggr_code, str_starts_with( $aggr_code, 'creative_' ) => 'ads',
		str_starts_with( $aggr_code, 'click_url' ) => 'destination',
		str_starts_with( $aggr_code, 'start_' ), str_starts_with( $aggr_code, 'end_' ) => 'schedule',
		str_starts_with( $aggr_code, 'package' ), str_starts_with( $aggr_code, 'price' ), str_starts_with( $aggr_code, 'placement' ), 'no_placements' === $aggr_code => 'package',
		'title_missing' === $aggr_code => 'name',
		default => 'account',
	};

	$aggr_check_problems[ $aggr_row ][] = $aggr_problem;
}

$aggr_check_total   = 0;
$aggr_check_ready   = 0;
$aggr_check_missing = array();

foreach ( $aggr_slots as $aggr_check_slot ) {
	if ( ! is_array( $aggr_check_slot ) ) {
		continue;
	}

	++$aggr_check_total;

	if ( ! empty( $aggr_check_slot['active'] ) && array() !== ( $aggr_check_slot['creatives'] ?? array() ) ) {
		++$aggr_check_ready;
	} else {
		$aggr_check_missing[] = (string) $aggr_check_slot['name'];
	}
}

$aggr_check_ads = sprintf(
	/* translators: 1: sizes that have an ad. 2: sizes in the package. */
	_n( '%1$d of %2$d size ready.', '%1$d of %2$d sizes ready.', $aggr_check_total, 'aggressive-ads' ),
	$aggr_check_ready,
	$aggr_check_total
);

if ( array() !== $aggr_check_missing ) {
	$aggr_check_ads .= ' ' . sprintf(
		/* translators: %s: placement names still without a file, comma-separated. */
		_n( '%s still needs a file.', '%s still need a file.', count( $aggr_check_missing ), 'aggressive-ads' ),
		implode( ', ', $aggr_check_missing )
	);
}

$aggr_check_host = (string) wp_parse_url( home_url(), PHP_URL_HOST );

$aggr_check_package = (string) ( $aggr_campaign['package_name'] ?? '' );
$aggr_check_price   = (string) ( $aggr_campaign['package_price'] ?? '' );
$aggr_check_link    = (string) ( $aggr_campaign['default_click_url'] ?? '' );
$aggr_check_tracker = (string) ( $aggr_campaign['click_tracker'] ?? '' );
$aggr_check_rows    = array(
	array(
		'key'    => 'package',
		'title'  => __( 'Package', 'aggressive-ads' ),
		'detail' => '' === $aggr_check_package ? __( 'Not chosen yet', 'aggressive-ads' ) : trim( $aggr_check_package . ( '' === $aggr_check_price ? '' : ' · ' . $aggr_check_price ) ),
		'href'   => add_query_arg( 'step', 'details', $aggr_campaign_url ) . '#aggr-packages',
		'action' => __( 'Change', 'aggressive-ads' ),
	),
	array(
		'key'    => 'schedule',
		'title'  => __( 'Schedule', 'aggressive-ads' ),
		'detail' => (string) ( $aggr_campaign['dates'] ?? '' ) . ( $aggr_run_days > 0
			? ' · ' . sprintf(
				/* translators: %d: number of days. */
				_n( '%d day', '%d days', $aggr_run_days, 'aggressive-ads' ),
				$aggr_run_days
			)
			: '' ),
		'href'   => add_query_arg( 'step', 'details', $aggr_campaign_url ) . '#aggr-schedule',
		'action' => __( 'Change', 'aggressive-ads' ),
	),
	array(
		'key'    => 'destination',
		'title'  => __( 'Destination', 'aggressive-ads' ),
		'detail' => '' === $aggr_check_link ? __( 'Given with your first ad', 'aggressive-ads' ) : $aggr_check_link,
		'href'   => add_query_arg( 'step', 'creative', $aggr_campaign_url ) . '#aggr-campaign-link',
		'action' => __( 'Change', 'aggressive-ads' ),
	),
	array(
		'key'    => 'ads',
		'title'  => __( 'Ads', 'aggressive-ads' ),
		'detail' => $aggr_check_ads,
		'href'   => add_query_arg( 'step', 'creative', $aggr_campaign_url ) . '#aggr-uploads',
		'action' => __( 'Change', 'aggressive-ads' ),
	),
	array(
		'key'    => 'name',
		'title'  => __( 'Name', 'aggressive-ads' ),
		'detail' => (string) ( $aggr_campaign['title'] ?? '' ),
		'href'   => '#aggr-rename',
		'action' => __( 'Rename', 'aggressive-ads' ),
	),
);

/*
 * Only when there is one, and beside the destination it sits in front of: a
 * reader sent through a third party should be a thing the advertiser sees
 * stated before they submit, not something found later in the review.
 */
if ( '' !== $aggr_check_tracker ) {
	array_splice(
		$aggr_check_rows,
		3,
		0,
		array(
			array(
				'key'    => 'tracker',
				'title'  => __( 'Click tracker', 'aggressive-ads' ),
				'detail' => $aggr_check_tracker,
				'href'   => add_query_arg( 'step', 'creative', $aggr_campaign_url ) . '#aggr-campaign-tracker',
				'action' => __( 'Change', 'aggressive-ads' ),
			),
		)
	);
}

if ( array() !== $aggr_check_problems['account'] ) {
	$aggr_check_rows[] = array(
		'key'    => 'account',
		'title'  => __( 'Account', 'aggressive-ads' ),
		'detail' => '',
		'href'   => '',
		'action' => '',
	);
}

$aggr_check_left = 0;

foreach ( $aggr_check_rows as $aggr_check_row ) {
	if ( array() !== $aggr_check_problems[ $aggr_check_row['key'] ] ) {
		++$aggr_check_left;
	}
}
?>
<div class="aggr-form aggr-review">
	<?php if ( $aggr_review_ready ) : ?>
		<section class="aggr-step-card aggr-readiness aggr-readiness--ready" aria-labelledby="aggr-readiness-heading" role="status">
	<?php else : ?>
		<section class="aggr-step-card aggr-readiness aggr-readiness--issues" aria-labelledby="aggr-readiness-heading" role="alert">
	<?php endif; ?>
		<div class="aggr-ads-section__head">
			<div>
				<h3 id="aggr-readiness-heading">
					<?php
					esc_html_e( 'Ready check', 'aggressive-ads' );
					?>
				</h3>
				<p class="aggr-hint"><?php esc_html_e( 'Everything the review team needs, checked as you go.', 'aggressive-ads' ); ?></p>
			</div>
			<?php if ( $aggr_review_ready ) : ?>
				<span class="aggr-pill aggr-pill--live"><?php esc_html_e( 'Ready', 'aggressive-ads' ); ?></span>
			<?php else : ?>
				<span class="aggr-pill aggr-pill--pending">
					<?php
					printf(
						/* translators: %d: number of things still to resolve. */
						esc_html( _n( '%d thing left', '%d things left', $aggr_check_left, 'aggressive-ads' ) ),
						(int) $aggr_check_left
					);
					?>
				</span>
			<?php endif; ?>
		</div>

		<ul class="aggr-check">
			<?php foreach ( $aggr_check_rows as $aggr_check_row ) : ?>
				<?php
				$aggr_check_issues = $aggr_check_problems[ $aggr_check_row['key'] ];
				$aggr_check_done   = array() === $aggr_check_issues;
				$aggr_check_first  = $aggr_check_done ? array() : $aggr_check_issues[0];
				$aggr_check_href   = $aggr_check_done
					? (string) $aggr_check_row['href']
					: add_query_arg( 'step', (string) $aggr_check_first['step'], $aggr_campaign_url ) . '#' . (string) $aggr_check_first['target'];
				?>
				<li class="aggr-check__row">
					<span class="aggr-check__mark<?php echo $aggr_check_done ? ' aggr-check__mark--done' : ' aggr-check__mark--todo'; ?>" aria-hidden="true">
						<?php if ( $aggr_check_done ) : ?>
							<svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><path d="M5 12.5l4.5 4.5L19 7.5"/></svg>
						<?php endif; ?>
					</span>
					<span class="aggr-check__text">
						<span class="aggr-check__title">
							<?php echo esc_html( (string) $aggr_check_row['title'] ); ?>
							<span class="aggr-sr"><?php echo esc_html( $aggr_check_done ? __( 'done', 'aggressive-ads' ) : __( 'needs attention', 'aggressive-ads' ) ); ?></span>
						</span>
						<?php foreach ( $aggr_check_done || 'ads' === $aggr_check_row['key'] ? array( array( 'message' => $aggr_check_row['detail'] ) ) : $aggr_check_issues as $aggr_check_line ) : ?>
							<?php if ( '' !== (string) $aggr_check_line['message'] ) : ?>
								<span class="aggr-check__detail"><?php echo esc_html( (string) $aggr_check_line['message'] ); ?></span>
							<?php endif; ?>
						<?php endforeach; ?>
					</span>
					<?php if ( '' !== $aggr_check_href ) : ?>
						<a class="aggr-check__action<?php echo $aggr_check_done ? '' : ' aggr-button aggr-button--secondary aggr-button--small'; ?>" href="<?php echo esc_url( $aggr_check_href ); ?>">
							<?php
							echo esc_html(
								$aggr_check_done
									? (string) $aggr_check_row['action']
									: ( 'ads' === $aggr_check_row['key'] ? __( 'Add file', 'aggressive-ads' ) : __( 'Fix', 'aggressive-ads' ) )
							);
							?>
							<span class="aggr-sr">: <?php echo esc_html( (string) $aggr_check_row['title'] ); ?></span>
						</a>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>

		<?php
		/*
		 * The page heading renames the campaign when script runs. This is the
		 * same write for a browser where it does not, and it opens itself when
		 * the name is what is wrong, because a link that lands on a closed fold
		 * looks like a link that did nothing.
		 */
		?>
		<details id="aggr-rename" class="aggr-rename" <?php echo $aggr_title_problem ? 'open' : ''; ?>>
			<summary><?php esc_html_e( 'Rename', 'aggressive-ads' ); ?></summary>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::RENAME_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
				<input type="hidden" name="return_step" value="review">
				<?php wp_nonce_field( Campaign_Nonces::rename_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

				<div class="aggr-field">
					<label for="aggr-title"><?php esc_html_e( 'Campaign name', 'aggressive-ads' ); ?></label>
					<p id="aggr-title-hint" class="aggr-hint"><?php esc_html_e( 'Only your team and the review team see this. It is not shown with the ad.', 'aggressive-ads' ); ?></p>
					<input
						id="aggr-title"
						name="title"
						type="text"
						value="<?php echo esc_attr( (string) $aggr_campaign['title'] ); ?>"
						maxlength="160"
						required
						aria-describedby="aggr-title-hint"
						<?php echo 'aggr-title' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
					>
				</div>

				<div class="aggr-form__actions">
					<button class="aggr-button aggr-button--secondary" type="submit"><?php esc_html_e( 'Save name', 'aggressive-ads' ); ?></button>
				</div>
			</form>
		</details>
	</section>

	<section class="aggr-step-card aggr-review-preview" aria-labelledby="aggr-review-preview-heading">
		<div class="aggr-ads-section__head">
			<div>
				<h3 id="aggr-review-preview-heading"><?php esc_html_e( 'See it on the page', 'aggressive-ads' ); ?></h3>
				<p class="aggr-hint">
					<?php
					printf(
						/* translators: %s: the site's name. */
						esc_html__( 'Your ads in their placements on a %s article.', 'aggressive-ads' ),
						esc_html( get_bloginfo( 'name' ) )
					);
					?>
				</p>
			</div>
			<?php
			/*
			 * Two radios, not two buttons: choosing a width changes only how the
			 * sketch below is drawn, so it needs no script and no request, and
			 * the browser keeps the choice in the group like any other. The
			 * stylesheet reads which one is checked.
			 */
			?>
			<fieldset class="aggr-segmented aggr-segmented--choice" aria-describedby="aggr-preview-note">
				<legend class="aggr-sr"><?php esc_html_e( 'Preview width', 'aggressive-ads' ); ?></legend>
				<label>
					<input class="aggr-sr" type="radio" name="aggr-preview-width" value="desktop" checked>
					<span><?php esc_html_e( 'Desktop', 'aggressive-ads' ); ?></span>
				</label>
				<label>
					<input class="aggr-sr" type="radio" name="aggr-preview-width" value="mobile">
					<span><?php esc_html_e( 'Mobile', 'aggressive-ads' ); ?></span>
				</label>
			</fieldset>
			<span id="aggr-preview-note" class="aggr-sr"><?php esc_html_e( 'An illustration of the page. The list below names every ad.', 'aggressive-ads' ); ?></span>
		</div>

		<?php require AGGR_PLUGIN_DIR . 'templates/portal/partials/campaign-page-preview.php'; ?>

		<div class="aggr-review-creatives">
			<?php foreach ( $aggr_creatives as $aggr_creative ) : ?>
				<article class="aggr-review-creative">
					<div class="aggr-review-creative__preview"><img src="<?php echo esc_url( (string) $aggr_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $aggr_creative['alt_text'] ); ?>" loading="lazy"></div>
					<div>
						<h4><?php echo esc_html( (string) $aggr_creative['placement'] ); ?></h4>
						<p><?php echo esc_html( (string) $aggr_creative['dimensions'] ); ?></p>
						<p class="aggr-table__url"><?php echo esc_html( (string) $aggr_creative['click_url'] ); ?></p>
					</div>
				</article>
			<?php endforeach; ?>
		</div>
	</section>

	<?php if ( $aggr_review_ready ) : ?>
		<section class="aggr-step-card aggr-submit-card" aria-labelledby="aggr-submit-heading">
			<div class="aggr-ads-section__head">
				<h3 id="aggr-submit-heading"><?php esc_html_e( 'Notes for the review team', 'aggressive-ads' ); ?></h3>
				<span class="aggr-eyebrow"><?php esc_html_e( 'Optional', 'aggressive-ads' ); ?></span>
			</div>

			<form id="aggr-submit-form" class="aggr-submit" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SUBMIT_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
				<?php wp_nonce_field( Campaign_Nonces::submit_nonce_action( (int) $aggr_campaign['id'] ) ); ?>

				<?php
				/*
				 * Posted by the submit button rather than autosaved as you type.
				 * Autosave runs on a 600ms debounce, and the gap between the last
				 * keystroke and the click is exactly where a note typed quickly
				 * would be lost. Submitting it with the transition closes it.
				 */
				?>
				<div class="aggr-field">
					<label class="aggr-sr" for="aggr-advertiser-notes"><?php esc_html_e( 'Notes for the review team', 'aggressive-ads' ); ?></label>
					<textarea id="aggr-advertiser-notes" name="advertiser_notes" rows="4" maxlength="2000" placeholder="<?php esc_attr_e( 'Anything that helps the team review this campaign.', 'aggressive-ads' ); ?>"><?php echo esc_textarea( (string) $aggr_campaign['advertiser_notes'] ); ?></textarea>
				</div>

				<div class="aggr-form__actions">
					<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'creative', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to ads', 'aggressive-ads' ); ?></a>
					<button class="aggr-button aggr-wizard__primary" type="submit"><?php esc_html_e( 'Submit for review', 'aggressive-ads' ); ?></button>
				</div>
			</form>
		</section>
	<?php else : ?>
		<div class="aggr-form__actions">
			<a class="aggr-button aggr-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'creative', $aggr_campaign_url ) ); ?>"><?php esc_html_e( 'Back to ads', 'aggressive-ads' ); ?></a>
		</div>
	<?php endif; ?>

	<ul class="aggr-nextsteps">
		<li>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
			<span><?php esc_html_e( 'Editing pauses while the campaign is in review.', 'aggressive-ads' ); ?></span>
		</li>
		<li>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M9 14L4 9l5-5"/><path d="M4 9h10a6 6 0 0 1 0 12h-3"/></svg>
			<span><?php esc_html_e( 'Withdraw it any time before a reviewer starts.', 'aggressive-ads' ); ?></span>
		</li>
		<li>
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M6 16v-5a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21h4"/></svg>
			<span><?php esc_html_e( 'You are notified if changes are needed.', 'aggressive-ads' ); ?></span>
		</li>
	</ul>
</div>
