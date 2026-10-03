<?php
/**
 * Disable XML-RPC: turns off xmlrpc.php, an old remote-publishing interface
 * that bots use to guess passwords and send pingback spam.
 */

defined( 'ABSPATH' ) || exit;

class MST_Disable_Xmlrpc {

	public static function label() {
		return __( 'Disable XML-RPC', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Turns off xmlrpc.php, which bots use to guess passwords and send pingback spam. Leave this off if you use Jetpack, or a mobile or desktop app that publishes over XML-RPC.', 'multisite-tools' );
	}

	public static function category() {
		return 'security';
	}

	/**
	 * Off until chosen, since it can break Jetpack and some apps.
	 */
	public static function default_enabled() {
		return false;
	}

	public function register() {
		// xmlrpc.php defines this before loading WordPress.
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			status_header( 403 );
			nocache_headers();
			exit( esc_html__( 'XML-RPC is disabled on this site.', 'multisite-tools' ) );
		}

		// In case something calls the server directly.
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );

		// Stop advertising it.
		add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
		remove_action( 'wp_head', 'rsd_link' );
	}

	/**
	 * @param string[] $headers
	 * @return string[]
	 */
	public function remove_pingback_header( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
}
