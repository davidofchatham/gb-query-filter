<?php
/**
 * Plugin Name:       GB Query Filter
 * Plugin URI:        https://github.com/davidofchatham/gb-query-filter
 * Description:       Adds filter UI and logic for GenerateBlocks Query Loop.
 * Version:           0.4.0
 * Author:            Clayton Chase, David Mitchell
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       gb-query-filter
 * Domain Path:       /languages
 *
 * @package GBQF
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'GBQF_VERSION', '0.4.0' );
define( 'GBQF_PLUGIN_FILE', __FILE__ );
define( 'GBQF_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GBQF_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Core plugin loader.
require_once GBQF_PLUGIN_DIR . 'includes/class-gbqf-plugin.php';

/*
 * Updates from GitHub Releases (Plugin Update Checker 5.7, vendored verbatim in
 * libs/). REQUIRE_RELEASE_ASSETS: a release without the zip built by
 * .github/workflows/release.yml offers no update, instead of falling back to
 * the source zipball with its dev files. Slug must equal the plugin folder.
 */
require_once GBQF_PLUGIN_DIR . 'libs/plugin-update-checker/load-v5p7.php';

\YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/davidofchatham/gb-query-filter/',
	__FILE__,
	'gb-query-filter'
)->getVcsApi()->enableReleaseAssets(
	'/gb-query-filter-[\d.]+\.zip/',
	\YahnisElsts\PluginUpdateChecker\v5p7\Vcs\Api::REQUIRE_RELEASE_ASSETS
);

/**
 * Helper to access the main plugin instance.
 *
 * @return \GBQF\Plugin
 */
function gbqf() {
	return \GBQF\Plugin::instance();
}

// Boot the plugin.
gbqf();
