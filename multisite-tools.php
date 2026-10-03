<?php
/**
 * Plugin Name:       Multisite Multitools
 * Description:       A toolkit of network admin utilities for WordPress multisite.
 * Version:           2.1.1
 * Author:			  Brad Salomons
 * Requires at least: 5.1
 * Requires PHP:      7.4
 * Network:           true
 * License:           GPL-2.0-or-later
 * Text Domain:       multisite-tools
 */

defined( 'ABSPATH' ) || exit;

if ( ! is_multisite() ) {
	return;
}

define( 'MST_VERSION', '2.1.1' );
define( 'MST_FILE', __FILE__ );
define( 'MST_DIR', plugin_dir_path( __FILE__ ) );

require_once MST_DIR . 'includes/class-multisite-tools.php';

add_action( 'plugins_loaded', array( 'Multisite_Tools', 'boot' ) );
