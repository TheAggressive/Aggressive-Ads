<?php
/**
 * The Destination card: one link for every ad, its check and its tags.
 *
 * Creation's card, drawn by creation and by the running-campaign edit flow.
 * Two copies of it would be how one of them stops checking links.
 *
 * **What differs is where the link goes.** While drafting it is saved on the
 * campaign as it is typed, by autosave. On a running campaign it is part of a
 * proposal: the form stages it with the rest of the edit, and the check stages
 * it first and then asks about the staged link — the route still never takes
 * a URL.
 *
 * Scope is inherited from the step that requires it.
 *
 * @var array<string, mixed>      $aggr_campaign   The campaign.
 * @var string                    $aggr_dest_mode  `draft` or `edit`.
 * @var string                    $aggr_dest_link  The link the field shows.
 * @var int                       $aggr_dest_used  Ads that go to it.
 * @var int                       $aggr_dest_total Ads, or sizes while drafting.
 * @var array<string, mixed>|null $aggr_dest_check The last check of this link.
 * @var string                    $aggr_dest_next  The edit step to move to on saving.
 * @var string                    $aggr_wizard_id        Autosave id, while drafting.
 * @var string                    $aggr_autosave_context Autosave store context attribute, while drafting.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Assets\Assets;
use Aggressive\Ads\Domain\Click_Macros;
use Aggressive\Ads\Domain\Link_Check_Rules;
use Aggressive\Ads\Portal\Campaign_Actions;
use Aggressive\Ads\Portal\Campaign_Nonces;
use Aggressive\Ads\REST\Api;

$aggr_dest_editing   = 'edit' === $aggr_dest_mode;
$aggr_dest_next      = isset( $aggr_dest_next ) ? (string) $aggr_dest_next : 'review';
$aggr_error_for      = isset( $aggr_error_for ) ? (string) $aggr_error_for : '';
$aggr_dest_check_url = rest_url( Api::NAMESPACE . '/campaigns/' . (int) $aggr_campaign['id'] . '/link-check' );

// Which stored link, never a link: the one staged in the proposal.
if ( $aggr_dest_editing ) {
	$aggr_dest_check_url = add_query_arg( 'proposed', '1', $aggr_dest_check_url );
}

/*
 * One link for every ad. Every size card starts from it and can still change
 * its own. The check and the tag builder beside it are conveniences: the link
 * is saved and the step is finished without either.
 */
?>
<section class="aggr-ads-section" aria-labelledby="aggr-ads-link-heading">
	<div class="aggr-ads-section__head">
		<div>
			<p class="aggr-eyebrow"><?php esc_html_e( '01 · Destination', 'aggressive-ads' ); ?></p>
			<h3 id="aggr-ads-link-heading"><?php esc_html_e( 'Where should people go when they click?', 'aggressive-ads' ); ?></h3>
			<p class="aggr-hint">
				<?php
				echo $aggr_dest_editing
					? esc_html__( 'One link for every ad. Changing it moves every ad that uses it once the review team accepts it; an ad with its own link keeps it.', 'aggressive-ads' )
					: esc_html__( 'One link for every ad. Any single size can still have its own.', 'aggressive-ads' );
				?>
			</p>
		</div>
	</div>
	<form
		<?php echo $aggr_dest_editing ? 'id="aggr-changes-form" data-aggr-stage="1"' : ''; ?>
		method="post"
		action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
		data-aggr-link-check="<?php echo esc_url( $aggr_dest_check_url ); ?>"
		data-aggr-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_rest' ) ); ?>"
		data-aggr-label-works="<?php esc_attr_e( 'Link works', 'aggressive-ads' ); ?>"
		data-aggr-label-missing="<?php esc_attr_e( 'Page not found', 'aggressive-ads' ); ?>"
		data-aggr-label-private="<?php esc_attr_e( 'Could not be read', 'aggressive-ads' ); ?>"
		data-aggr-label-broken="<?php esc_attr_e( 'The site returned an error', 'aggressive-ads' ); ?>"
		data-aggr-label-unreachable="<?php esc_attr_e( 'No answer', 'aggressive-ads' ); ?>"
		data-aggr-label-checking="<?php esc_attr_e( 'Checking…', 'aggressive-ads' ); ?>"
		data-aggr-label-failed="<?php esc_attr_e( 'Could not check it', 'aggressive-ads' ); ?>"
		<?php if ( ! $aggr_dest_editing ) : ?>
			data-wp-interactive="<?php echo esc_attr( Assets::AUTOSAVE_STORE ); ?>"
			<?php echo $aggr_autosave_context; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_interactivity_data_wp_context(). ?>
			data-aggr-autosave="<?php echo esc_attr( $aggr_wizard_id ); ?>"
			data-wp-init="actions.init"
		<?php endif; ?>
	>
		<?php if ( $aggr_dest_editing ) : ?>
			<?php // A proposal, staged like every other step of the edit flow. ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::CHANGES_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="next_step" value="<?php echo esc_attr( $aggr_dest_next ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::changes_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
		<?php else : ?>
			<input type="hidden" name="action" value="<?php echo esc_attr( Campaign_Actions::SAVE_ACTION ); ?>">
			<input type="hidden" name="campaign_id" value="<?php echo esc_attr( (string) $aggr_campaign['id'] ); ?>">
			<input type="hidden" name="autosave_rev" value="<?php echo esc_attr( (string) $aggr_campaign['autosave_rev'] ); ?>">
			<?php wp_nonce_field( Campaign_Nonces::save_nonce_action( (int) $aggr_campaign['id'] ) ); ?>
		<?php endif; ?>

		<label class="aggr-sr" for="aggr-campaign-link"><?php esc_html_e( 'Destination link for every ad', 'aggressive-ads' ); ?></label>
		<div class="aggr-ads-link">
			<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M10 14a4 4 0 0 0 5.7 0l3-3a4 4 0 0 0-5.7-5.7l-1 1"/><path d="M14 10a4 4 0 0 0-5.7 0l-3 3a4 4 0 0 0 5.7 5.7l1-1"/></svg>
			<input
				id="aggr-campaign-link"
				class="aggr-ads-link__input"
				name="default_click_url"
				type="url"
				inputmode="url"
				placeholder="https://"
				aria-describedby="aggr-campaign-link-status"
				value="<?php echo esc_attr( $aggr_dest_link ); ?>"
				<?php echo 'aggr-campaign-link' === $aggr_error_for ? 'aria-invalid="true"' : ''; ?>
			>
			<?php
			/*
			 * Two different claims, and the chip only ever makes the
			 * one it has. A saved link passed the same check a
			 * creative's does, which says it is a usable web address
			 * and nothing about whether the page is there; "Link
			 * works" is only shown once something answered.
			 */
			$aggr_link_outcome = null === $aggr_dest_check ? '' : (string) $aggr_dest_check['outcome'];
			$aggr_link_chips   = array(
				Link_Check_Rules::OUTCOME_WORKS       => array( __( 'Link works', 'aggressive-ads' ), 'aggr-pill--live' ),
				Link_Check_Rules::OUTCOME_MISSING     => array( __( 'Page not found', 'aggressive-ads' ), 'aggr-pill--danger' ),
				Link_Check_Rules::OUTCOME_PRIVATE     => array( __( 'Could not be read', 'aggressive-ads' ), 'aggr-pill--pending' ),
				Link_Check_Rules::OUTCOME_BROKEN      => array( __( 'The site returned an error', 'aggressive-ads' ), 'aggr-pill--danger' ),
				Link_Check_Rules::OUTCOME_UNREACHABLE => array( __( 'No answer', 'aggressive-ads' ), 'aggr-pill--pending' ),
			);
			$aggr_link_chip    = $aggr_link_chips[ $aggr_link_outcome ] ?? array( __( 'Valid link', 'aggressive-ads' ), 'aggr-pill--neutral' );
			?>
			<span class="aggr-pill <?php echo esc_attr( $aggr_link_chip[1] ); ?> aggr-ads-link__status" data-aggr-link-chip <?php echo '' === $aggr_dest_link ? 'hidden' : ''; ?>><?php echo esc_html( $aggr_link_chip[0] ); ?></span>
		</div>
		<?php
		/*
		 * Autosave is the only save the design draws. A browser without
		 * script has no autosave, so it alone gets a button.
		 */
		?>
		<p id="aggr-campaign-link-status" class="aggr-ads-link__status-text" data-aggr-link-status role="status" aria-live="polite"></p>
		<?php if ( ! $aggr_dest_editing ) : ?>
			<noscript><button class="aggr-button aggr-button--secondary aggr-button--small" type="submit"><?php esc_html_e( 'Save link', 'aggressive-ads' ); ?></button></noscript>
		<?php endif; ?>
		<div class="aggr-ads-link__meta">
			<span class="aggr-ads-link__used">
				<?php
				printf(
					/* translators: 1: sizes whose ad goes to this link. 2: sizes in the package. */
					esc_html( _n( 'Used by %1$d of %2$d ad', 'Used by %1$d of %2$d ads', $aggr_dest_total, 'aggressive-ads' ) ),
					(int) $aggr_dest_used,
					(int) $aggr_dest_total
				);
				?>
			</span>
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
					data-aggr-for="aggr-campaign-link"
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
	</form>
</section>
