<?php
/**
 * What the review team decided about one ad, and when.
 *
 * **Durable, and not read from the ad's current state.** The reason a creative
 * was turned down used to live on the revision and be shown only while that
 * revision still counted as rejected, so a campaign that moved on took the
 * explanation with it — and the same artwork came back. These rows are the
 * decisions themselves, kept whatever happens to the campaign afterwards.
 *
 * Who decided is not shown. The advertiser is owed the decision and the
 * reason; the reviewer's name is staff information, and the audit log is
 * where an administrator looks for it.
 *
 * Scope is inherited from the card.
 *
 * @var array<string, mixed> $aggr_creative The ad, with its decisions attached.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aggressive\Ads\Repository\Creative_Decision_Repository;

$aggr_history = is_array( $aggr_creative['decisions'] ?? null ) ? $aggr_creative['decisions'] : array();

if ( array() === $aggr_history ) {
	return;
}

// Newest first: what happened last is what the advertiser is here about.
$aggr_history = array_reverse( $aggr_history );
?>
<div class="aggr-ad-history">
	<p class="aggr-ad-history__title"><?php esc_html_e( 'Review history', 'aggressive-ads' ); ?></p>
	<ul class="aggr-ad-history__list">
		<?php foreach ( $aggr_history as $aggr_decision ) : ?>
			<?php
			$aggr_decision_kind = (string) $aggr_decision['decision'];
			$aggr_decision_word = match ( $aggr_decision_kind ) {
				Creative_Decision_Repository::APPROVED => __( 'Approved', 'aggressive-ads' ),
				Creative_Decision_Repository::REJECTED => __( 'Not approved', 'aggressive-ads' ),
				Creative_Decision_Repository::CHANGES_APPROVED => __( 'Update approved', 'aggressive-ads' ),
				Creative_Decision_Repository::CHANGES_REJECTED => __( 'Update not approved', 'aggressive-ads' ),
				default => __( 'Reviewed', 'aggressive-ads' ),
			};
			$aggr_decision_tone = in_array( $aggr_decision_kind, array( Creative_Decision_Repository::REJECTED, Creative_Decision_Repository::CHANGES_REJECTED ), true )
				? 'aggr-pill--danger'
				: 'aggr-pill--live';
	?>
			<li class="aggr-ad-history__item">
				<span class="aggr-pill <?php echo esc_attr( $aggr_decision_tone ); ?>"><?php echo esc_html( $aggr_decision_word ); ?></span>
				<?php
				/*
				 * The site's own zone and format, like every other date here:
				 * a stamp built in the browser is the visitor's zone rather
				 * than the site's, which is the one the review team works in.
				 */
				?>
				<time datetime="<?php echo esc_attr( gmdate( 'c', (int) $aggr_decision['at'] ) ); ?>">
					<?php
					// `wp_date()` answers false for a timestamp it cannot
					// format; an empty line is better than "false" on a card.
					echo esc_html( (string) wp_date( (string) get_option( 'date_format' ) . ' · ' . (string) get_option( 'time_format' ), (int) $aggr_decision['at'] ) );
					?>
				</time>
				<?php if ( '' !== (string) $aggr_decision['reason'] ) : ?>
					<p class="aggr-ad-history__reason"><?php echo esc_html( (string) $aggr_decision['reason'] ); ?></p>
				<?php endif; ?>
			</li>
		<?php endforeach; ?>
	</ul>
</div>
