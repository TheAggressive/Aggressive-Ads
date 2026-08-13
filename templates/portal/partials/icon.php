<?php
/**
 * One navigation icon.
 *
 * Literal inline SVG rather than an icon font or a sprite request: the rail is
 * above the fold on every screen, five icons is less markup than one HTTP
 * request, and an icon font renders as a random glyph while it loads.
 *
 * Every icon is aria-hidden. The label beside it is the accessible name; a
 * decorative shape announcing itself twice is worse than not announcing at all.
 *
 * @package Aggressive\Ads
 *
 * @var string $aggr_icon Icon name.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aggr_icon = isset( $aggr_icon ) && is_string( $aggr_icon ) ? $aggr_icon : '';

?>
<svg class="aggr-icon" viewBox="0 0 24 24" width="18" height="18" fill="none"
	stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"
	aria-hidden="true" focusable="false">
	<?php
	switch ( $aggr_icon ) {
		case 'dashboard':
			?>
			<rect x="3" y="3" width="7" height="9" rx="1.5" />
			<rect x="14" y="3" width="7" height="5" rx="1.5" />
			<rect x="14" y="12" width="7" height="9" rx="1.5" />
			<rect x="3" y="16" width="7" height="5" rx="1.5" />
			<?php
			break;

		case 'campaigns':
			?>
			<path d="M4 9v6h3l6 4V5L7 9H4z" />
			<path d="M17.5 8.5a5 5 0 0 1 0 7" />
			<?php
			break;

		case 'organization':
			?>
			<path d="M3 21h18" />
			<path d="M5 21V6a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v15" />
			<path d="M13 10h5a1 1 0 0 1 1 1v10" />
			<path d="M8 9h2M8 13h2M8 17h2M16 14h1M16 18h1" />
			<?php
			break;

		case 'account':
			?>
			<circle cx="12" cy="8" r="3.5" />
			<path d="M4.5 20a7.5 7.5 0 0 1 15 0" />
			<?php
			break;

		case 'help':
			?>
			<circle cx="12" cy="12" r="9" />
			<path d="M9.5 9.5a2.5 2.5 0 1 1 3.2 2.4c-.6.2-.7.6-.7 1.1v.5" />
			<path d="M12 17h.01" />
			<?php
			break;

		case 'close':
			?>
			<path d="M6 6l12 12" />
			<path d="M18 6L6 18" />
			<?php
			break;
	}
	?>
</svg>
