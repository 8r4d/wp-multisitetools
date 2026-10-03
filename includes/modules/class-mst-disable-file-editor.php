<?php
/**
 * Disable file editor: removes the built-in theme and plugin file editors, the
 * same as DISALLOW_FILE_EDIT, so a stolen admin login can't be used to run
 * code on the server.
 */

defined( 'ABSPATH' ) || exit;

class MST_Disable_File_Editor {

	const CAPS = array( 'edit_themes', 'edit_plugins', 'edit_files' );

	public static function label() {
		return __( 'Disable file editor', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Removes the theme and plugin file editors from every site and Network Admin, so a stolen admin login can\'t be used to change code on the server.', 'multisite-tools' );
	}

	public static function category() {
		return 'security';
	}

	public static function default_enabled() {
		return false;
	}

	public function register() {
		add_filter( 'map_meta_cap', array( $this, 'deny_file_edit' ), 10, 2 );
	}

	/**
	 * @param string[] $caps
	 * @param string   $cap
	 * @return string[]
	 */
	public function deny_file_edit( $caps, $cap ) {
		return in_array( $cap, self::CAPS, true ) ? array( 'do_not_allow' ) : $caps;
	}
}
