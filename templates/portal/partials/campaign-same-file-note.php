<?php
/**
 * Says that changing this ad's file leaves its copies on other placements.
 *
 * Each copy is a creative of its own, so a new file here is a new file here
 * only. Without this sentence, an advertiser who used "Same file as …" would
 * reasonably expect the change to follow, and find out otherwise from the
 * live site.
 *
 * Scope is inherited from the dialog.
 *
 * @var array<string, mixed> $aggr_creative The ad being changed.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_same_file = is_array( $aggr_creative['same_file'] ?? null ) ? $aggr_creative['same_file'] : array();

if ( array() === $aggr_same_file ) {
	return;
}
?>
<p class="aggr-hint">
	<?php
	echo esc_html(
		sprintf(
			/* translators: %s: one placement's name, or several separated by commas, e.g. Break, Footer. */
			_n( 'Only this placement changes. %s keeps the current file; change it there too if it should match.', 'Only this placement changes. %s keep the current file; change them there too if they should match.', count( $aggr_same_file ), 'aggressive-ads' ),
			implode( ', ', array_map( static fn ( array $same ): string => (string) $same['placement'], $aggr_same_file ) )
		)
	);
	?>
</p>
