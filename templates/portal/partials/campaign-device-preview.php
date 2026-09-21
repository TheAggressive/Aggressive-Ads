<?php
/**
 * One ad as it will appear, at the widths people actually browse at.
 *
 * **The exact revision, never a copy made to look at.** P17 forbids editing a
 * reviewed revision to produce a preview, so this frames the bytes that were
 * uploaded, from an authenticated route carrying the same authorization as the
 * card's thumbnail — a document holding that one image, not the image itself,
 * because a browser handed bare artwork writes its own viewer around it.
 *
 * **Untrusted rendering.** A creative is somebody else's file, and a reviewer's
 * browser is not a safer place to run one than a visitor's, so the frame
 * carries an empty `sandbox` — every restriction on — over a response that
 * already denies everything but the image (`Domain\Preview_Frame`). The widths
 * come from there too, because the reviewer's own screen draws this same frame
 * from React and a phone that is 390 pixels in one and 375 in the other is two
 * answers to one question.
 *
 * Without script the frame still renders, at the first width: the control that
 * switches widths is a radio group whose `:checked` state drives the layout in
 * CSS, so nothing here depends on a module loading.
 *
 * Scope is inherited from the dialog.
 *
 * @var array<string, mixed> $aggr_creative The ad being previewed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Preview_Frame;

$aggr_preview_id    = 'aggr-device-' . (int) $aggr_creative['id'];
$aggr_preview_names = array(
	'phone'   => __( 'Phone', 'aggressive-ads' ),
	'tablet'  => __( 'Tablet', 'aggressive-ads' ),
	'desktop' => __( 'Desktop', 'aggressive-ads' ),
);
$aggr_preview_first = true;
?>
<div class="aggr-device" id="<?php echo esc_attr( $aggr_preview_id ); ?>">
	<fieldset class="aggr-device__widths">
		<legend class="aggr-sr"><?php esc_html_e( 'Preview width', 'aggressive-ads' ); ?></legend>
		<?php foreach ( Preview_Frame::widths() as $aggr_width_key => $aggr_width ) : ?>
			<?php $aggr_width_id = $aggr_preview_id . '-' . $aggr_width_key; ?>
			<input
				class="aggr-sr aggr-device__choice"
				type="radio"
				name="<?php echo esc_attr( $aggr_preview_id . '-width' ); ?>"
				id="<?php echo esc_attr( $aggr_width_id ); ?>"
				value="<?php echo esc_attr( $aggr_width_key ); ?>"
				<?php echo $aggr_preview_first ? 'checked' : ''; ?>
			>
			<label class="aggr-device__width" for="<?php echo esc_attr( $aggr_width_id ); ?>">
				<?php echo esc_html( $aggr_preview_names[ $aggr_width_key ] ?? $aggr_width_key ); ?>
				<span class="aggr-device__px">
					<?php
					printf(
						/* translators: %d: a width in CSS pixels, e.g. 390. */
						esc_html__( '%dpx', 'aggressive-ads' ),
						(int) $aggr_width
					);
					?>
				</span>
			</label>
			<?php $aggr_preview_first = false; ?>
		<?php endforeach; ?>
	</fieldset>

	<div class="aggr-device__stage">
		<?php
		/*
		 * A frame, not an `<img>`. The image alone would be safe enough — the
		 * type comes from an allowlist and the response says `nosniff` — but
		 * the isolation is what the phase asks to be able to point at, and a
		 * frame is where `sandbox` can be asserted. `title` because a frame
		 * needs an accessible name; the artwork's own description is on the
		 * card beside it.
		 */
		?>
		<iframe
			class="aggr-device__frame"
			src="<?php echo esc_url( (string) $aggr_creative['preview_frame'] ); ?>"
			sandbox="<?php echo esc_attr( Preview_Frame::SANDBOX ); ?>"
			referrerpolicy="no-referrer"
			loading="lazy"
			title="<?php echo esc_attr( sprintf( /* translators: %s: the placement an ad runs in. */ __( 'Preview of the ad for %s', 'aggressive-ads' ), (string) ( $aggr_creative['placement'] ?? '' ) ) ); ?>"
		></iframe>
	</div>

	<p class="aggr-hint">
		<?php
		printf(
			/* translators: %s: the ad's pixel size, e.g. 728 × 90. */
			esc_html__( 'The ad is %s. The width above is the screen it is shown on, not the ad itself.', 'aggressive-ads' ),
			esc_html( (string) ( $aggr_creative['dimensions'] ?? $aggr_creative['size'] ?? '' ) )
		);
		?>
	</p>
</div>
