<?php
/**
 * Toolbar site colours: shows each site's colour in the admin toolbar, as a
 * bar beside each site in the My Sites menu and a strip along the bottom of
 * the toolbar on the current site.
 */

defined( 'ABSPATH' ) || exit;

class MST_Toolbar_Colors {

	/** @var array<int, string> Site ID => colour, for the sites in the toolbar. */
	private $colors = array();

	public static function label() {
		return __( 'Toolbar site colours', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Shows each site\'s colour in the admin toolbar: beside every site in the My Sites menu, and as a strip along the bottom of the toolbar on the current site.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	public function register() {
		// After core builds the My Sites menu (admin_bar_menu, 20).
		add_action( 'admin_bar_menu', array( $this, 'collect_colors' ), 999 );
		add_action( 'wp_after_admin_bar_render', array( $this, 'print_styles' ) );
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function collect_colors( $wp_admin_bar ) {
		foreach ( (array) ( $wp_admin_bar->user->blogs ?? array() ) as $site ) {
			$id = (int) $site->userblog_id;
			if ( $wp_admin_bar->get_node( 'blog-' . $id ) ) {
				$this->colors[ $id ] = MST_Sites::color( $id );
			}
		}
	}

	public function print_styles() {
		$css = '';

		foreach ( $this->colors as $id => $color ) {
			$css .= sprintf( '#wpadminbar #wp-admin-bar-blog-%d > .ab-item { box-shadow: inset 4px 0 0 %s; }', $id, $color );
		}

		// Network Admin and user admin aren't a site, so get no strip.
		if ( ! is_network_admin() && ! is_user_admin() ) {
			$css .= sprintf(
				'#wpadminbar::after { content: ""; position: absolute; left: 0; right: 0; bottom: 0; height: 3px; background: %s; pointer-events: none; }',
				MST_Sites::color( get_current_blog_id() )
			);
		}

		if ( '' !== $css ) {
			// Colours come from sanitize_hex_color(), IDs are integers.
			echo '<style id="mst-toolbar-colors">' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
	}
}
