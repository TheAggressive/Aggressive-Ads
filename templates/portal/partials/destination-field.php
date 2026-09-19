<?php
/**
 * A destination link, with its check and its tracking tags.
 *
 * **One copy, two places.** The campaign's Destination card and an ad's own
 * "Edit destination" dialog ask for the same thing, and for a while only the
 * card had the checking, the tag builder and the tidying that turns
 * `example.com` into `https://example.com` — so the same address was accepted
 * in one place and refused in the other. A fix to any of it has to reach both,
 * which it only does while there is one of it.
 *
 * The caller owns the form: what it posts, what saves it, and the nonce it
 * carries. This owns the field and the conveniences beside it, and the module
 * binds to the attributes rather than to either caller.
 *
 * @var string                    $aggr_dest_field_id    Input id; the status line derives its own from it.
 * @var string                    $aggr_dest_field_name  Posted field name.
 * @var string                    $aggr_dest_field_value The stored link.
 * @var string                    $aggr_dest_field_label Its label, read by screen readers.
 * @var array<string, mixed>|null $aggr_dest_field_check The last check of this link, if any.
 * @var bool                      $aggr_dest_field_error Whether the current refusal belongs to this field.
 * @var string                    $aggr_dest_field_used  A line beside the controls, or empty.
 * @var string                    $aggr_dest_field_save  Label for the no-script save button, or empty for none.
 * @var string                    $aggr_dest_field_check_url Where the server checks this link.
 * @var array<string, mixed>      $aggr_campaign         The campaign, which fills the tags in.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Domain\Click_Macros;
use Aggressive\Ads\Domain\Link_Check_Rules;

$aggr_dest_field_check = $aggr_dest_field_check ?? null;
$aggr_dest_field_error = true === ( $aggr_dest_field_error ?? false );
$aggr_dest_field_used  = (string) ( $aggr_dest_field_used ?? '' );
$aggr_dest_field_save  = (string) ( $aggr_dest_field_save ?? '' );
?>
<div
	class="aggr-destination-field"
	data-aggr-link-check="<?php echo esc_url( $aggr_dest_field_check_url ); ?>"
	data-aggr-link-field="<?php echo esc_attr( $aggr_dest_field_name ); ?>"
	data-aggr-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
	data-aggr-label-works="<?php esc_attr_e( 'Link works', 'aggressive-ads' ); ?>"
	data-aggr-label-missing="<?php esc_attr_e( 'Page not found', 'aggressive-ads' ); ?>"
	data-aggr-label-private="<?php esc_attr_e( 'Could not be read', 'aggressive-ads' ); ?>"
	data-aggr-label-broken="<?php esc_attr_e( 'The site returned an error', 'aggressive-ads' ); ?>"
	data-aggr-label-unreachable="<?php esc_attr_e( 'No answer', 'aggressive-ads' ); ?>"
	data-aggr-label-checking="<?php esc_attr_e( 'Checking…', 'aggressive-ads' ); ?>"
	data-aggr-label-failed="<?php esc_attr_e( 'Could not check it', 'aggressive-ads' ); ?>"
>
	<label class="aggr-sr" for="<?php echo esc_attr( $aggr_dest_field_id ); ?>"><?php echo esc_html( $aggr_dest_field_label ); ?></label>
		<div class="aggr-ads-link">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>
			<input
				id="<?php echo esc_attr( $aggr_dest_field_id ); ?>"
				class="aggr-ads-link__input"
				name="<?php echo esc_attr( $aggr_dest_field_name ); ?>"
				type="url"
				inputmode="url"
				placeholder="https://"
				aria-describedby="<?php echo esc_attr( $aggr_dest_field_id . '-status' ); ?>"
				value="<?php echo esc_attr( $aggr_dest_field_value ); ?>"
				<?php echo $aggr_dest_field_error ? 'aria-invalid="true"' : ''; ?>
			>
			<?php
			/*
			 * Two different claims, and the chip only ever makes the
			 * one it has. A saved link passed the same check a
			 * creative's does, which says it is a usable web address
			 * and nothing about whether the page is there; "Link
			 * works" is only shown once something answered.
			 */
			$aggr_link_outcome = null === $aggr_dest_field_check ? '' : (string) $aggr_dest_field_check['outcome'];
			$aggr_link_chips   = array(
				Link_Check_Rules::OUTCOME_WORKS       => array( __( 'Link works', 'aggressive-ads' ), 'aggr-pill--live' ),
				Link_Check_Rules::OUTCOME_MISSING     => array( __( 'Page not found', 'aggressive-ads' ), 'aggr-pill--danger' ),
				Link_Check_Rules::OUTCOME_PRIVATE     => array( __( 'Could not be read', 'aggressive-ads' ), 'aggr-pill--pending' ),
				Link_Check_Rules::OUTCOME_BROKEN      => array( __( 'The site returned an error', 'aggressive-ads' ), 'aggr-pill--danger' ),
				Link_Check_Rules::OUTCOME_UNREACHABLE => array( __( 'No answer', 'aggressive-ads' ), 'aggr-pill--pending' ),
			);
			$aggr_link_chip    = $aggr_link_chips[ $aggr_link_outcome ] ?? array( __( 'Valid link', 'aggressive-ads' ), 'aggr-pill--neutral' );
			?>
			<span class="aggr-pill <?php echo esc_attr( $aggr_link_chip[1] ); ?> aggr-ads-link__status" data-aggr-link-chip <?php echo '' === $aggr_dest_field_value ? 'hidden' : ''; ?>><?php echo esc_html( $aggr_link_chip[0] ); ?></span>
		</div>
		<?php
		/*
		 * Autosave is the only save the design draws. A browser without
		 * script has no autosave, so it alone gets a button.
		 */
		?>
		<p id="<?php echo esc_attr( $aggr_dest_field_id . '-status' ); ?>" class="aggr-ads-link__status-text" data-aggr-link-status role="status" aria-live="polite"></p>
		<?php if ( '' !== $aggr_dest_field_save ) : ?>
			<noscript><button class="aggr-button aggr-button--secondary aggr-button--small" type="submit"><?php echo esc_html( $aggr_dest_field_save ); ?></button></noscript>
		<?php endif; ?>
		<div class="aggr-ads-link__meta">
			<?php if ( '' !== $aggr_dest_field_used ) : ?>
				<span class="aggr-ads-link__used"><?php echo esc_html( $aggr_dest_field_used ); ?></span>
			<?php endif; ?>
			<?php
			/*
			 * Script-only, both of them. The check needs a request
			 * this form cannot make without leaving the page, and the
			 * tag builder is a preview of an edit — neither is a way
			 * to save a link, which is what this form is for.
			 */
			?>
			<button class="aggr-ads-link__check aggr-script-only" type="button" data-aggr-link-check-button disabled><?php esc_html_e( 'Check link', 'aggressive-ads' ); ?></button>
			<?php
			/*
			 * Filled in from the campaign, because the advertiser
			 * came here to run an ad, not to learn what a `utm_medium`
			 * is. The three that matter are on show with the words
			 * around them; the two that rarely apply to a display ad
			 * are behind "More options". Every value is still theirs
			 * to change, and nothing is written to the link until
			 * they press the button.
			 *
			 * The publisher's domain, which is what a media plan and
			 * every other ad server call the source — "laartsonline.com",
			 * not a slug of the site's title. The dot survives tidying.
			 */
			$aggr_tag_site = strtolower( (string) preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );

			// A development host is nobody's source; the site's name reads better than "localhost".
			if ( ! str_contains( $aggr_tag_site, '.' ) ) {
				$aggr_tag_site = sanitize_title( (string) get_bloginfo( 'name' ) );
			}

			// An invented name suggests nothing: "Untitled campaign" is not a tag.
			$aggr_tag_campaign = (bool) ( $aggr_campaign['title_is_placeholder'] ?? false )
				? ''
				: sanitize_title( (string) ( $aggr_campaign['title'] ?? '' ) );

			$aggr_tag_fields = array(
				'source'   => array( __( 'Where it came from', 'aggressive-ads' ), 'utm_source', $aggr_tag_site, false ),
				'medium'   => array( __( 'How it was shown', 'aggressive-ads' ), 'utm_medium', 'display', false ),
				'campaign' => array( __( 'Which campaign', 'aggressive-ads' ), 'utm_campaign', $aggr_tag_campaign, false ),
				'content'  => array( __( 'Which ad, when you run more than one', 'aggressive-ads' ), 'utm_content', '', true ),
				'term'     => array( __( 'Keyword, if your team uses one', 'aggressive-ads' ), 'utm_term', '', true ),
			);

			$aggr_tag_field = static function ( string $key, array $field ): void {
				?>
				<div class="aggr-field">
					<label for="aggr-tag-<?php echo esc_attr( $key ); ?>">
						<?php echo esc_html( $field[0] ); ?>
						<span class="aggr-tags__code" aria-hidden="true"><?php echo esc_html( $field[1] ); ?></span>
					</label>
					<input
						type="text"
						id="aggr-tag-<?php echo esc_attr( $key ); ?>"
						data-aggr-tag="<?php echo esc_attr( $key ); ?>"
						value="<?php echo esc_attr( $field[2] ); ?>"
						autocomplete="off"
						spellcheck="false"
					>
				</div>
				<?php
			};
			?>
			<details class="aggr-tags aggr-script-only">
				<summary class="aggr-ads-link__tags"><?php esc_html_e( 'Add tracking tags', 'aggressive-ads' ); ?></summary>
				<div
					class="aggr-tags__body"
					data-aggr-tags
					data-aggr-for="<?php echo esc_attr( $aggr_dest_field_id ); ?>"
					data-aggr-label-kept="<?php /* translators: %s: comma-separated parameter names, e.g. utm_source, utm_medium. */ esc_attr_e( 'Already on your link, so it was left alone: %s', 'aggressive-ads' ); ?>"
				>
					<p class="aggr-hint"><?php esc_html_e( 'These tell your analytics where a visit came from. Filled in from this campaign — change them if your team uses different words.', 'aggressive-ads' ); ?></p>
					<div class="aggr-tags__grid">
						<?php foreach ( $aggr_tag_fields as $aggr_tag_key => $aggr_tag_def ) : ?>
							<?php if ( ! $aggr_tag_def[3] ) : ?>
								<?php $aggr_tag_field( (string) $aggr_tag_key, $aggr_tag_def ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<details class="aggr-tags__more">
						<summary><?php esc_html_e( 'More options', 'aggressive-ads' ); ?></summary>
						<?php
						/*
						 * Values only a click knows. They are written into a
						 * tag as `{creative_id}` and filled in on the way to
						 * the advertiser's page, so one link reports which ad
						 * and which placement earned the visit — a tracking
						 * template, as every ad server has.
						 */
						$aggr_macros = array(
							Click_Macros::CREATIVE_ID  => __( 'Which ad', 'aggressive-ads' ),
							Click_Macros::PLACEMENT_ID => __( 'Which placement', 'aggressive-ads' ),
							Click_Macros::CAMPAIGN_ID  => __( 'Campaign id', 'aggressive-ads' ),
							Click_Macros::TIMESTAMP    => __( 'Time of the click', 'aggressive-ads' ),
							Click_Macros::CACHEBUSTER  => __( 'Cache buster', 'aggressive-ads' ),
							Click_Macros::CLICK_ID     => __( 'Click id', 'aggressive-ads' ),
						);
						?>
						<p class="aggr-hint"><?php esc_html_e( 'Add a value that is filled in when somebody clicks, so your reports name the ad that earned the visit.', 'aggressive-ads' ); ?></p>
						<div class="aggr-tags__macros">
							<?php foreach ( $aggr_macros as $aggr_macro => $aggr_macro_label ) : ?>
								<button class="aggr-tags__macro" type="button" data-aggr-macro="<?php echo esc_attr( $aggr_macro ); ?>">
									<?php echo esc_html( $aggr_macro_label ); ?>
									<span class="aggr-tags__code" aria-hidden="true"><?php echo esc_html( '{' . $aggr_macro . '}' ); ?></span>
								</button>
							<?php endforeach; ?>
						</div>
						<div class="aggr-tags__grid">
							<?php foreach ( $aggr_tag_fields as $aggr_tag_key => $aggr_tag_def ) : ?>
								<?php if ( $aggr_tag_def[3] ) : ?>
									<?php $aggr_tag_field( (string) $aggr_tag_key, $aggr_tag_def ); ?>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
					</details>
					<p class="aggr-tags__preview" data-aggr-tags-preview aria-live="polite"></p>
					<p class="aggr-hint" data-aggr-tags-note hidden></p>
					<button class="aggr-button aggr-button--secondary aggr-button--small" type="button" data-aggr-tags-apply disabled><?php esc_html_e( 'Add tags to link', 'aggressive-ads' ); ?></button>
				</div>
			</details>
		</div>
</div>
