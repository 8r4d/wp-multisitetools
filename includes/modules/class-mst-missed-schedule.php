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

	public static function label() {
		return __( 'Missed schedule fixer', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Publishes scheduled posts that were missed because their site had no visitors at the scheduled time. Any visit to any site checks the whole network, at most every 5 minutes.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		add_action( 'wp_loaded', array( $this, 'publish_overdue' ) );

		// After core's own cron spawn (wp_loaded, 20).
		add_action( 'wp_loaded', array( $this, 'maybe_scan' ), 21 );
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

		foreach ( $post_ids as $post_id ) {
			// Re-checks the date and runs the normal publish hooks.
			check_and_publish_future_post( (int) $post_id );
		}
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
