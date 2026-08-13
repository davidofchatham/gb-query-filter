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
