<?php
/**
 * Hide usernames: closes the common ways visitors can discover login names,
 * which are half of what's needed to brute-force a login.
 *
 * Logged-in users are unaffected, so the editor's author picker still works.
 */

defined( 'ABSPATH' ) || exit;

class MST_Hide_Usernames {

	public static function label() {
		return __( 'Hide usernames', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Stops visitors discovering usernames through ?author= links, the REST API user list, the author sitemap, CSS classes and login error messages.', 'multisite-tools' );
	}

	public static function category() {
		return 'security';
	}

	public function register() {
		// ?author=1 redirects to /author/<username>/.
		add_action( 'template_redirect', array( $this, 'block_author_query' ), 1 );

		// /wp-json/wp/v2/users lists every author's slug.
		add_filter( 'rest_pre_dispatch', array( $this, 'block_rest_users' ), 10, 3 );

		// /wp-sitemap-users-1.xml lists every author archive URL.
		add_filter( 'wp_sitemaps_add_provider', array( $this, 'remove_users_sitemap' ), 10, 2 );

		// author-<username> and comment-author-<username> classes.
		add_filter( 'body_class', array( $this, 'filter_classes' ) );
		add_filter( 'comment_class', array( $this, 'filter_classes' ) );

		// "The password you entered for the username X is incorrect."
		add_filter( 'login_errors', array( $this, 'generic_login_error' ) );
	}

	public function block_author_query() {
		if ( ! isset( $_GET['author'] ) || is_user_logged_in() ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();
	}

	/**
	 * @param mixed           $result
	 * @param WP_REST_Server  $server
	 * @param WP_REST_Request $request
	 * @return mixed
	 */
	public function block_rest_users( $result, $server, $request ) {
		if ( null !== $result || is_user_logged_in() ) {
			return $result;
		}

		if ( 0 === strpos( $request->get_route(), '/wp/v2/users' ) ) {
			return new WP_Error(
				'rest_user_cannot_view',
				__( 'Sorry, you are not allowed to list users.', 'multisite-tools' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return $result;
	}

	/**
	 * @param WP_Sitemaps_Provider $provider
	 * @param string               $name
	 * @return WP_Sitemaps_Provider|false
	 */
	public function remove_users_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * @param string[] $classes
	 * @return string[]
	 */
	public function filter_classes( $classes ) {
		return array_values(
			array_filter(
				$classes,
				function ( $class ) {
					// Keep the ID-based author-123 class; drop the slug-based ones.
					return ! preg_match( '/^(comment-)?author-(?!\d+$)/', $class );
				}
			)
		);
	}

	/**
	 * @param string $error
	 * @return string
	 */
	public function generic_login_error( $error ) {
		global $errors;

		// Leave other messages (e.g. "check your email") alone.
		$codes = is_wp_error( $errors ) ? $errors->get_error_codes() : array();
		if ( ! array_intersect( $codes, array( 'invalid_username', 'invalid_email', 'incorrect_password' ) ) ) {
			return $error;
		}

		return __( '<strong>Error:</strong> The username, email address or password is incorrect.', 'multisite-tools' );
	}
}
