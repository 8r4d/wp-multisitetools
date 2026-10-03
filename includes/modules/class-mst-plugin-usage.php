<?php
/**
 * Plugin usage: adds an "Active On" column to Network Admin › Plugins showing
 * which individual sites each plugin is activated on.
 */

defined( 'ABSPATH' ) || exit;

class MST_Plugin_Usage {

	const COLUMN    = 'mst_active_on';
	const CACHE_KEY = 'mst_plugin_usage';
	const CACHE_TTL = 12 * HOUR_IN_SECONDS;

	public static function label() {
		return __( 'Plugin usage', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds an "Active On" column to Network Admin › Plugins showing which sites each plugin is activated on.', 'multisite-tools' );
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
		// Any change to a site's active plugins, on any site, invalidates the map.
		add_action( 'add_option_active_plugins', array( $this, 'flush' ) );
		add_action( 'update_option_active_plugins', array( $this, 'flush' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'wp_initialize_site', array( $this, 'flush' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'flush' ) );
		add_action( 'wp_update_site', array( $this, 'flush' ) );

		if ( is_network_admin() ) {
			add_filter( 'manage_plugins-network_columns', array( $this, 'add_column' ) );
			add_action( 'manage_plugins_custom_column', array( $this, 'render_column' ), 10, 2 );
			add_action( 'admin_head-plugins.php', array( $this, 'print_styles' ) );
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
	 * @param string $plugin_file
	 */
	public function render_column( $column_name, $plugin_file ) {
		global $status;

		if ( self::COLUMN !== $column_name ) {
			return;
		}

		if ( in_array( $status, array( 'mustuse', 'dropins' ), true ) ) {
			echo '<span class="mst-muted">' . esc_html__( 'Always active', 'multisite-tools' ) . '</span>';
			return;
		}

		if ( is_plugin_active_for_network( $plugin_file ) ) {
			echo '<span class="mst-badge">' . esc_html__( 'Network-wide', 'multisite-tools' ) . '</span>';
			return;
		}

		$usage    = $this->get_usage();
		$site_ids = $usage['plugins'][ $plugin_file ] ?? array();

		if ( ! $site_ids ) {
			echo '<span class="mst-muted">' . esc_html__( 'Not active on any site', 'multisite-tools' ) . '</span>';
			return;
		}

		$label = sprintf(
			/* translators: %s: number of sites */
			_n( '%s site', '%s sites', count( $site_ids ), 'multisite-tools' ),
			number_format_i18n( count( $site_ids ) )
		);

		echo '<details class="mst-sites"><summary>' . esc_html( $label ) . '</summary><ul>';

		foreach ( $site_ids as $site_id ) {
			$site = $usage['sites'][ $site_id ];

			printf(
				'<li>%s<a href="%s" title="%s">%s</a>%s</li>',
				MST_Sites::swatch( $site_id ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
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
			.mst-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #2271b1; color: #fff; font-size: 12px; }
			.mst-muted { color: #8c8f94; }
			.mst-sites summary { cursor: pointer; color: #2271b1; }
			.mst-sites ul { margin: 4px 0 0; }
			.mst-sites li { margin: 0; }
		</style>
		<?php
	}

	/**
	 * Plugin-to-sites map, cached network-wide.
	 *
	 * @return array{plugins: array<string, int[]>, sites: array<int, array>}
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
			'plugins' => array(),
			'sites'   => array(),
		);

		$sites   = MST_Sites::all();
		$options = MST_Sites::get_options( $sites, array( 'active_plugins', 'blogname' ) );

		foreach ( $sites as $site ) {
			$id      = (int) $site->blog_id;
			$plugins = maybe_unserialize( $options[ $id ]['active_plugins'] ?? array() );

			$usage['sites'][ $id ] = array(
				'name'      => (string) ( $options[ $id ]['blogname'] ?? '' ),
				'url'       => MST_Sites::url( $site ),
				'admin_url' => MST_Sites::admin_url( $site, 'plugins.php' ),
				'status'    => MST_Sites::status( $site ),
			);

			foreach ( (array) $plugins as $plugin_file ) {
				$usage['plugins'][ $plugin_file ][] = $id;
			}
		}

		return $usage;
	}
}
