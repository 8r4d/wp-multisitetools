<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'mst_plugin_usage' );
delete_site_transient( 'mst_queuebar_counts' );
delete_site_option( 'mst_modules' );

// Default author is a per-site setting.
foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $mst_site_id ) {
	delete_blog_option( $mst_site_id, 'dpa_default_author' );
}
