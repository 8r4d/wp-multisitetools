<?php
/**
 * Hide toolbar comments: removes the comments bubble, with its count of
 * comments awaiting moderation, from the admin toolbar on every site.
 */

defined( 'ABSPATH' ) || exit;

class MST_Hide_Toolbar_Comments {

	public static function label() {
		return __( 'Hide toolbar comments', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Removes the comments bubble and its moderation count from the admin toolbar on every site. Comments are still under Comments in each site\'s admin menu.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	/**
	 * Off until chosen, since it changes the toolbar for every user.
	 */
	public static function default_enabled() {
		return false;
	}

	public function register() {
		// After core adds it (admin_bar_menu, 60).
		add_action( 'admin_bar_menu', array( $this, 'remove_comments' ), 999 );
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function remove_comments( $wp_admin_bar ) {
		$wp_admin_bar->remove_node( 'comments' );
	}
}
