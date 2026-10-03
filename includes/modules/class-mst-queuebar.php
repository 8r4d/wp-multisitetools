<?php
/**
 * QueueBar: shows the number of scheduled posts on the current site in the
 * admin toolbar, and a per-site breakdown of published, scheduled and draft
 * posts on the Network Admin dashboard.
 */

defined( 'ABSPATH' ) || exit;

class MST_Queuebar {

	/**
	 * Same node ID as the standalone QueueBar plugin, so running both shows a
	 * single item rather than two.
	 */
	const NODE = 'queuebar';

	const WIDGET    = 'mst_queuebar_sites';
	const CACHE_KEY = 'mst_queuebar_counts';
	const CACHE_TTL = HOUR_IN_SECONDS;

	/**
	 * Sites per UNION query when reading counts straight from the database.
	 */
	const CHUNK_SIZE = 100;

	/**
	 * Post statuses counted, in display order.
	 */
	const STATUSES = array( 'publish', 'future', 'draft' );

	public static function label() {
		return __( 'QueueBar', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Shows the number of scheduled posts on the current site in the admin toolbar, and a per-site count of published, scheduled and draft posts on the Network Admin dashboard.', 'multisite-tools' );
	}

	/**
	 * Called when the module is switched back on. The counts may have gone
	 * stale while the invalidation hooks below weren't registered.
	 */
	public function enable() {
		$this->flush();
	}

	public function register() {
		add_action( 'admin_bar_menu', array( $this, 'add_toolbar_item' ), 100 );

		// Any post changing status, on any site, invalidates the counts.
		add_action( 'transition_post_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'wp_initialize_site', array( $this, 'flush' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'flush' ) );
		add_action( 'wp_update_site', array( $this, 'flush' ) );

		if ( is_network_admin() ) {
			add_action( 'wp_network_dashboard_setup', array( $this, 'add_dashboard_widget' ) );
		}
	}

	public function flush() {
		delete_site_transient( self::CACHE_KEY );
	}

	/**
	 * @param string  $new_status
	 * @param string  $old_status
	 * @param WP_Post $post
	 */
	public function flush_on_transition( $new_status, $old_status, $post ) {
		if ( 'post' === $post->post_type && $new_status !== $old_status ) {
			$this->flush();
		}
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

	public function add_dashboard_widget() {
		if ( ! current_user_can( 'manage_sites' ) ) {
			return;
		}

		wp_add_dashboard_widget(
			self::WIDGET,
			__( 'Posts by Site', 'multisite-tools' ),
			array( $this, 'render_dashboard_widget' )
		);
	}

	public function render_dashboard_widget() {
		$sites = $this->get_counts();

		if ( ! $sites ) {
			echo '<p>' . esc_html__( 'No sites found.', 'multisite-tools' ) . '</p>';
			return;
		}

		$headings = array(
			'publish' => __( 'Published', 'multisite-tools' ),
			'future'  => __( 'Scheduled', 'multisite-tools' ),
			'draft'   => __( 'Drafts', 'multisite-tools' ),
		);
		$totals   = array_fill_keys( self::STATUSES, 0 );
		?>
		<style>
			#<?php echo esc_attr( self::WIDGET ); ?> .inside { margin: 0; padding: 0; }
			.mst-qb-scroll { max-height: 400px; overflow-y: auto; }
			.mst-qb-table { border: 0; }
			.mst-qb-table .num { text-align: right; width: 6em; }
			.mst-qb-table thead th, .mst-qb-table tfoot th { position: sticky; background: #fff; }
			.mst-qb-table thead th { top: 0; }
			.mst-qb-table tfoot th { bottom: 0; font-weight: 600; border-top: 1px solid #c3c4c7; }
			.mst-qb-zero { color: #8c8f94; }
		</style>
		<div class="mst-qb-scroll">
			<table class="widefat striped mst-qb-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></th>
						<?php foreach ( $headings as $label ) : ?>
							<th scope="col" class="num"><?php echo esc_html( $label ); ?></th>
						<?php endforeach; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sites as $site ) : ?>
						<tr>
							<td>
								<a href="<?php echo esc_url( $site['admin_url'] . 'edit.php' ); ?>" title="<?php echo esc_attr( $site['url'] ); ?>">
									<?php echo esc_html( '' !== $site['name'] ? $site['name'] : $site['url'] ); ?>
								</a>
							</td>
							<?php
							foreach ( self::STATUSES as $status ) :
								$count              = $site['counts'][ $status ];
								$totals[ $status ] += $count;
								?>
								<td class="num">
									<?php if ( $count ) : ?>
										<a href="<?php echo esc_url( $site['admin_url'] . 'edit.php?post_type=post&post_status=' . $status ); ?>"><?php echo esc_html( number_format_i18n( $count ) ); ?></a>
									<?php else : ?>
										<span class="mst-qb-zero">0</span>
									<?php endif; ?>
								</td>
							<?php endforeach; ?>
						</tr>
					<?php endforeach; ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total', 'multisite-tools' ); ?></th>
						<?php foreach ( $totals as $total ) : ?>
							<th class="num"><?php echo esc_html( number_format_i18n( $total ) ); ?></th>
						<?php endforeach; ?>
					</tr>
				</tfoot>
			</table>
		</div>
		<?php
	}

	/**
	 * Per-site post counts, cached network-wide.
	 *
	 * @return array<int, array{name: string, url: string, admin_url: string, counts: array<string, int>}>
	 */
	public function get_counts() {
		$sites = get_site_transient( self::CACHE_KEY );

		if ( ! is_array( $sites ) ) {
			$sites = $this->build_counts();
			set_site_transient( self::CACHE_KEY, $sites, self::CACHE_TTL );
		}

		return $sites;
	}

	private function build_counts() {
		$result = array();

		$sites = get_sites(
			array(
				'network_id' => get_current_network_id(),
				'number'     => 0,
				'deleted'    => 0,
				'archived'   => 0,
				'spam'       => 0,
			)
		);

		foreach ( array_chunk( $sites, self::CHUNK_SIZE ) as $chunk ) {
			$data = $this->read_counts( $chunk );

			foreach ( $chunk as $site ) {
				$id = (int) $site->blog_id;

				$result[ $id ] = array(
					'name'      => (string) ( $data[ $id ]['blogname'] ?? '' ),
					'url'       => untrailingslashit( $site->domain . $site->path ),
					'admin_url' => set_url_scheme( 'http://' . $site->domain . $site->path . 'wp-admin/', 'admin' ),
					'counts'    => array(),
				);

				foreach ( self::STATUSES as $status ) {
					$result[ $id ]['counts'][ $status ] = (int) ( $data[ $id ][ $status ] ?? 0 );
				}
			}
		}

		return $result;
	}

	/**
	 * Reads post counts by status and the blogname for a batch of sites in a
	 * single query, avoiding a switch_to_blog() per site. Each site contributes
	 * one row per status plus a 'blogname' row, all as (blog_id, k, v). Falls
	 * back to switch_to_blog() if the query fails, e.g. a site's tables are
	 * missing or live on another database server (HyperDB, LudicrousDB).
	 *
	 * @param WP_Site[] $sites
	 * @return array<int, array<string, string>> Site ID => status or 'blogname' => value.
	 */
	private function read_counts( $sites ) {
		global $wpdb;

		$statuses = "'" . implode( "', '", self::STATUSES ) . "'";
		$selects  = array();

		foreach ( $sites as $site ) {
			$prefix    = $wpdb->get_blog_prefix( $site->blog_id );
			$selects[] = $wpdb->prepare(
				"SELECT %d AS blog_id, post_status AS k, COUNT(*) AS v FROM `{$prefix}posts` WHERE post_type = 'post' AND post_status IN ({$statuses}) GROUP BY post_status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site->blog_id
			);
			$selects[] = $wpdb->prepare(
				"SELECT %d AS blog_id, option_name AS k, option_value AS v FROM `{$prefix}options` WHERE option_name = 'blogname'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$site->blog_id
			);
		}

		$suppress = $wpdb->suppress_errors();
		$rows     = $wpdb->get_results( implode( ' UNION ALL ', $selects ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$failed   = '' !== $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );

		$data = array();

		if ( ! $failed ) {
			foreach ( $rows as $row ) {
				$data[ (int) $row->blog_id ][ $row->k ] = $row->v;
			}
			return $data;
		}

		foreach ( $sites as $site ) {
			$id = (int) $site->blog_id;

			switch_to_blog( $id );

			$counts      = wp_count_posts( 'post' );
			$data[ $id ] = array( 'blogname' => get_option( 'blogname', '' ) );
			foreach ( self::STATUSES as $status ) {
				$data[ $id ][ $status ] = $counts->$status ?? 0;
			}

			restore_current_blog();
		}

		return $data;
	}
}
