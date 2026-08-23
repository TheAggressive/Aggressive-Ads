/**
 * DataViews, compiled once and shared by every admin screen that uses it.
 *
 * WordPress 7.1 uses DataViews internally but registers no `wp-dataviews`
 * script or style handle, so a screen cannot depend on core for it. The first
 * conversion solved that by compiling the package into the screen's own bundle,
 * which is correct for one screen and wrong for the second: nothing is shared
 * between admin entries — `splitChunks` is off, and each `.asset.php` is a
 * separate `wp_enqueue_script` — so a second consumer would ship a second
 * 490 KB copy, and a fifth a fifth.
 *
 * So it is compiled here instead, exposed on `window.aggrDataViews`, and
 * registered as `aggr-dataviews`. Screens keep importing `@wordpress/dataviews`
 * exactly as before; `webpack.admin.config.mjs` rewrites that import to this
 * global and names this handle as their dependency, so the sharing is a build
 * concern and no screen has to know about it.
 *
 * The stylesheet lives here too, for the same reason and one more:
 * `@wordpress/dataviews` declares `"sideEffects": false`, which is true of its
 * modules and false of its 102 KB of CSS. Webpack believes the package and
 * tree-shakes the import away unless the config says otherwise — no CSS, no
 * warning, and a table that renders as unstyled markup.
 */

import '@wordpress/dataviews/build-style/style.css';

export * from '@wordpress/dataviews';
