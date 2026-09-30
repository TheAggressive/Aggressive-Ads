<?php
/**
 * The frame every Advertising screen is drawn inside.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

namespace Aggressive\Ads\Admin;

/**
 * Prints one page header and one mount point, so every staff screen opens the same way.
 *
 * Seven screens each carried their own copy of the same `printf`: a `.wrap`, a
 * bare `<h1>`, a `<noscript>` notice and the React root. None of them said what
 * the screen was for, and none printed `wp-header-end` — so core's `common.js`,
 * which moves every admin notice to sit after that marker or else after the
 * first heading, had nothing to aim at but the `<h1>` itself. With a sentence
 * under the title, a notice would have landed between the two.
 *
 * Static for the reason `Shared_Assets` is: this renders markup from its
 * arguments and holds nothing, so an instance in the container would be a
 * collaborator every screen has to be handed for no gain.
 *
 * **Presentation only.** Every caller checks its own capability before it gets
 * here, and nothing here reads a request or decides who may see what. A screen
 * that moves its capability check below a call to this class has changed its
 * authorization, not its layout.
 *
 * The Review screen is not a caller yet: its header is rendered by React and
 * changes with the view (queue or campaign). Moving it is the review slice's
 * decision, not this one's. See docs/admin-ui.md.
 */
final class Screen_Shell {

	/**
	 * Opens the screen: the wrap, its header, and the marker notices go under.
	 *
	 * The caller prints the body and then `close()`. Used directly only by a
	 * screen that renders its body in PHP; a React screen calls `mount()`.
	 *
	 * @param string $title   Page title, already translated.
	 * @param string $purpose One sentence saying what the screen is for, already translated. Empty for none.
	 * @return void
	 */
	public static function open( string $title, string $purpose = '' ): void {
		/*
		 * The mark is decorative and hidden from assistive technology: the
		 * heading's accessible name is the title alone. It is drawn by CSS from
		 * the same SVG the sidebar icon is, so there is one copy of the shape.
		 */
		printf(
			'<div class="wrap aggr-admin"><header class="aggr-admin-head"><h1 class="aggr-admin-head__title"><span class="aggr-admin-head__mark" aria-hidden="true"></span>%s</h1>',
			esc_html( $title )
		);

		if ( '' !== $purpose ) {
			printf( '<p class="aggr-admin-head__purpose">%s</p>', esc_html( $purpose ) );
		}

		// Core moves admin notices to sit directly after this element.
		echo '</header><hr class="wp-header-end">';
	}

	/**
	 * Closes what `open()` opened.
	 *
	 * @return void
	 */
	public static function close(): void {
		echo '</div>';
	}

	/**
	 * A complete React screen: header, the no-script notice and the mount point.
	 *
	 * The payload travels as a JSON attribute on the root, which is how every
	 * screen already hydrated; the attribute name is each bundle's own, so it is
	 * passed rather than derived.
	 *
	 * @param string               $title     Page title, already translated.
	 * @param string               $purpose   One sentence saying what the screen is for, already translated.
	 * @param string               $root_id   Element id the bundle mounts on.
	 * @param string               $attribute Data attribute the bundle reads its payload from.
	 * @param array<string, mixed> $payload   Bootstrap data.
	 * @param string               $noscript  What to say when scripting is off, already translated.
	 * @return void
	 */
	public static function mount( string $title, string $purpose, string $root_id, string $attribute, array $payload, string $noscript ): void {
		self::open( $title, $purpose );

		printf(
			'<noscript><div class="notice notice-error"><p>%1$s</p></div></noscript><div id="%2$s" %3$s="%4$s"></div>',
			esc_html( $noscript ),
			esc_attr( $root_id ),
			esc_attr( $attribute ),
			esc_attr( (string) wp_json_encode( $payload ) )
		);

		self::close();
	}

	/**
	 * The screen when its bundle has not been built.
	 *
	 * `dist/` is not committed, so a checkout without `pnpm build` has no screen
	 * at all. Saying so beats an empty wrap the reader has to diagnose from the
	 * console.
	 *
	 * @param string $title   Page title, already translated.
	 * @param string $message What to do about it, already translated.
	 * @return void
	 */
	public static function unbuilt( string $title, string $message ): void {
		self::open( $title );
		printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $message ) );
		self::close();
	}
}
