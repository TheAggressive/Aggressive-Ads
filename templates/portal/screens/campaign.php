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
use LAAO_Advertiser_Portal\Portal\Campaign_Actions;
use LAAO_Advertiser_Portal\Portal\Creative_Actions;

$laao_ads_creatives          = is_array( $laao_ads_campaign['creatives'] ) ? $laao_ads_campaign['creatives'] : array();
$laao_ads_notes              = (string) $laao_ads_campaign['review_notes'];
$laao_ads_places             = is_array( $laao_ads_campaign['placements'] ) ? $laao_ads_campaign['placements'] : array();
$laao_ads_place_ids          = is_array( $laao_ads_campaign['placement_ids'] ) ? array_map( 'intval', $laao_ads_campaign['placement_ids'] ) : array();
$laao_ads_options            = is_array( $laao_ads_campaign['placement_options'] ) ? $laao_ads_campaign['placement_options'] : array();
$laao_ads_packages           = is_array( $laao_ads_campaign['package_options'] ) ? $laao_ads_campaign['package_options'] : array();
$laao_ads_slots              = is_array( $laao_ads_campaign['creative_slots'] ) ? $laao_ads_campaign['creative_slots'] : array();
$laao_ads_readiness          = is_array( $laao_ads_campaign['readiness'] ) ? $laao_ads_campaign['readiness'] : array();
$laao_ads_review_problems    = is_array( $laao_ads_readiness['problems'] ?? null ) ? $laao_ads_readiness['problems'] : array();
$laao_ads_review_ready       = true === ( $laao_ads_readiness['ready'] ?? false );
$laao_ads_package_id         = (int) $laao_ads_campaign['package_id'];
$laao_ads_notice             = Campaign_Actions::request_notice();
$laao_ads_error              = Campaign_Actions::request_error_code();
$laao_ads_error_for          = Campaign_Actions::error_field( $laao_ads_error );
$laao_ads_step               = Campaign_Actions::request_step( (string) $laao_ads_campaign['wizard_step'] );
$laao_ads_step               = in_array( $laao_ads_step, array( 'details', 'package', 'creative', 'destination', 'review', 'submit' ), true ) ? $laao_ads_step : 'review';
$laao_ads_campaign_url       = Routes::url( Request::ROUTE_CAMPAIGNS, (int) $laao_ads_campaign['id'] );
$laao_ads_creative_notice    = Creative_Actions::request_notice();
$laao_ads_creative_error     = Creative_Actions::request_error_code();
$laao_ads_error_placement    = Creative_Actions::request_error_placement();
$laao_ads_creative_error_for = Creative_Actions::error_target( $laao_ads_creative_error, $laao_ads_error_placement );
$laao_ads_min_start_date     = ( new \DateTimeImmutable( 'tomorrow', wp_timezone() ) )->format( 'Y-m-d' );
$laao_ads_creative_ready     = array() !== $laao_ads_slots;

foreach ( $laao_ads_slots as $laao_ads_slot ) {
	if ( ! $laao_ads_slot['active'] || 1 !== count( $laao_ads_slot['creatives'] ) ) {
		$laao_ads_creative_ready = false;
	}
}

if ( 'creative' === $laao_ads_step && '' !== $laao_ads_creative_notice ) {
	$laao_ads_notice = '';
}
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

<?php if ( in_array( $laao_ads_notice, array( 'created', 'saved', 'package_saved', 'schedule_saved', 'submitted' ), true ) ) : ?>
	<div class="laao-ads-alert laao-ads-alert--success" role="status">
		<p>
			<?php
				echo esc_html(
					match ( $laao_ads_notice ) {
						'created'        => __( 'Campaign created. Add the details below to continue.', 'laao-advertiser-portal' ),
						'package_saved'  => __( 'Package saved. Add one correctly sized creative for each placement.', 'laao-advertiser-portal' ),
						'schedule_saved' => __( 'Destinations and schedule saved. Continue to review when you are ready.', 'laao-advertiser-portal' ),
						'submitted'      => __( 'Campaign submitted. It is now in the review queue.', 'laao-advertiser-portal' ),
						default          => __( 'Details saved. Choose a package to continue.', 'laao-advertiser-portal' ),
					}
				);
			?>
		</p>
	</div>
<?php elseif ( 'error' === $laao_ads_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--error" role="alert" tabindex="-1">
		<h2><?php esc_html_e( 'There is a problem', 'laao-advertiser-portal' ); ?></h2>
		<p id="laao-ads-campaign-error">
			<?php if ( '' !== $laao_ads_error_for ) : ?>
				<a href="#<?php echo esc_attr( $laao_ads_error_for ); ?>">
					<?php echo esc_html( Campaign_Actions::error_message( $laao_ads_error ) ); ?>
				</a>
			<?php else : ?>
				<?php echo esc_html( Campaign_Actions::error_message( $laao_ads_error ) ); ?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( 'creative_uploaded' === $laao_ads_creative_notice || 'creative_removed' === $laao_ads_creative_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--success" role="status">
		<p>
			<?php echo esc_html( 'creative_uploaded' === $laao_ads_creative_notice ? __( 'Creative uploaded and stored privately.', 'laao-advertiser-portal' ) : __( 'Creative removed.', 'laao-advertiser-portal' ) ); ?>
		</p>
	</div>
<?php elseif ( 'error' === $laao_ads_creative_notice ) : ?>
	<div class="laao-ads-alert laao-ads-alert--error" role="alert" tabindex="-1">
		<h2><?php esc_html_e( 'There is a problem with the creative', 'laao-advertiser-portal' ); ?></h2>
		<p id="laao-ads-creative-error">
			<?php if ( '' !== $laao_ads_creative_error_for ) : ?>
				<a href="#<?php echo esc_attr( $laao_ads_creative_error_for ); ?>"><?php echo esc_html( Creative_Actions::error_message( $laao_ads_creative_error ) ); ?></a>
			<?php else : ?>
				<?php echo esc_html( Creative_Actions::error_message( $laao_ads_creative_error ) ); ?>
			<?php endif; ?>
		</p>
	</div>
<?php endif; ?>

<?php if ( '' !== $laao_ads_notes ) : ?>
	<section class="laao-ads-notice" aria-labelledby="laao-ads-notes-heading">
		<h2 id="laao-ads-notes-heading" class="laao-ads-notice__head">
			<?php esc_html_e( 'Notes from the review team', 'laao-advertiser-portal' ); ?>
		</h2>
		<p><?php echo esc_html( $laao_ads_notes ); ?></p>
	</section>
<?php endif; ?>

<?php if ( true === $laao_ads_campaign['editable'] ) : ?>
	<section class="laao-ads-panel" aria-labelledby="laao-ads-details-heading">
		<div class="laao-ads-panel__head">
			<p class="laao-ads-eyebrow">
				<?php
				echo esc_html(
					match ( $laao_ads_step ) {
						'details'  => __( 'Step 1 of 6', 'laao-advertiser-portal' ),
						'package'  => __( 'Step 2 of 6', 'laao-advertiser-portal' ),
						'creative' => __( 'Step 3 of 6', 'laao-advertiser-portal' ),
						'destination' => __( 'Step 4 of 6', 'laao-advertiser-portal' ),
						'review'      => __( 'Step 5 of 6', 'laao-advertiser-portal' ),
						default       => __( 'Step 6 of 6', 'laao-advertiser-portal' ),
					}
				);
				?>
			</p>
			<h2 id="laao-ads-details-heading">
				<?php
				echo esc_html(
					match ( $laao_ads_step ) {
						'details'  => __( 'Campaign details', 'laao-advertiser-portal' ),
						'package'  => __( 'Choose a package', 'laao-advertiser-portal' ),
						'creative' => __( 'Upload creative', 'laao-advertiser-portal' ),
						'destination' => __( 'Confirm destinations and schedule', 'laao-advertiser-portal' ),
						'review'      => __( 'Review your campaign', 'laao-advertiser-portal' ),
						default       => __( 'Submit your campaign', 'laao-advertiser-portal' ),
					}
				);
				?>
			</h2>
		</div>

		<ol class="laao-ads-steps" aria-label="<?php esc_attr_e( 'Campaign creation progress', 'laao-advertiser-portal' ); ?>">
			<li <?php echo 'details' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<a href="<?php echo esc_url( add_query_arg( 'step', 'details', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Details', 'laao-advertiser-portal' ); ?></a>
			</li>
			<li <?php echo 'package' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<a href="<?php echo esc_url( add_query_arg( 'step', 'package', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Package', 'laao-advertiser-portal' ); ?></a>
			</li>
			<li <?php echo 'creative' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<a href="<?php echo esc_url( add_query_arg( 'step', 'creative', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Creative', 'laao-advertiser-portal' ); ?></a>
			</li>
			<li <?php echo 'destination' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<a href="<?php echo esc_url( add_query_arg( 'step', 'destination', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Destination and schedule', 'laao-advertiser-portal' ); ?></a>
			</li>
			<li <?php echo 'review' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<a href="<?php echo esc_url( add_query_arg( 'step', 'review', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Review', 'laao-advertiser-portal' ); ?></a>
			</li>
			<li <?php echo 'submit' === $laao_ads_step ? 'aria-current="step"' : ''; ?>>
				<?php if ( $laao_ads_review_ready && 'submit' !== $laao_ads_step ) : ?>
					<a href="<?php echo esc_url( add_query_arg( 'step', 'submit', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Submit', 'laao-advertiser-portal' ); ?></a>
				<?php else : ?>
					<?php esc_html_e( 'Submit', 'laao-advertiser-portal' ); ?>
				<?php endif; ?>
			</li>
		</ol>

		<?php if ( 'details' === $laao_ads_step ) : ?>
		<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
			<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $laao_ads_campaign['autosave_rev'] ); ?>">
			<?php wp_nonce_field( Campaign_Actions::save_nonce_action( (int) $laao_ads_campaign['id'] ) ); ?>

			<div class="laao-ads-field">
				<label for="laao-ads-title"><?php esc_html_e( 'Campaign name', 'laao-advertiser-portal' ); ?></label>
				<p id="laao-ads-title-hint" class="laao-ads-hint"><?php esc_html_e( 'Use a name your team will recognize. This is not shown with the ad.', 'laao-advertiser-portal' ); ?></p>
				<input
					id="laao-ads-title"
					name="title"
					type="text"
					value="<?php echo esc_attr( (string) $laao_ads_campaign['title'] ); ?>"
					maxlength="160"
					required
					aria-describedby="laao-ads-title-hint"
					<?php echo 'laao-ads-title' === $laao_ads_error_for ? 'aria-invalid="true"' : ''; ?>
				>
			</div>

			<fieldset id="laao-ads-placements" class="laao-ads-fieldset" <?php echo 'laao-ads-placements' === $laao_ads_error_for ? 'aria-invalid="true"' : ''; ?>>
				<legend><?php esc_html_e( 'Placement interests', 'laao-advertiser-portal' ); ?></legend>
				<p class="laao-ads-hint"><?php esc_html_e( 'Optional. Note where you would like the campaign to appear. The package selected in the next step sets the final placement list.', 'laao-advertiser-portal' ); ?></p>

				<?php if ( array() === $laao_ads_options ) : ?>
					<p><?php esc_html_e( 'No placements are available right now. You can save the draft and return later.', 'laao-advertiser-portal' ); ?></p>
				<?php else : ?>
					<div class="laao-ads-choicegrid">
						<?php foreach ( $laao_ads_options as $laao_ads_option ) : ?>
							<label class="laao-ads-choice">
								<input
									type="checkbox"
									name="placement_ids[]"
									value="<?php echo esc_attr( (string) $laao_ads_option['id'] ); ?>"
									<?php checked( in_array( (int) $laao_ads_option['id'], $laao_ads_place_ids, true ) ); ?>
								>
								<span>
									<strong><?php echo esc_html( (string) $laao_ads_option['name'] ); ?></strong>
									<small><?php echo esc_html( (string) $laao_ads_option['size'] ); ?> px</small>
								</span>
							</label>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</fieldset>

			<div class="laao-ads-field">
				<label for="laao-ads-advertiser-notes"><?php esc_html_e( 'Notes for the review team', 'laao-advertiser-portal' ); ?></label>
				<p id="laao-ads-notes-hint" class="laao-ads-hint"><?php esc_html_e( 'Optional. Include context that will help the team review this campaign.', 'laao-advertiser-portal' ); ?></p>
				<textarea id="laao-ads-advertiser-notes" name="advertiser_notes" rows="4" aria-describedby="laao-ads-notes-hint"><?php echo esc_textarea( (string) $laao_ads_campaign['advertiser_notes'] ); ?></textarea>
			</div>

			<div class="laao-ads-form__actions">
				<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Save and continue', 'laao-advertiser-portal' ); ?></button>
			</div>
		</form>
		<?php elseif ( 'package' === $laao_ads_step ) : ?>
			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_PACKAGE_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $laao_ads_campaign['autosave_rev'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::package_nonce_action( (int) $laao_ads_campaign['id'] ) ); ?>

				<fieldset id="laao-ads-packages" class="laao-ads-fieldset" <?php echo 'laao-ads-packages' === $laao_ads_error_for ? 'aria-invalid="true"' : ''; ?>>
					<legend><?php esc_html_e( 'Available packages', 'laao-advertiser-portal' ); ?></legend>
					<p class="laao-ads-hint"><?php esc_html_e( 'Selecting a package copies its current price and placements into this campaign so later catalogue changes cannot alter your draft.', 'laao-advertiser-portal' ); ?></p>

					<?php if ( array() === $laao_ads_packages ) : ?>
						<div class="laao-ads-empty">
							<p class="laao-ads-empty__title"><?php esc_html_e( 'No packages are available', 'laao-advertiser-portal' ); ?></p>
							<p><?php esc_html_e( 'The catalogue is not configured yet. Your draft is safe; please return later or get in touch.', 'laao-advertiser-portal' ); ?></p>
						</div>
					<?php else : ?>
						<div class="laao-ads-choicegrid laao-ads-choicegrid--packages">
							<?php foreach ( $laao_ads_packages as $laao_ads_package ) : ?>
								<label class="laao-ads-choice laao-ads-choice--package">
									<input
										type="radio"
										name="package_id"
										value="<?php echo esc_attr( (string) $laao_ads_package['id'] ); ?>"
										required
										<?php checked( (int) $laao_ads_package['id'], $laao_ads_package_id ); ?>
									>
									<span>
										<strong><?php echo esc_html( (string) $laao_ads_package['name'] ); ?></strong>
										<small><?php echo esc_html( (string) $laao_ads_package['price'] . ' · ' . (string) $laao_ads_package['duration'] ); ?></small>
										<small><?php echo esc_html( implode( ', ', $laao_ads_package['placements'] ) ); ?></small>
									</span>
								</label>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</fieldset>

				<div class="laao-ads-form__actions">
					<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'details', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to details', 'laao-advertiser-portal' ); ?></a>
					<?php if ( array() !== $laao_ads_packages ) : ?>
						<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Save package', 'laao-advertiser-portal' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php elseif ( 'creative' === $laao_ads_step ) : ?>
			<div class="laao-ads-form">
				<p class="laao-ads-hint"><?php esc_html_e( 'Upload one image for every placement. Files stay in private storage until staff approve the campaign. JPEG, PNG, GIF, and WebP are supported, up to 2 MB.', 'laao-advertiser-portal' ); ?></p>

				<?php if ( array() === $laao_ads_slots ) : ?>
					<div class="laao-ads-empty">
						<p class="laao-ads-empty__title"><?php esc_html_e( 'Choose a package first', 'laao-advertiser-portal' ); ?></p>
						<p><?php esc_html_e( 'A package supplies the placements and exact image sizes required for this campaign.', 'laao-advertiser-portal' ); ?></p>
					</div>
				<?php else : ?>
					<div class="laao-ads-upload-list">
						<?php foreach ( $laao_ads_slots as $laao_ads_slot ) : ?>
							<section class="laao-ads-upload-card" aria-labelledby="laao-ads-slot-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>">
								<div class="laao-ads-upload-card__head">
									<div>
										<h3 id="laao-ads-slot-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>"><?php echo esc_html( (string) $laao_ads_slot['name'] ); ?></h3>
										<p>
											<?php
											/* translators: %s: required image dimensions, e.g. 728x90. */
											printf( esc_html__( 'Required image size: %s pixels', 'laao-advertiser-portal' ), esc_html( (string) $laao_ads_slot['size'] ) );
											?>
										</p>
									</div>
									<span class="laao-ads-pill laao-ads-pill--<?php echo array() === $laao_ads_slot['creatives'] ? 'pending' : 'live'; ?>">
										<?php echo esc_html( array() === $laao_ads_slot['creatives'] ? __( 'Creative needed', 'laao-advertiser-portal' ) : __( 'Uploaded', 'laao-advertiser-portal' ) ); ?>
									</span>
								</div>

								<?php if ( ! $laao_ads_slot['active'] ) : ?>
									<div class="laao-ads-alert laao-ads-alert--error" role="alert">
										<p><?php esc_html_e( 'This placement is no longer available. Return to the package step and choose an available package.', 'laao-advertiser-portal' ); ?></p>
									</div>
								<?php elseif ( array() !== $laao_ads_slot['creatives'] ) : ?>
									<?php foreach ( $laao_ads_slot['creatives'] as $laao_ads_creative ) : ?>
										<div class="laao-ads-uploaded">
											<div class="laao-ads-uploaded__preview">
												<img src="<?php echo esc_url( (string) $laao_ads_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $laao_ads_creative['alt_text'] ); ?>" loading="lazy">
											</div>
											<div class="laao-ads-uploaded__details">
												<p><strong><?php echo esc_html( (string) $laao_ads_creative['name'] ); ?></strong></p>
												<p><?php echo esc_html( (string) $laao_ads_creative['dimensions'] . ' · ' . size_format( (int) $laao_ads_creative['bytes'] ) ); ?></p>
												<p><?php echo esc_html( (string) $laao_ads_creative['alt_text'] ); ?></p>
												<p class="laao-ads-table__url"><?php echo esc_html( (string) $laao_ads_creative['click_url'] ); ?></p>
												<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
													<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::REMOVE_ACTION ); ?>">
													<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
													<input type="hidden" name="creative_id" value="<?php echo esc_attr( (string) $laao_ads_creative['id'] ); ?>">
													<?php wp_nonce_field( Creative_Actions::remove_nonce_action( (int) $laao_ads_creative['id'] ) ); ?>
													<button class="laao-ads-button laao-ads-button--danger" type="submit"><?php esc_html_e( 'Remove creative', 'laao-advertiser-portal' ); ?></button>
												</form>
											</div>
										</div>
									<?php endforeach; ?>
								<?php else : ?>
									<form class="laao-ads-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="<?php echo esc_attr( Creative_Actions::UPLOAD_ACTION ); ?>">
										<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
										<input type="hidden" name="placement_id" value="<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>">
										<?php wp_nonce_field( Creative_Actions::upload_nonce_action( (int) $laao_ads_campaign['id'], (int) $laao_ads_slot['id'] ) ); ?>

										<div class="laao-ads-field">
											<label for="laao-ads-file-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>"><?php esc_html_e( 'Image file', 'laao-advertiser-portal' ); ?></label>
											<p id="laao-ads-file-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" class="laao-ads-hint">
												<?php
												/* translators: %s: required image dimensions, e.g. 728x90. */
												printf( esc_html__( 'Required: %s pixels. Maximum file size: 2 MB.', 'laao-advertiser-portal' ), esc_html( (string) $laao_ads_slot['size'] ) );
												?>
											</p>
											<input id="laao-ads-file-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" name="file" type="file" accept="image/jpeg,image/png,image/gif,image/webp" required aria-describedby="laao-ads-file-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?><?php echo ( 'laao-ads-file-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? ' laao-ads-creative-error' : ''; ?>" <?php echo ( 'laao-ads-file-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
										</div>

										<div class="laao-ads-field">
											<label for="laao-ads-click-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>"><?php esc_html_e( 'Destination URL', 'laao-advertiser-portal' ); ?></label>
											<p id="laao-ads-click-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" class="laao-ads-hint"><?php esc_html_e( 'Where someone should go after selecting the advertisement. Use a complete http or https URL.', 'laao-advertiser-portal' ); ?></p>
											<input id="laao-ads-click-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" name="click_url" type="url" inputmode="url" required aria-describedby="laao-ads-click-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?><?php echo ( 'laao-ads-click-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? ' laao-ads-creative-error' : ''; ?>" <?php echo ( 'laao-ads-click-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
										</div>

										<div class="laao-ads-field">
											<label for="laao-ads-alt-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>"><?php esc_html_e( 'Image description', 'laao-advertiser-portal' ); ?></label>
											<p id="laao-ads-alt-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" class="laao-ads-hint"><?php esc_html_e( 'Briefly describe the meaningful content for people who cannot see the image. Do not repeat “image of.”', 'laao-advertiser-portal' ); ?></p>
											<input id="laao-ads-alt-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?>" name="alt_text" type="text" maxlength="500" required aria-describedby="laao-ads-alt-hint-<?php echo esc_attr( (string) $laao_ads_slot['id'] ); ?><?php echo ( 'laao-ads-alt-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? ' laao-ads-creative-error' : ''; ?>" <?php echo ( 'laao-ads-alt-' . $laao_ads_slot['id'] ) === $laao_ads_creative_error_for ? 'aria-invalid="true"' : ''; ?>>
										</div>

										<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Upload creative', 'laao-advertiser-portal' ); ?></button>
									</form>
								<?php endif; ?>
							</section>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div class="laao-ads-form__actions">
					<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'package', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to package', 'laao-advertiser-portal' ); ?></a>
					<?php if ( $laao_ads_creative_ready ) : ?>
						<a class="laao-ads-button" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Continue to schedule', 'laao-advertiser-portal' ); ?></a>
					<?php else : ?>
						<p class="laao-ads-hint"><?php esc_html_e( 'Upload one creative for every active package placement to continue.', 'laao-advertiser-portal' ); ?></p>
					<?php endif; ?>
				</div>
			</div>
		<?php elseif ( 'destination' === $laao_ads_step ) : ?>
			<form class="laao-ads-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_SCHEDULE_ACTION ); ?>">
				<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
				<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $laao_ads_campaign['autosave_rev'] ); ?>">
				<?php wp_nonce_field( Campaign_Actions::schedule_nonce_action( (int) $laao_ads_campaign['id'] ) ); ?>

				<section id="laao-ads-destinations" class="laao-ads-confirmation" aria-labelledby="laao-ads-destinations-heading">
					<h3 id="laao-ads-destinations-heading"><?php esc_html_e( 'Creative destinations', 'laao-advertiser-portal' ); ?></h3>
					<p class="laao-ads-hint"><?php esc_html_e( 'Confirm where each advertisement sends visitors. Return to the creative step if an address or image description needs to change.', 'laao-advertiser-portal' ); ?></p>

					<?php if ( ! $laao_ads_creative_ready ) : ?>
						<div class="laao-ads-alert laao-ads-alert--error" role="alert">
							<p><?php esc_html_e( 'Every active package placement needs exactly one creative before this schedule can be completed.', 'laao-advertiser-portal' ); ?></p>
						</div>
					<?php endif; ?>

					<div class="laao-ads-destination-list">
						<?php foreach ( $laao_ads_creatives as $laao_ads_creative ) : ?>
							<article class="laao-ads-destination-card">
								<h4><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></h4>
								<p class="laao-ads-table__url"><?php echo esc_html( (string) $laao_ads_creative['click_url'] ); ?></p>
								<p><?php echo esc_html( (string) $laao_ads_creative['alt_text'] ); ?></p>
							</article>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="laao-ads-formgrid">
					<div class="laao-ads-field">
						<label for="laao-ads-start-date"><?php esc_html_e( 'Start date', 'laao-advertiser-portal' ); ?></label>
						<p id="laao-ads-start-hint" class="laao-ads-hint"><?php esc_html_e( 'Required. The campaign begins at the start of this day in the site timezone.', 'laao-advertiser-portal' ); ?></p>
						<input
							id="laao-ads-start-date"
							name="start_date"
							type="date"
							value="<?php echo esc_attr( (string) $laao_ads_campaign['start_date'] ); ?>"
							min="<?php echo esc_attr( $laao_ads_min_start_date ); ?>"
							required
							aria-describedby="laao-ads-start-hint<?php echo 'laao-ads-start-date' === $laao_ads_error_for ? ' laao-ads-campaign-error' : ''; ?>"
							<?php echo 'laao-ads-start-date' === $laao_ads_error_for ? 'aria-invalid="true"' : ''; ?>
						>
					</div>

					<div class="laao-ads-field">
						<label for="laao-ads-end-date"><?php esc_html_e( 'End date', 'laao-advertiser-portal' ); ?></label>
						<p id="laao-ads-end-hint" class="laao-ads-hint"><?php esc_html_e( 'Optional. The campaign runs through the end of this day.', 'laao-advertiser-portal' ); ?></p>
						<input
							id="laao-ads-end-date"
							name="end_date"
							type="date"
							value="<?php echo esc_attr( (string) $laao_ads_campaign['end_date'] ); ?>"
							aria-describedby="laao-ads-end-hint<?php echo 'laao-ads-end-date' === $laao_ads_error_for ? ' laao-ads-campaign-error' : ''; ?>"
							<?php echo 'laao-ads-end-date' === $laao_ads_error_for ? 'aria-invalid="true"' : ''; ?>
						>
					</div>
				</div>

				<div class="laao-ads-form__actions">
					<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'creative', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to creative', 'laao-advertiser-portal' ); ?></a>
					<?php if ( $laao_ads_creative_ready ) : ?>
						<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Save and continue to review', 'laao-advertiser-portal' ); ?></button>
					<?php endif; ?>
				</div>
			</form>
		<?php elseif ( 'review' === $laao_ads_step ) : ?>
			<div class="laao-ads-form laao-ads-review">
				<?php if ( $laao_ads_review_ready ) : ?>
					<section class="laao-ads-readiness laao-ads-readiness--ready" aria-labelledby="laao-ads-readiness-heading" role="status">
						<h3 id="laao-ads-readiness-heading"><?php esc_html_e( 'Ready for submission', 'laao-advertiser-portal' ); ?></h3>
						<p><?php esc_html_e( 'The campaign currently passes every submission check. Review the information below before continuing.', 'laao-advertiser-portal' ); ?></p>
					</section>
				<?php else : ?>
					<section class="laao-ads-readiness laao-ads-readiness--issues" aria-labelledby="laao-ads-readiness-heading" role="alert">
						<h3 id="laao-ads-readiness-heading"><?php esc_html_e( 'Changes are still needed', 'laao-advertiser-portal' ); ?></h3>
						<p><?php esc_html_e( 'Resolve every item below before submitting this campaign.', 'laao-advertiser-portal' ); ?></p>
						<ol class="laao-ads-readiness__list">
							<?php foreach ( $laao_ads_review_problems as $laao_ads_problem ) : ?>
								<li>
									<span><?php echo esc_html( (string) $laao_ads_problem['message'] ); ?></span>
									<a href="<?php echo esc_url( add_query_arg( 'step', (string) $laao_ads_problem['step'], $laao_ads_campaign_url ) . '#' . (string) $laao_ads_problem['target'] ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
				<?php endif; ?>

				<div class="laao-ads-review-grid">
					<section class="laao-ads-review-card" aria-labelledby="laao-ads-review-details-heading">
						<div class="laao-ads-review-card__head">
							<h3 id="laao-ads-review-details-heading"><?php esc_html_e( 'Campaign details', 'laao-advertiser-portal' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'details', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
						</div>
						<dl class="laao-ads-review-list">
							<div><dt><?php esc_html_e( 'Name', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_campaign['title'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Notes', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( '' === (string) $laao_ads_campaign['advertiser_notes'] ? __( 'None', 'laao-advertiser-portal' ) : (string) $laao_ads_campaign['advertiser_notes'] ); ?></dd></div>
						</dl>
					</section>

					<section class="laao-ads-review-card" aria-labelledby="laao-ads-review-package-heading">
						<div class="laao-ads-review-card__head">
							<h3 id="laao-ads-review-package-heading"><?php esc_html_e( 'Package', 'laao-advertiser-portal' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'package', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
						</div>
						<dl class="laao-ads-review-list">
							<div><dt><?php esc_html_e( 'Package', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( '' === (string) $laao_ads_campaign['package_name'] ? __( 'Not selected', 'laao-advertiser-portal' ) : (string) $laao_ads_campaign['package_name'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Price', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( '' === (string) $laao_ads_campaign['package_price'] ? '—' : (string) $laao_ads_campaign['package_price'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Placements', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( array() === $laao_ads_places ? __( 'None selected', 'laao-advertiser-portal' ) : implode( ', ', $laao_ads_places ) ); ?></dd></div>
						</dl>
					</section>

					<section class="laao-ads-review-card" aria-labelledby="laao-ads-review-schedule-heading">
						<div class="laao-ads-review-card__head">
							<h3 id="laao-ads-review-schedule-heading"><?php esc_html_e( 'Schedule', 'laao-advertiser-portal' ); ?></h3>
							<a href="<?php echo esc_url( add_query_arg( 'step', 'destination', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
						</div>
						<p><?php echo esc_html( (string) $laao_ads_campaign['dates'] ); ?></p>
					</section>
				</div>

				<section class="laao-ads-review-card laao-ads-review-card--wide" aria-labelledby="laao-ads-review-creative-heading">
					<div class="laao-ads-review-card__head">
						<h3 id="laao-ads-review-creative-heading"><?php esc_html_e( 'Creative', 'laao-advertiser-portal' ); ?></h3>
						<a href="<?php echo esc_url( add_query_arg( 'step', 'creative', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
					</div>
					<div class="laao-ads-review-creatives">
						<?php foreach ( $laao_ads_creatives as $laao_ads_creative ) : ?>
							<article class="laao-ads-review-creative">
								<div class="laao-ads-review-creative__preview"><img src="<?php echo esc_url( (string) $laao_ads_creative['preview'] ); ?>" alt="<?php echo esc_attr( (string) $laao_ads_creative['alt_text'] ); ?>" loading="lazy"></div>
								<div>
									<h4><?php echo esc_html( (string) $laao_ads_creative['placement'] ); ?></h4>
									<p><?php echo esc_html( (string) $laao_ads_creative['dimensions'] ); ?></p>
									<p class="laao-ads-table__url"><?php echo esc_html( (string) $laao_ads_creative['click_url'] ); ?></p>
									<p><?php echo esc_html( (string) $laao_ads_creative['alt_text'] ); ?></p>
								</div>
							</article>
						<?php endforeach; ?>
					</div>
				</section>

				<div class="laao-ads-form__actions">
					<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'destination', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to schedule', 'laao-advertiser-portal' ); ?></a>
					<?php if ( $laao_ads_review_ready ) : ?>
						<a class="laao-ads-button" href="<?php echo esc_url( add_query_arg( 'step', 'submit', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Continue to submit', 'laao-advertiser-portal' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
		<?php else : ?>
			<div class="laao-ads-form laao-ads-submit">
				<?php if ( $laao_ads_review_ready ) : ?>
					<section class="laao-ads-submit-card" aria-labelledby="laao-ads-submit-heading">
						<h3 id="laao-ads-submit-heading"><?php esc_html_e( 'Send this campaign to the review team?', 'laao-advertiser-portal' ); ?></h3>
						<p><?php esc_html_e( 'Submission locks campaign editing while the review team checks the creative, destinations, schedule, and placement availability.', 'laao-advertiser-portal' ); ?></p>
						<ul>
							<li><?php esc_html_e( 'You can withdraw the campaign only until a reviewer claims it.', 'laao-advertiser-portal' ); ?></li>
							<li><?php esc_html_e( 'If changes are requested, the campaign becomes editable again.', 'laao-advertiser-portal' ); ?></li>
							<li><?php esc_html_e( 'The submission checks run again when you press the button below.', 'laao-advertiser-portal' ); ?></li>
						</ul>
						<dl class="laao-ads-review-list">
							<div><dt><?php esc_html_e( 'Campaign', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_campaign['title'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Package', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_campaign['package_name'] ); ?></dd></div>
							<div><dt><?php esc_html_e( 'Schedule', 'laao-advertiser-portal' ); ?></dt><dd><?php echo esc_html( (string) $laao_ads_campaign['dates'] ); ?></dd></div>
						</dl>
					</section>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SUBMIT_ACTION ); ?>">
						<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $laao_ads_campaign['id'] ); ?>">
						<?php wp_nonce_field( Campaign_Actions::submit_nonce_action( (int) $laao_ads_campaign['id'] ) ); ?>
						<div class="laao-ads-form__actions">
							<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'review', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to review', 'laao-advertiser-portal' ); ?></a>
							<button class="laao-ads-button" type="submit"><?php esc_html_e( 'Submit campaign for review', 'laao-advertiser-portal' ); ?></button>
						</div>
					</form>
				<?php else : ?>
					<section class="laao-ads-readiness laao-ads-readiness--issues" aria-labelledby="laao-ads-readiness-heading" role="alert">
						<h3 id="laao-ads-readiness-heading"><?php esc_html_e( 'Campaign is no longer ready', 'laao-advertiser-portal' ); ?></h3>
						<p><?php esc_html_e( 'Resolve every item below before returning to submission.', 'laao-advertiser-portal' ); ?></p>
						<ol class="laao-ads-readiness__list">
							<?php foreach ( $laao_ads_review_problems as $laao_ads_problem ) : ?>
								<li>
									<span><?php echo esc_html( (string) $laao_ads_problem['message'] ); ?></span>
									<a href="<?php echo esc_url( add_query_arg( 'step', (string) $laao_ads_problem['step'], $laao_ads_campaign_url ) . '#' . (string) $laao_ads_problem['target'] ); ?>"><?php esc_html_e( 'Edit', 'laao-advertiser-portal' ); ?></a>
								</li>
							<?php endforeach; ?>
						</ol>
					</section>
					<div class="laao-ads-form__actions">
						<a class="laao-ads-button laao-ads-button--secondary" href="<?php echo esc_url( add_query_arg( 'step', 'review', $laao_ads_campaign_url ) ); ?>"><?php esc_html_e( 'Back to review', 'laao-advertiser-portal' ); ?></a>
					</div>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</section>
<?php endif; ?>

<?php if ( true !== $laao_ads_campaign['editable'] || ! in_array( $laao_ads_step, array( 'review', 'submit' ), true ) ) : ?>
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
<?php endif; ?>
