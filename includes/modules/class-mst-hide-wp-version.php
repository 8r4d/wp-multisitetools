<?php
/**
 * Hide WordPress version: removes the version number from page source and
 * feeds, so bots can't easily match a site against known vulnerabilities.
 */

defined( 'ABSPATH' ) || exit;

class MST_Hide_Wp_Version {

	public static function label() {
		return __( 'Hide WordPress version', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Removes the WordPress version from the generator tag, feeds, and the ?ver= on core scripts and styles on the front end.', 'multisite-tools' );
	}

	public static function category() {
		return 'security';
	}

	public static function default_enabled() {
		return false;
	}

	public function register() {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		if ( ! is_admin() ) {
			add_filter( 'script_loader_src', array( $this, 'mask_version' ) );
			add_filter( 'style_loader_src', array( $this, 'mask_version' ) );
		}
	}

	/**
	 * Swaps ?ver=<WordPress version> for a hash of it, so browsers still fetch
	 * fresh files after an update.
	 *
	 * @param string $src
	 * @return string
	 */
	public function mask_version( $src ) {
		$version = get_bloginfo( 'version' );

		if ( ! $src || ! preg_match( '/[?&]ver=' . preg_quote( $version, '/' ) . '(&|$)/', $src ) ) {
			return $src;
		}

		return add_query_arg( 'ver', substr( wp_hash( $version ), 0, 8 ), $src );
	}
}
