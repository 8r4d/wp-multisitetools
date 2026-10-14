<?php
/**
 * Missed schedule: publishes scheduled posts that WordPress failed to publish
 * on time, on every site in the network.
 *
 * WP-Cron only runs on a site when that site gets a visit, so a quiet site can
 * sit on an overdue post for hours. This works in two halves:
 *
 * 1. Scan: a visit to any site (at most every INTERVAL) checks every site for
 *    overdue posts in one query, and pings each late site's wp-cron.php.
 * 2. Publish: whenever a site's cron runs, whether pinged or natural, it
 *    publishes its own overdue posts. This also catches posts whose scheduled
 *    event was lost, which cron alone would never publish. Publishing on the
 *    site itself means that site's own plugins see the post go live.
 *
 * Each site logs the posts it publishes this way in its own option, so
 * concurrent cron runs on different sites can't overwrite each other, and a
 * Network Admin dashboard widget shows the log for every site.
 */

defined( 'ABSPATH' ) || exit;

class MST_Missed_Schedule {

	const LOCK_KEY = 'mst_missed_schedule_scan';
	const INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * How late a post must be before its site is pinged, giving the site's
	 * own cron a chance to publish it first.
	 */
	const GRACE = MINUTE_IN_SECONDS;

	/**
	 * Most posts published per cron run on one site.
	 */
	const BATCH = 20;

	/**
	 * Site option holding that site's log, newest first: a list of
	 * array{time: int, post: int, title: string, late: int, lost: bool}.
	 */
	const LOG_OPTION = 'mst_missed_schedule_log';

	/**
	 * Most entries kept per site, and the longest they're kept.
	 */
	const LOG_SIZE = 20;
	const LOG_AGE  = 90 * DAY_IN_SECONDS;

	/**
	 * How late a post must be to be logged. This runs before core's own
	 * events in every cron run, so it publishes on-time posts on busy sites
	 * too, a few seconds late; those aren't worth logging.
	 */
	const LOG_AFTER = 5 * MINUTE_IN_SECONDS;

	/**
	 * The widget counts fixes per site over this period.
	 */
	const WIDGET_PERIOD = 30 * DAY_IN_SECONDS;

	/**
	 * Most sites and most recent fixes shown in the widget.
	 */
	const WIDGET_SITES = 5;
	const WIDGET_POSTS = 10;

	public static function label() {
		return __( 'Missed schedule fixer', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Publishes scheduled posts that were missed because their site had no visitors at the scheduled time. Any visit to any site checks the whole network, at most every 5 minutes. A Network Admin dashboard widget logs what was published and which sites miss most often.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		add_action( 'wp_loaded', array( $this, 'publish_overdue' ) );

		// After core's own cron spawn (wp_loaded, 20).
		add_action( 'wp_loaded', array( $this, 'maybe_scan' ), 21 );

		add_action( 'wp_network_dashboard_setup', array( $this, 'add_widget' ) );
	}

	/**
	 * During this site's cron run, publishes its overdue posts.
	 */
	public function publish_overdue() {
		global $wpdb;

		if ( ! wp_doing_cron() ) {
			return;
		}

		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_status = 'future' AND post_date_gmt <= %s ORDER BY post_date_gmt LIMIT %d",
				gmdate( 'Y-m-d H:i:s' ),
				self::BATCH
			)
		);

		$log = array();
		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );
			if ( ! $post ) {
				continue;
			}

			// Without its event, cron alone would never have published it.
			$lost = ! wp_next_scheduled( 'publish_future_post', array( $post->ID ) );

			// Re-checks the date and runs the normal publish hooks.
			check_and_publish_future_post( $post->ID );

			$late = max( 0, time() - (int) strtotime( $post->post_date_gmt . ' UTC' ) );

			if ( ( $lost || $late >= self::LOG_AFTER ) && 'publish' === get_post_status( $post->ID ) ) {
				$log[] = array(
					'time'  => time(),
					'post'  => $post->ID,
					'title' => $post->post_title,
					'late'  => $late,
					'lost'  => $lost,
				);
			}
		}

		if ( $log ) {
			$this->add_to_log( array_reverse( $log ) );
		}
	}

	/**
	 * Adds entries to the current site's log, dropping the oldest.
	 *
	 * @param array[] $entries Newest first.
	 */
	private function add_to_log( $entries ) {
		$cutoff = time() - self::LOG_AGE;
		$log    = array_filter(
			array_merge( $entries, (array) get_option( self::LOG_OPTION, array() ) ),
			function ( $entry ) use ( $cutoff ) {
				return (int) ( $entry['time'] ?? 0 ) >= $cutoff;
			}
		);

		update_option( self::LOG_OPTION, array_slice( array_values( $log ), 0, self::LOG_SIZE ), false );
	}

	public function add_widget() {
		wp_add_dashboard_widget( 'mst_missed_schedule', __( 'Missed schedules', 'multisite-tools' ), array( $this, 'render_widget' ) );
	}

	/**
	 * The sites that missed most often lately, then the latest fixes.
	 */
	public function render_widget() {
		$sites   = MST_Sites::details( MST_Sites::active(), array( self::LOG_OPTION ) );
		$cutoff  = time() - self::WIDGET_PERIOD;
		$entries = array();
		$counts  = array();

		foreach ( $sites as $id => $site ) {
			$log = maybe_unserialize( $site['options'][ self::LOG_OPTION ] ?? '' );
			foreach ( is_array( $log ) ? $log : array() as $entry ) {
				$entry['site'] = $id;
				$entries[]     = $entry;
				if ( (int) $entry['time'] >= $cutoff ) {
					$counts[ $id ] = ( $counts[ $id ] ?? 0 ) + 1;
				}
			}
		}

		if ( ! $entries ) {
			echo '<p>' . esc_html__( 'No missed scheduled posts have needed publishing. Every post went out on time.', 'multisite-tools' ) . '</p>';
			return;
		}

		usort(
			$entries,
			function ( $a, $b ) {
				return (int) $b['time'] <=> (int) $a['time'];
			}
		);
		arsort( $counts );
		?>
		<style>
			#mst_missed_schedule ul { margin: 0 0 12px; }
			#mst_missed_schedule li { margin: 0 0 6px; }
			#mst_missed_schedule .mst-muted { color: #8c8f94; }
			#mst_missed_schedule .mst-flag { display: inline-block; margin-left: 4px; padding: 0 6px; border-radius: 10px; font-size: 11px; line-height: 1.6; background: #fcf0f1; color: #b32d2e; }
		</style>

		<?php if ( $counts ) : ?>
			<h3><?php esc_html_e( 'Most missed in the last 30 days', 'multisite-tools' ); ?></h3>
			<ul>
				<?php foreach ( array_slice( $counts, 0, self::WIDGET_SITES, true ) as $id => $count ) : ?>
					<li>
						<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<a href="<?php echo esc_url( $sites[ $id ]['admin_url'] . 'edit.php?post_status=future&post_type=post' ); ?>"><?php echo esc_html( $sites[ $id ]['name'] ); ?></a>
						<span class="mst-muted">
							<?php
							/* translators: %s: number of posts */
							echo esc_html( sprintf( _n( '%s post', '%s posts', $count, 'multisite-tools' ), number_format_i18n( $count ) ) );
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<h3><?php esc_html_e( 'Recently published late', 'multisite-tools' ); ?></h3>
		<ul>
			<?php foreach ( array_slice( $entries, 0, self::WIDGET_POSTS ) as $entry ) : ?>
				<li>
					<?php echo MST_Sites::swatch( $entry['site'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<a href="<?php echo esc_url( $sites[ $entry['site'] ]['admin_url'] . 'post.php?post=' . (int) $entry['post'] . '&action=edit' ); ?>"><?php echo esc_html( '' !== $entry['title'] ? $entry['title'] : __( '(no title)', 'multisite-tools' ) ); ?></a>
					<?php if ( ! empty( $entry['lost'] ) ) : ?>
						<span class="mst-flag" title="<?php esc_attr_e( 'Its scheduled publish event was missing, so WordPress would never have published it.', 'multisite-tools' ); ?>"><?php esc_html_e( 'Schedule lost', 'multisite-tools' ); ?></span>
					<?php endif; ?>
					<br>
					<span class="mst-muted">
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: site name, 2: how late, e.g. "2 hours", 3: how long ago, e.g. "3 days" */
								__( '%1$s · %2$s late · %3$s ago', 'multisite-tools' ),
								$sites[ $entry['site'] ]['name'],
								human_time_diff( 0, max( 60, (int) $entry['late'] ) ),
								human_time_diff( (int) $entry['time'] )
							)
						);
						?>
					</span>
				</li>
			<?php endforeach; ?>
		</ul>
		<p class="mst-muted">
			<?php esc_html_e( 'Sites that miss often are rarely visited or have cron trouble. A server cron job that requests each site\'s wp-cron.php is the reliable fix.', 'multisite-tools' ); ?>
		</p>
		<?php
	}

	/**
	 * At most every INTERVAL across the network, pings the cron of each site
	 * with an overdue post.
	 */
	public function maybe_scan() {
		// Cron requests are often the pings themselves.
		if ( wp_doing_cron() || get_site_transient( self::LOCK_KEY ) ) {
			return;
		}

		set_site_transient( self::LOCK_KEY, time(), self::INTERVAL );

		foreach ( $this->overdue_sites() as $site_id ) {
			$this->ping( $site_id );
		}
	}

	/**
	 * @return int[] IDs of active sites with a post overdue by more than GRACE.
	 */
	private function overdue_sites() {
		global $wpdb;

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - self::GRACE );

		$rows = MST_Sites::query(
			MST_Sites::active(),
			function ( $site ) use ( $wpdb, $cutoff ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
				return $wpdb->prepare(
					"SELECT %d AS blog_id FROM `{$table}` WHERE post_status = 'future' AND post_date_gmt <= %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id,
					$cutoff
				);
			}
		);

		return array_map(
			function ( $row ) {
				return (int) $row->blog_id;
			},
			$rows
		);
	}

	/**
	 * Requests the site's wp-cron.php without waiting for a response, as core's
	 * spawn_cron() does.
	 *
	 * @param int $site_id
	 */
	private function ping( $site_id ) {
		wp_remote_post(
			get_site_url( $site_id, 'wp-cron.php' ),
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				/** This filter is documented in wp-includes/class-wp-http-streams.php */
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}
}
