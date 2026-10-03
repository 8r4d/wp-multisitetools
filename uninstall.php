<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'mst_plugin_usage' );
delete_site_transient( 'mst_queuebar_counts' );
delete_site_transient( 'mst_theme_usage' );
delete_site_transient( 'mst_missed_schedule_scan' );
delete_site_option( 'mst_modules' );
delete_site_option( 'mst_site_colors' );

// Per-site settings: Default author and Social graph's default image.
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mst_site_id ) {
	delete_blog_option( $mst_site_id, 'dpa_default_author' );
	delete_blog_option( $mst_site_id, 'mst_social_image' );
}
