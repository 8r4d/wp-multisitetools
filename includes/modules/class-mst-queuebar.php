<?php
/**
 * QueueBar: shows the number of scheduled posts on the current site in the
 * admin toolbar.
 */

defined( 'ABSPATH' ) || exit;

class MST_Queuebar {

	/**
	 * Same node ID as the standalone QueueBar plugin, so running both shows a
	 * single item rather than two.
	 */
	const NODE = 'queuebar';

	public static function label() {
		return __( 'QueueBar', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Shows the number of scheduled posts on the current site in the admin toolbar.', 'multisite-tools' );
	}

	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'add_toolbar_item' ), 100 );
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_toolbar_item( $wp_admin_bar ) {
		// Network and user admin don't belong to a single site's post queue.
		if ( is_network_admin() || is_user_admin() ) {
			return;
		}

		if ( ! is_user_logged_in() || ! current_user_can( 'edit_posts' ) ) {
			return;
		}

		$count = (int) wp_count_posts( 'post' )->future;

		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE,
				'title' => sprintf(
					'<span class="ab-icon dashicons dashicons-calendar-alt" style="top:2px;"></span><span class="ab-label">%s</span>',
					/* translators: %s: number of scheduled posts */
					esc_html( sprintf( __( '%s Scheduled', 'multisite-tools' ), number_format_i18n( $count ) ) )
				),
				'href'  => admin_url( 'edit.php?post_status=future&post_type=post' ),
				'meta'  => array(
					'title' => __( 'View scheduled posts', 'multisite-tools' ),
				),
			)
		);
	}
}
