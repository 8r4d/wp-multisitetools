<?php
/**
 * Theme usage: adds an "Active On" column to Network Admin › Themes showing
 * which sites use each theme, directly or as the parent of a child theme.
 */

defined( 'ABSPATH' ) || exit;

class MST_Theme_Usage {

	const COLUMN    = 'mst_theme_active_on';
	const CACHE_KEY = 'mst_theme_usage';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function label() {
		return __( 'Theme usage', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds an "Active On" column to Network Admin › Themes showing which sites use each theme, including as a parent theme.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	/**
	 * Called when the module is switched back on. The map may have gone stale
	 * while the invalidation hooks below weren't registered.
	 */
	public function enable() {
		$this->flush();
	}

	public function register() {
		// Any site switching theme, on any site, invalidates the map.
		add_action( 'update_option_stylesheet', array( $this, 'flush' ) );
		add_action( 'update_option_template', array( $this, 'flush' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'wp_initialize_site', array( $this, 'flush' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'flush' ) );
		add_action( 'wp_update_site', array( $this, 'flush' ) );

		if ( is_network_admin() ) {
			add_filter( 'manage_themes-network_columns', array( $this, 'add_column' ) );
			add_action( 'manage_themes_custom_column', array( $this, 'render_column' ), 10, 2 );
			add_action( 'admin_head-themes.php', array( $this, 'print_styles' ) );
		}
	}

	public function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * @param string[] $columns
	 * @return string[]
	 */
	public function add_column( $columns ) {
		$columns[ self::COLUMN ] = __( 'Active On', 'multisite-tools' );
		return $columns;
	}

	/**
	 * @param string $column_name
	 * @param string $stylesheet Theme directory name.
	 */
	public function render_column( $column_name, $stylesheet ) {
		if ( self::COLUMN !== $column_name ) {
			return;
		}

		$usage   = $this->get_usage();
		$active  = $usage['active'][ $stylesheet ] ?? array();
		$parents = $usage['parent'][ $stylesheet ] ?? array();

		if ( ! $active && ! $parents ) {
			echo '<span class="mst-muted">' . esc_html__( 'Not active on any site', 'multisite-tools' ) . '</span>';
			return;
		}

		if ( $active ) {
			$this->render_sites(
				$usage['sites'],
				$active,
				/* translators: %s: number of sites */
				_n( '%s site', '%s sites', count( $active ), 'multisite-tools' )
			);
		}

		if ( $parents ) {
			$this->render_sites(
				$usage['sites'],
				$parents,
				/* translators: %s: number of sites */
				_n( 'Parent theme on %s site', 'Parent theme on %s sites', count( $parents ), 'multisite-tools' )
			);
		}
	}

	/**
	 * @param array<int, array> $sites
	 * @param int[]             $site_ids
	 * @param string            $label    Label with a %s for the count.
	 */
	private function render_sites( $sites, $site_ids, $label ) {
		$label = sprintf( $label, number_format_i18n( count( $site_ids ) ) );

		echo '<details class="mst-sites"><summary>' . esc_html( $label ) . '</summary><ul>';

		foreach ( $site_ids as $site_id ) {
			$site = $sites[ $site_id ];

			printf(
				'<li><a href="%s" title="%s">%s</a>%s</li>',
				esc_url( $site['admin_url'] ),
				esc_attr( $site['url'] ),
				esc_html( '' !== $site['name'] ? $site['name'] : $site['url'] ),
				$site['status'] ? ' <span class="mst-muted">(' . esc_html( $site['status'] ) . ')</span>' : ''
			);
		}

		echo '</ul></details>';
	}

	public function print_styles() {
		?>
		<style>
			.column-<?php echo esc_attr( self::COLUMN ); ?> { width: 16em; }
			.mst-muted { color: #8c8f94; }
			.mst-sites summary { cursor: pointer; color: #2271b1; }
			.mst-sites ul { margin: 4px 0 0 1em; list-style: disc; }
			.mst-sites li { margin: 0; }
		</style>
		<?php
	}

	/**
	 * Theme-to-sites map, cached network-wide.
	 *
	 * @return array{active: array<string, int[]>, parent: array<string, int[]>, sites: array<int, array>}
	 */
	public function get_usage() {
		$usage = get_site_transient( self::CACHE_KEY );

		if ( ! is_array( $usage ) ) {
			$usage = $this->build_usage();
			set_site_transient( self::CACHE_KEY, $usage, self::CACHE_TTL );
		}

		return $usage;
	}

	private function build_usage() {
		$usage = array(
			'active' => array(),
			'parent' => array(),
			'sites'  => array(),
		);

		$sites   = MST_Sites::all();
		$options = MST_Sites::get_options( $sites, array( 'stylesheet', 'template', 'blogname' ) );

		foreach ( $sites as $site ) {
			$id         = (int) $site->blog_id;
			$stylesheet = (string) ( $options[ $id ]['stylesheet'] ?? '' );
			$template   = (string) ( $options[ $id ]['template'] ?? '' );

			$usage['sites'][ $id ] = array(
				'name'      => (string) ( $options[ $id ]['blogname'] ?? '' ),
				'url'       => MST_Sites::url( $site ),
				'admin_url' => MST_Sites::admin_url( $site, 'themes.php' ),
				'status'    => MST_Sites::status( $site ),
			);

			if ( '' !== $stylesheet ) {
				$usage['active'][ $stylesheet ][] = $id;
			}

			// A child theme also depends on its parent.
			if ( '' !== $template && $template !== $stylesheet ) {
				$usage['parent'][ $template ][] = $id;
			}
		}

		return $usage;
	}
}
