<?php
/**
 * Compatibility loader for sites whose active_plugins still names this file.
 *
 * WordPress identifies plugins by the basename in active_plugins, not by
 * Plugin Name. A second Plugin Name header would list two plugins. This file
 * therefore has none, and loads aggressive-ads.php when that file has not
 * already defined the runtime constants.
 *
 * @package Aggressive\Ads
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'AGGR_VERSION' ) ) {
	require_once __DIR__ . '/aggressive-ads.php';
}
