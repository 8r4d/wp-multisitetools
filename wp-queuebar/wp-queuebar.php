<?php
/**
 * Plugin Name: QueueBar
 * Description: Shows the number of scheduled posts in the admin toolbar.
 * Version:     1.0.0
 * Author:      Brad Salomons
 * License:     GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_bar_menu', 'queuebar_add_toolbar_item', 100 );

function queuebar_add_toolbar_item( $wp_admin_bar ) {
	if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
		return;
	}

	$count = (int) wp_count_posts( 'post' )->future;

	$wp_admin_bar->add_node(
		array(
			'id'    => 'queuebar',
			'title' => sprintf(
				'<span class="ab-icon dashicons dashicons-calendar-alt" style="top:2px;"></span><span class="ab-label">%s</span>',
				esc_html( sprintf( __( '%s Scheduled', 'queuebar' ), number_format_i18n( $count ) ) )
			),
			'href'  => admin_url( 'edit.php?post_status=future&post_type=post' ),
			'meta'  => array(
				'title' => __( 'View scheduled posts', 'queuebar' ),
			),
		)
	);
}
