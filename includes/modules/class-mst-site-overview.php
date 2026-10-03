<?php
/**
 * Site overview: one table of every active site's publishing activity, with
 * the things worth attention flagged, under Network Admin › Sites › Overview.
 */

defined( 'ABSPATH' ) || exit;

class MST_Site_Overview {

	const PAGE = 'mst-site-overview';

	/**
	 * A site with no published post for this long is flagged as quiet.
	 */
	const QUIET_AFTER = 6 * MONTH_IN_SECONDS;

	public static function label() {
		return __( 'Site overview', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Network Admin › Sites › Overview: every site\'s last and next post, post counts, comments awaiting moderation and theme, flagging sites that are hidden from search engines, have missed a schedule or have gone quiet.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	public function register() {
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
	}

	public function add_page() {
		add_submenu_page( 'sites.php', __( 'Site Overview', 'multisite-tools' ), __( 'Overview', 'multisite-tools' ), 'manage_sites', self::PAGE, array( $this, 'render_page' ) );
	}

	public function render_page() {
		$sites   = $this->get_sites();
		$summary = array(
			'missed'  => 0,
			'hidden'  => 0,
			'quiet'   => 0,
			'pending' => 0,
		);

		foreach ( $sites as $site ) {
			foreach ( $site['flags'] as $flag => $label ) {
				$summary[ $flag ]++;
			}
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Site Overview', 'multisite-tools' ); ?></h1>

			<?php $this->render_summary( $summary, count( $sites ) ); ?>

			<style>
				.mst-overview td, .mst-overview th { vertical-align: top; }
				.mst-overview .num { text-align: right; }
				.mst-overview .mst-muted { color: #8c8f94; }
				.mst-flag { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; line-height: 1.6; white-space: nowrap; }
				.mst-flag-missed { background: #fcf0f1; color: #b32d2e; }
				.mst-flag-hidden, .mst-flag-quiet { background: #fcf9e8; color: #8a6d00; }
				.mst-flag-pending { background: #f0f6fc; color: #135e96; }
			</style>

			<table class="widefat striped mst-overview">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Last published', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Next scheduled', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Published', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Scheduled', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Drafts', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Comments', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Theme', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Needs attention', 'multisite-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $sites as $id => $site ) : ?>
						<tr>
							<td>
								<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<strong><a href="<?php echo esc_url( $site['admin_url'] ); ?>"><?php echo esc_html( $site['name'] ); ?></a></strong>
								<div class="row-actions visible">
									<a href="<?php echo esc_url( $site['admin_url'] ); ?>"><?php esc_html_e( 'Dashboard', 'multisite-tools' ); ?></a>
									| <a href="<?php echo esc_url( $site['home_url'] ); ?>"><?php esc_html_e( 'Visit', 'multisite-tools' ); ?></a>
									| <a href="<?php echo esc_url( network_admin_url( 'site-info.php?id=' . $id ) ); ?>"><?php esc_html_e( 'Edit site', 'multisite-tools' ); ?></a>
								</div>
							</td>
							<td><?php $this->render_date( $site['last_published'], $site['last_published_gmt'] ); ?></td>
							<td><?php $this->render_date( $site['next_scheduled'], $site['next_scheduled_gmt'] ); ?></td>
							<?php
							$this->render_count( $site['published'], $site['admin_url'] . 'edit.php?post_status=publish&post_type=post' );
							$this->render_count( $site['scheduled'], $site['admin_url'] . 'edit.php?post_status=future&post_type=post' );
							$this->render_count( $site['drafts'], $site['admin_url'] . 'edit.php?post_status=draft&post_type=post' );
							$this->render_count( $site['pending'], $site['admin_url'] . 'edit-comments.php?comment_status=moderated' );
							?>
							<td><?php echo esc_html( $site['theme'] ); ?></td>
							<td>
								<?php foreach ( $site['flags'] as $flag => $label ) : ?>
									<span class="mst-flag mst-flag-<?php echo esc_attr( $flag ); ?>"><?php echo esc_html( $label ); ?></span>
								<?php endforeach; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @param array<string, int> $summary Flag => number of sites.
	 * @param int                $total   Number of sites.
	 */
	private function render_summary( $summary, $total ) {
		$lines = array_filter(
			array(
				/* translators: %s: number of sites */
				'missed'  => _n( '%s site has missed a scheduled post.', '%s sites have missed a scheduled post.', $summary['missed'], 'multisite-tools' ),
				/* translators: %s: number of sites */
				'hidden'  => _n( '%s site is hidden from search engines.', '%s sites are hidden from search engines.', $summary['hidden'], 'multisite-tools' ),
				/* translators: %s: number of sites */
				'quiet'   => _n( '%s site has published nothing in 6 months.', '%s sites have published nothing in 6 months.', $summary['quiet'], 'multisite-tools' ),
				/* translators: %s: number of sites */
				'pending' => _n( '%s site has comments awaiting moderation.', '%s sites have comments awaiting moderation.', $summary['pending'], 'multisite-tools' ),
			),
			function ( $line, $flag ) use ( $summary ) {
				return $summary[ $flag ] > 0;
			},
			ARRAY_FILTER_USE_BOTH
		);

		if ( ! $lines ) {
			/* translators: %s: number of sites */
			echo '<div class="notice notice-success inline"><p>' . esc_html( sprintf( _n( 'Your %s site looks healthy.', 'All %s sites look healthy.', $total, 'multisite-tools' ), number_format_i18n( $total ) ) ) . '</p></div>';
			return;
		}

		echo '<div class="notice notice-warning inline"><ul>';
		foreach ( $lines as $flag => $line ) {
			echo '<li>' . esc_html( sprintf( $line, number_format_i18n( $summary[ $flag ] ) ) ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * A local date, with how long ago or until it is.
	 *
	 * @param string|null $local Site-local 'Y-m-d H:i:s'.
	 * @param string|null $gmt   The same moment in UTC.
	 */
	private function render_date( $local, $gmt ) {
		if ( ! $local ) {
			echo '<span class="mst-muted">—</span>';
			return;
		}

		$time = strtotime( $gmt . ' UTC' );

		echo esc_html( mysql2date( get_option( 'date_format' ), $local ) );
		echo '<br /><span class="mst-muted">';
		echo esc_html(
			sprintf(
				/* translators: %s: human-readable time difference, e.g. "3 days" */
				$time < time() ? __( '%s ago', 'multisite-tools' ) : __( 'in %s', 'multisite-tools' ),
				human_time_diff( $time )
			)
		);
		echo '</span>';
	}

	/**
	 * @param int    $count
	 * @param string $url
	 */
	private function render_count( $count, $url ) {
		echo '<td class="num">';
		if ( $count ) {
			echo '<a href="' . esc_url( $url ) . '">' . esc_html( number_format_i18n( $count ) ) . '</a>';
		} else {
			echo '<span class="mst-muted">0</span>';
		}
		echo '</td>';
	}

	/**
	 * Every active site with its stats and flags.
	 *
	 * @return array<int, array>
	 */
	private function get_sites() {
		global $wpdb;

		$sites   = MST_Sites::details( MST_Sites::active(), array( 'stylesheet' ) );
		$objects = wp_list_pluck( $sites, 'site' );
		$now_gmt = gmdate( 'Y-m-d H:i:s' );

		$posts = MST_Sites::query(
			$objects,
			function ( $site ) use ( $wpdb, $now_gmt ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
				// No GROUP BY, so every site returns exactly one row.
				return $wpdb->prepare(
					"SELECT %d AS blog_id,
						SUM(post_status = 'publish') AS published,
						SUM(post_status = 'future') AS scheduled,
						SUM(post_status = 'draft') AS drafts,
						SUM(post_status = 'future' AND post_date_gmt < %s) AS missed,
						MAX(IF(post_status = 'publish', post_date, NULL)) AS last_published,
						MAX(IF(post_status = 'publish', post_date_gmt, NULL)) AS last_published_gmt,
						MIN(IF(post_status = 'future', post_date, NULL)) AS next_scheduled,
						MIN(IF(post_status = 'future', post_date_gmt, NULL)) AS next_scheduled_gmt
					FROM `{$table}` WHERE post_type = 'post' AND post_status IN ('publish', 'future', 'draft')", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id,
					$now_gmt
				);
			}
		);

		$comments = MST_Sites::query(
			$objects,
			function ( $site ) use ( $wpdb ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'comments';
				return $wpdb->prepare(
					"SELECT %d AS blog_id, COUNT(*) AS pending FROM `{$table}` WHERE comment_approved = '0'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id
				);
			}
		);

		$posts    = array_column( $posts, null, 'blog_id' );
		$comments = array_column( $comments, null, 'blog_id' );
		$themes   = array();
		$quiet    = gmdate( 'Y-m-d H:i:s', time() - self::QUIET_AFTER );

		foreach ( $sites as $id => &$site ) {
			$row        = $posts[ $id ] ?? null;
			$stylesheet = (string) ( $site['options']['stylesheet'] ?? '' );

			if ( ! isset( $themes[ $stylesheet ] ) ) {
				$theme                 = wp_get_theme( $stylesheet );
				$themes[ $stylesheet ] = $theme->exists()
					? $theme->get( 'Name' )
					/* translators: %s: theme directory name */
					: sprintf( __( '%s (missing)', 'multisite-tools' ), $stylesheet );
			}

			$site += array(
				'published'          => (int) ( $row->published ?? 0 ),
				'scheduled'          => (int) ( $row->scheduled ?? 0 ),
				'drafts'             => (int) ( $row->drafts ?? 0 ),
				'missed'             => (int) ( $row->missed ?? 0 ),
				'last_published'     => $row->last_published ?? null,
				'last_published_gmt' => $row->last_published_gmt ?? null,
				'next_scheduled'     => $row->next_scheduled ?? null,
				'next_scheduled_gmt' => $row->next_scheduled_gmt ?? null,
				'pending'            => (int) ( $comments[ $id ]->pending ?? 0 ),
				'theme'              => $themes[ $stylesheet ],
				'flags'              => array(),
			);

			if ( $site['missed'] ) {
				/* translators: %s: number of posts */
				$site['flags']['missed'] = sprintf( _n( '%s missed schedule', '%s missed schedules', $site['missed'], 'multisite-tools' ), number_format_i18n( $site['missed'] ) );
			}
			if ( 1 !== (int) $site['site']->public ) {
				$site['flags']['hidden'] = __( 'Hidden from search engines', 'multisite-tools' );
			}
			if ( ! $site['last_published_gmt'] || $site['last_published_gmt'] < $quiet ) {
				$site['flags']['quiet'] = $site['last_published_gmt'] ? __( 'Quiet for 6+ months', 'multisite-tools' ) : __( 'Nothing published', 'multisite-tools' );
			}
			if ( $site['pending'] ) {
				/* translators: %s: number of comments */
				$site['flags']['pending'] = sprintf( _n( '%s comment to moderate', '%s comments to moderate', $site['pending'], 'multisite-tools' ), number_format_i18n( $site['pending'] ) );
			}
		}
		unset( $site );

		return $sites;
	}
}
