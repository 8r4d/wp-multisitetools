<?php
defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_site_transient( 'mst_plugin_usage' );
delete_site_transient( 'mst_queuebar_counts' );
delete_site_option( 'mst_modules' );
