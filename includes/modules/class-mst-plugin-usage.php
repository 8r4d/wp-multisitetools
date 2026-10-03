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

	/**
	 * Sites per UNION query when reading options straight from the database.
	 */
	const CHUNK_SIZE = 100;

	public static function label() {
		return __( 'Plugin usage', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds an "Active On" column to Network Admin › Plugins showing which sites each plugin is activated on.', 'multisite-tools' );
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
			.mst-badge { display: inline-block; padding: 1px 8px; border-radius: 10px; background: #2271b1; color: #fff; font-size: 12px; }
			.mst-muted { color: #8c8f94; }
			.mst-sites summary { cursor: pointer; color: #2271b1; }
			.mst-sites ul { margin: 4px 0 0 1em; list-style: disc; }
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

		$sites = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
			)
		);

		foreach ( array_chunk( $sites, self::CHUNK_SIZE ) as $chunk ) {
			$options = $this->read_options( $chunk );

			foreach ( $chunk as $site ) {
				$id      = (int) $site->blog_id;
				$plugins = maybe_unserialize( $options[ $id ]['active_plugins'] ?? '' );

				$usage['sites'][ $id ] = array(
					'name'      => (string) ( $options[ $id ]['blogname'] ?? '' ),
					'url'       => untrailingslashit( $site->domain . $site->path ),
					'admin_url' => set_url_scheme( 'http://' . $site->domain . $site->path . 'wp-admin/plugins.php', 'admin' ),
					'status'    => $this->site_status( $site ),
				);

				foreach ( (array) $plugins as $plugin_file ) {
					$usage['plugins'][ $plugin_file ][] = $id;
				}
			}
		}

		return $usage;
	}

	/**
	 * Reads active_plugins and blogname for a batch of sites in a single query,
	 * avoiding a switch_to_blog() per site. Falls back to get_blog_option() if
	 * the query fails, e.g. a site's tables are missing or live on another
	 * database server (HyperDB, LudicrousDB).
	 *
	 * @param WP_Site[] $sites
	 * @return array<int, array<string, string>> Site ID => option name => raw value.
	 */
	private function read_options( $sites ) {
		global $wpdb;

		$selects = array();
		foreach ( $sites as $site ) {
			$table     = $wpdb->get_blog_prefix( $site->blog_id ) . 'options';
			$selects[] = $wpdb->prepare(
				"SELECT %d AS blog_id, option_name, option_value FROM `{$table}` WHERE option_name IN ('active_plugins', 'blogname')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site->blog_id
			);
		}

		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( implode( ' UNION ALL ', $selects ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$failed   = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		$options = array();

		if ( ! $failed ) {
			foreach ( $rows as $row ) {
				$options[ (int) $row->blog_id ][ $row->option_name ] = $row->option_value;
			}
			return $options;
		}

		foreach ( $sites as $site ) {
			$id             = (int) $site->blog_id;
			$options[ $id ] = array(
				'active_plugins' => get_blog_option( $id, 'active_plugins', array() ),
				'blogname'       => get_blog_option( $id, 'blogname', '' ),
			);
		}

		return $options;
	}

	/**
	 * @param WP_Site $site
	 * @return string Comma-separated status labels, or '' for a normal public site.
	 */
	private function site_status( $site ) {
		$labels = array();

		if ( (int) $site->archived ) {
			$labels[] = __( 'archived', 'multisite-tools' );
		}
		if ( (int) $site->spam ) {
			$labels[] = __( 'spam', 'multisite-tools' );
		}
		if ( (int) $site->deleted ) {
			$labels[] = __( 'deactivated', 'multisite-tools' );
		}

		return implode( ', ', $labels );
	}
}
