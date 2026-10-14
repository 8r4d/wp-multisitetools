<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'mst_plugin_usage' );
delete_site_transient( 'mst_queuebar_counts' );
delete_site_transient( 'mst_theme_usage' );
delete_site_transient( 'mst_missed_schedule_scan' );
delete_site_transient( 'mst_network_digest' );
delete_site_option( 'mst_related_posts_generation' );
delete_site_option( 'mst_modules' );
delete_site_option( 'mst_site_colors' );
delete_site_option( 'mst_post_types_since' );
delete_site_option( 'mst_user_audit_since' );
delete_site_option( 'network_digest_source_site_ids' );
delete_site_option( 'network_digest_posts_per_source' );

// User audit's recorded logins (user meta is network-wide).
delete_metadata( 'user', 0, 'mst_last_login', '', true );

// Per-site settings: Default author, Social graph's default image, Post type
// inventory's snapshot, Related posts' settings and cached lists, and Copy to
// site's links between copies and originals.
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mst_site_id ) {
	delete_blog_option( $mst_site_id, 'dpa_default_author' );
	delete_blog_option( $mst_site_id, 'mst_social_image' );
	delete_blog_option( $mst_site_id, 'mst_post_types' );
	delete_blog_option( $mst_site_id, 'mst_related_posts' );

	switch_to_blog( $mst_site_id );
	delete_metadata( 'post', 0, '_mst_related_posts', '', true );
	delete_metadata( 'post', 0, '_mst_copied_from', '', true );
	delete_metadata( 'post', 0, '_mst_copies', '', true );
	restore_current_blog();
}
