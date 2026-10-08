<?php
/**
 * Script dependencies for index.js, read by register_block_type(). Written by
 * hand since there's no build step.
 */

defined( 'ABSPATH' ) || exit;

return array(
	'dependencies' => array( 'wp-blocks', 'wp-core-data', 'wp-i18n', 'mst-featured-cover' ),
	'version'      => MST_VERSION,
);
