<?php
/**
 * Calendar: a month calendar and an upcoming-posts agenda covering every site
 * you can edit, under Dashboard › Calendar in Network Admin and on each site.
 *
 * Posts are shown at their own site's local date and time, since sites can be
 * in different time zones.
 */

defined( 'ABSPATH' ) || exit;

class MST_Calendar {

	const PAGE = 'mst-calendar';

	/**
	 * Post types shown on the calendar.
	 */
	const POST_TYPES = array( 'post' );

	/**
	 * Most posts read from one site for a month, or for the agenda.
	 */
	const PER_SITE_LIMIT = 500;

	public static function label() {
		return __( 'Calendar', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Dashboard › Calendar, in Network Admin and on each site: a month calendar of published and scheduled posts and an agenda of everything upcoming, across every site you can edit.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	public function register() {
		add_action( 'network_admin_menu', array( $this, 'add_network_page' ) );
		add_action( 'admin_menu', array( $this, 'add_site_page' ) );
	}

	public function add_network_page() {
		add_submenu_page( 'index.php', __( 'Calendar', 'multisite-tools' ), __( 'Calendar', 'multisite-tools' ), 'manage_sites', self::PAGE, array( $this, 'render_page' ) );
	}

	public function add_site_page() {
		add_submenu_page( 'index.php', __( 'Calendar', 'multisite-tools' ), __( 'Calendar', 'multisite-tools' ), 'edit_posts', self::PAGE, array( $this, 'render_page' ) );
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$view    = 'agenda' === ( $_GET['view'] ?? '' ) ? 'agenda' : 'month';
		$site_id = absint( $_GET['site'] ?? 0 );
		$month   = sanitize_text_field( wp_unslash( $_GET['month'] ?? '' ) );
		// phpcs:enable

		$sites = $this->sites();
		$shown = isset( $sites[ $site_id ] ) ? array( $site_id => $sites[ $site_id ] ) : $sites;
		?>
		<div class="wrap mst-cal">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Calendar', 'multisite-tools' ); ?></h1>
			<hr class="wp-header-end" />

			<?php if ( ! $sites ) : ?>
				<p><?php esc_html_e( 'You can\'t edit posts on any site.', 'multisite-tools' ); ?></p>
			<?php else : ?>
				<?php $this->print_styles(); ?>

				<div class="mst-cal-toolbar">
					<nav class="mst-cal-views">
						<?php
						foreach ( array(
							'month'  => __( 'Month', 'multisite-tools' ),
							'agenda' => __( 'Agenda', 'multisite-tools' ),
						) as $key => $label ) :
							?>
							<a href="<?php echo esc_url( $this->url( array( 'view' => $key, 'site' => $site_id ?: false ) ) ); ?>" class="button<?php echo $key === $view ? ' button-primary' : ''; ?>"><?php echo esc_html( $label ); ?></a>
						<?php endforeach; ?>
					</nav>

					<?php if ( count( $sites ) > 1 ) : ?>
						<form method="get" class="mst-cal-filter">
							<?php foreach ( array( 'page' => self::PAGE, 'view' => $view, 'month' => $month ) as $name => $value ) : ?>
								<?php if ( '' !== $value ) : ?>
									<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>" />
								<?php endif; ?>
							<?php endforeach; ?>
							<label class="screen-reader-text" for="mst-cal-site"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></label>
							<select name="site" id="mst-cal-site" onchange="this.form.submit()">
								<option value=""><?php esc_html_e( 'All sites', 'multisite-tools' ); ?></option>
								<?php foreach ( $sites as $id => $site ) : ?>
									<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $site_id, $id ); ?>><?php echo esc_html( $site['name'] ); ?></option>
								<?php endforeach; ?>
							</select>
							<noscript><?php submit_button( __( 'Filter', 'multisite-tools' ), 'secondary', '', false ); ?></noscript>
						</form>
					<?php endif; ?>
				</div>

				<?php
				if ( 'agenda' === $view ) {
					$this->render_agenda( $shown );
				} else {
					$this->render_month( $shown, $month, $site_id );
				}

				$this->render_legend( $shown );
				$this->render_dialog();
				?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array> $sites
	 * @param string            $month   'YYYY-MM', or '' for this month.
	 * @param int               $site_id Site filter, or 0.
	 */
	private function render_month( $sites, $month, $site_id ) {
		global $wp_locale;

		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'now', $tz );
		$first = preg_match( '/^\d{4}-(0[1-9]|1[0-2])$/', $month )
			? new DateTimeImmutable( $month . '-01', $tz )
			: $today->modify( 'first day of this month' )->setTime( 0, 0 );

		// Pad the grid out to whole weeks.
		$start_of_week = (int) get_option( 'start_of_week', 1 );
		$grid_start    = $first->modify( '-' . ( ( (int) $first->format( 'w' ) - $start_of_week + 7 ) % 7 ) . ' days' );
		$last          = $first->modify( 'last day of this month' );
		$grid_end      = $last->modify( '+' . ( ( $start_of_week + 6 - (int) $last->format( 'w' ) ) % 7 ) . ' days' );

		$by_day = array();
		foreach ( $this->get_posts( $sites, array( 'publish', 'future' ), $grid_start->format( 'Y-m-d 00:00:00' ), $grid_end->modify( '+1 day' )->format( 'Y-m-d 00:00:00' ) ) as $post ) {
			$by_day[ substr( $post['date'], 0, 10 ) ][] = $post;
		}

		$nav = function ( $date ) use ( $site_id ) {
			return $this->url(
				array(
					'month' => $date->format( 'Y-m' ),
					'site'  => $site_id ?: false,
				)
			);
		};
		?>
		<div class="mst-cal-monthnav">
			<a class="button" href="<?php echo esc_url( $nav( $first->modify( '-1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Previous month', 'multisite-tools' ); ?>">&lsaquo;</a>
			<h2><?php echo esc_html( $wp_locale->get_month( $first->format( 'm' ) ) . ' ' . $first->format( 'Y' ) ); ?></h2>
			<a class="button" href="<?php echo esc_url( $nav( $first->modify( '+1 month' ) ) ); ?>" aria-label="<?php esc_attr_e( 'Next month', 'multisite-tools' ); ?>">&rsaquo;</a>
			<a class="button" href="<?php echo esc_url( $nav( $today ) ); ?>"><?php esc_html_e( 'Today', 'multisite-tools' ); ?></a>
		</div>

		<table class="mst-cal-grid">
			<thead>
				<tr>
					<?php for ( $i = 0; $i < 7; $i++ ) : ?>
						<th scope="col"><?php echo esc_html( $wp_locale->get_weekday_abbrev( $wp_locale->get_weekday( ( $start_of_week + $i ) % 7 ) ) ); ?></th>
					<?php endfor; ?>
				</tr>
			</thead>
			<tbody>
				<?php
				for ( $day = $grid_start; $day <= $grid_end; $day = $day->modify( '+1 day' ) ) :
					$key     = $day->format( 'Y-m-d' );
					$classes = array();
					if ( $day->format( 'm' ) !== $first->format( 'm' ) ) {
						$classes[] = 'is-other-month';
					}
					if ( $key === $today->format( 'Y-m-d' ) ) {
						$classes[] = 'is-today';
					}

					if ( (int) $day->format( 'w' ) === $start_of_week ) {
						echo '<tr>';
					}
					?>
					<td class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<span class="mst-cal-daynum"><?php echo esc_html( number_format_i18n( (int) $day->format( 'j' ) ) ); ?></span>
						<?php foreach ( $by_day[ $key ] ?? array() as $post ) : ?>
							<?php $this->render_item( $post, mysql2date( get_option( 'time_format' ), $post['date'] ) ); ?>
						<?php endforeach; ?>
					</td>
					<?php
					if ( (int) $day->modify( '+1 day' )->format( 'w' ) === $start_of_week ) {
						echo '</tr>';
					}
				endfor;
				?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<int, array> $sites
	 */
	private function render_agenda( $sites ) {
		$posts = $this->get_posts( $sites, array( 'future' ) );

		if ( ! $posts ) {
			echo '<p>' . esc_html__( 'Nothing is scheduled.', 'multisite-tools' ) . '</p>';
			return;
		}

		$today    = current_time( 'Y-m-d' );
		$tomorrow = gmdate( 'Y-m-d', strtotime( $today . ' +1 day' ) );
		$groups   = array();

		foreach ( $posts as $post ) {
			$groups[ $post['missed'] ? 'missed' : substr( $post['date'], 0, 10 ) ][] = $post;
		}

		// Missed posts first.
		uksort(
			$groups,
			function ( $a, $b ) {
				return 'missed' === $a ? -1 : ( 'missed' === $b ? 1 : strcmp( $a, $b ) );
			}
		);
		?>
		<div class="mst-cal-agenda">
			<?php foreach ( $groups as $key => $items ) : ?>
				<h2 class="<?php echo 'missed' === $key ? 'is-missed' : ''; ?>">
					<?php
					if ( 'missed' === $key ) {
						esc_html_e( 'Missed schedule', 'multisite-tools' );
					} elseif ( $today === $key ) {
						esc_html_e( 'Today', 'multisite-tools' );
					} elseif ( $tomorrow === $key ) {
						esc_html_e( 'Tomorrow', 'multisite-tools' );
					} else {
						echo esc_html( mysql2date( 'l, ' . get_option( 'date_format' ), $key ) );
					}
					?>
				</h2>
				<ul>
					<?php foreach ( $items as $post ) : ?>
						<li>
							<?php
							$when = 'missed' === $key
								? mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post['date'] )
								: mysql2date( get_option( 'time_format' ), $post['date'] );

							$this->render_item( $post, $when, true );
							?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * A post as a button that opens the details dialog.
	 *
	 * @param array  $post
	 * @param string $when     Time (or date and time) label.
	 * @param bool   $detailed Also show the site and author inline.
	 */
	private function render_item( $post, $when, $detailed = false ) {
		$status = $post['missed'] ? __( 'Missed schedule', 'multisite-tools' ) : ( 'future' === $post['status'] ? __( 'Scheduled', 'multisite-tools' ) : __( 'Published', 'multisite-tools' ) );

		$details = array(
			'title'     => $post['title'],
			'site'      => $post['site']['name'],
			'color'     => $post['site']['color'],
			'status'    => $status,
			'when'      => mysql2date( 'l, ' . get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $post['date'] ),
			'author'    => $post['author'],
			'edit'      => $post['edit_url'],
			'view'      => $post['view_url'],
			'viewLabel' => 'publish' === $post['status'] ? __( 'View', 'multisite-tools' ) : __( 'Preview', 'multisite-tools' ),
		);
		?>
		<button type="button" class="mst-cal-item is-<?php echo esc_attr( $post['missed'] ? 'missed' : $post['status'] ); ?>" style="--mst-site: <?php echo esc_attr( $post['site']['color'] ); ?>" data-post="<?php echo esc_attr( wp_json_encode( $details ) ); ?>" title="<?php echo esc_attr( $post['title'] . ' — ' . $post['site']['name'] ); ?>">
			<span class="mst-cal-time"><?php echo esc_html( $when ); ?></span>
			<span class="mst-cal-title"><?php echo esc_html( $post['title'] ); ?></span>
			<?php if ( $detailed ) : ?>
				<span class="mst-cal-meta"><?php echo esc_html( $post['site']['name'] . ( '' !== $post['author'] ? ' · ' . $post['author'] : '' ) ); ?></span>
			<?php endif; ?>
			<?php if ( 'future' === $post['status'] ) : ?>
				<span class="mst-cal-status"><?php echo esc_html( $status ); ?></span>
			<?php endif; ?>
		</button>
		<?php
	}

	/**
	 * @param array<int, array> $sites
	 */
	private function render_legend( $sites ) {
		if ( count( $sites ) < 2 ) {
			return;
		}
		?>
		<ul class="mst-cal-legend">
			<?php foreach ( $sites as $site ) : ?>
				<li><span class="mst-cal-swatch" style="background: <?php echo esc_attr( $site['color'] ); ?>"></span><?php echo esc_html( $site['name'] ); ?></li>
			<?php endforeach; ?>
		</ul>
		<?php
	}

	private function render_dialog() {
		?>
		<dialog class="mst-cal-dialog" aria-labelledby="mst-cal-dialog-title">
			<form method="dialog">
				<button class="mst-cal-close" aria-label="<?php esc_attr_e( 'Close', 'multisite-tools' ); ?>">&times;</button>
			</form>
			<p class="mst-cal-dialog-site"><span class="mst-cal-swatch"></span><span data-field="site"></span></p>
			<h2 id="mst-cal-dialog-title" data-field="title"></h2>
			<dl>
				<dt><?php esc_html_e( 'Status', 'multisite-tools' ); ?></dt><dd data-field="status"></dd>
				<dt><?php esc_html_e( 'Date', 'multisite-tools' ); ?></dt><dd data-field="when"></dd>
				<dt><?php esc_html_e( 'Author', 'multisite-tools' ); ?></dt><dd data-field="author"></dd>
			</dl>
			<p class="mst-cal-dialog-actions">
				<a class="button button-primary" data-link="edit"><?php esc_html_e( 'Edit', 'multisite-tools' ); ?></a>
				<a class="button" data-link="view" target="_blank" rel="noopener"></a>
			</p>
		</dialog>
		<script>
			( function () {
				var dialog = document.querySelector( '.mst-cal-dialog' );

				document.addEventListener( 'click', function ( event ) {
					var item = event.target.closest( '.mst-cal-item' );
					if ( ! item ) {
						return;
					}

					var post = JSON.parse( item.dataset.post );

					dialog.querySelectorAll( '[data-field]' ).forEach( function ( el ) {
						el.textContent = post[ el.dataset.field ] || '—';
					} );
					dialog.querySelector( '.mst-cal-swatch' ).style.background = post.color;
					dialog.querySelector( '[data-link="edit"]' ).href = post.edit;
					dialog.querySelector( '[data-link="view"]' ).href = post.view;
					dialog.querySelector( '[data-link="view"]' ).textContent = post.viewLabel;
					dialog.showModal();
				} );

				// Close when clicking the backdrop.
				dialog.addEventListener( 'click', function ( event ) {
					if ( event.target === dialog ) {
						dialog.close();
					}
				} );
			} )();
		</script>
		<?php
	}

	private function print_styles() {
		?>
		<style>
			.mst-cal-toolbar { display: flex; flex-wrap: wrap; gap: 8px 16px; align-items: center; justify-content: space-between; margin: 12px 0; }
			.mst-cal-views { display: flex; gap: 4px; }
			.mst-cal-monthnav { display: flex; align-items: center; gap: 6px; margin: 8px 0 12px; }
			.mst-cal-monthnav h2 { margin: 0 8px; min-width: 10em; text-align: center; }

			.mst-cal-grid { width: 100%; table-layout: fixed; border-collapse: collapse; background: #fff; }
			.mst-cal-grid th { padding: 6px; text-align: left; font-weight: 600; border-bottom: 1px solid #c3c4c7; }
			.mst-cal-grid td { height: 110px; padding: 4px; vertical-align: top; border: 1px solid #dcdcde; overflow: hidden; }
			.mst-cal-grid td.is-other-month { background: #f6f7f7; }
			.mst-cal-grid td.is-other-month .mst-cal-daynum { color: #a7aaad; }
			.mst-cal-grid td.is-today { box-shadow: inset 0 0 0 2px #2271b1; }
			.mst-cal-daynum { display: block; margin-bottom: 2px; font-weight: 600; color: #50575e; }

			.mst-cal-item { display: block; width: 100%; margin: 0 0 3px; padding: 2px 4px 2px 6px; border: 0; border-left: 3px solid var(--mst-site); border-radius: 2px; background: #f0f0f1; text-align: left; font: inherit; font-size: 12px; line-height: 1.4; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
			.mst-cal-item:hover, .mst-cal-item:focus { background: #e0e0e0; }
			.mst-cal-item.is-future, .mst-cal-item.is-missed { background: #fff; outline: 1px dashed #c3c4c7; outline-offset: -1px; }
			.mst-cal-item.is-missed { border-left-color: #d63638; }
			.mst-cal-time { color: #50575e; margin-right: 4px; }
			.mst-cal-grid .mst-cal-status { display: none; }

			.mst-cal-agenda { max-width: 800px; }
			.mst-cal-agenda h2 { margin: 24px 0 8px; font-size: 14px; text-transform: uppercase; letter-spacing: .03em; color: #50575e; }
			.mst-cal-agenda h2.is-missed { color: #d63638; }
			.mst-cal-agenda ul { margin: 0; }
			.mst-cal-agenda li { margin: 0 0 6px; }
			.mst-cal-agenda .mst-cal-item { display: grid; grid-template-columns: 8em 1fr auto; grid-template-areas: "time title status" "time meta status"; padding: 8px 12px; font-size: 13px; white-space: normal; background: #fff; outline: 0; border: 1px solid #dcdcde; border-left: 4px solid var(--mst-site); }
			.mst-cal-agenda .mst-cal-time { grid-area: time; }
			.mst-cal-agenda .mst-cal-title { grid-area: title; font-weight: 600; }
			.mst-cal-agenda .mst-cal-meta { grid-area: meta; color: #646970; }
			.mst-cal-agenda .mst-cal-status { grid-area: status; align-self: center; color: #646970; }
			.mst-cal-agenda .is-missed .mst-cal-status { color: #d63638; font-weight: 600; }

			.mst-cal-legend { display: flex; flex-wrap: wrap; gap: 4px 16px; margin: 16px 0; }
			.mst-cal-legend li { margin: 0; }
			.mst-cal-swatch { display: inline-block; width: 10px; height: 10px; margin-right: 6px; border-radius: 2px; vertical-align: baseline; }

			.mst-cal-dialog { width: min(440px, calc(100vw - 32px)); padding: 20px 24px; border: 0; border-radius: 4px; box-shadow: 0 8px 32px rgba(0,0,0,.25); }
			.mst-cal-dialog::backdrop { background: rgba(0,0,0,.4); }
			.mst-cal-dialog h2 { margin: 4px 0 12px; font-size: 18px; }
			.mst-cal-dialog-site { margin: 0; color: #50575e; }
			.mst-cal-dialog dl { display: grid; grid-template-columns: auto 1fr; gap: 4px 16px; margin: 0 0 16px; }
			.mst-cal-dialog dt { font-weight: 600; }
			.mst-cal-dialog dd { margin: 0; }
			.mst-cal-dialog-actions { display: flex; gap: 8px; margin: 0; }
			.mst-cal-close { position: absolute; top: 8px; right: 12px; border: 0; background: none; font-size: 22px; line-height: 1; cursor: pointer; color: #50575e; }

			@media (max-width: 782px) {
				.mst-cal-grid td { height: 70px; }
				.mst-cal-grid .mst-cal-time { display: none; }
				.mst-cal-agenda .mst-cal-item { grid-template-columns: 5em 1fr; grid-template-areas: "time title" "time meta" "time status"; }
			}
		</style>
		<?php
	}

	/**
	 * Posts from the given sites, sorted by local date.
	 *
	 * @param array<int, array> $sites
	 * @param string[]          $statuses
	 * @param string|null       $from Local 'Y-m-d H:i:s', inclusive.
	 * @param string|null       $to   Local 'Y-m-d H:i:s', exclusive.
	 * @return array[]
	 */
	private function get_posts( $sites, $statuses, $from = null, $to = null ) {
		global $wpdb;

		$types    = "'" . implode( "', '", array_map( 'esc_sql', self::POST_TYPES ) ) . "'";
		$statuses = "'" . implode( "', '", array_map( 'esc_sql', $statuses ) ) . "'";
		$range    = $from ? $wpdb->prepare( ' AND post_date >= %s AND post_date < %s', $from, $to ) : '';

		$rows = MST_Sites::query(
			wp_list_pluck( $sites, 'site' ),
			function ( $site ) use ( $wpdb, $types, $statuses, $range ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
				// Matches the type_status_date index.
				return $wpdb->prepare(
					"SELECT %d AS blog_id, ID, post_title, post_status, post_date, post_date_gmt, post_author FROM `{$table}` WHERE post_type IN ({$types}) AND post_status IN ({$statuses}){$range} ORDER BY post_date LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id,
					self::PER_SITE_LIMIT
				);
			}
		);

		$authors = $this->author_names( wp_list_pluck( $rows, 'post_author' ) );
		$now_gmt = gmdate( 'Y-m-d H:i:s' );
		$posts   = array();

		foreach ( $rows as $row ) {
			$site    = $sites[ (int) $row->blog_id ];
			$id      = (int) $row->ID;
			$posts[] = array(
				'title'    => '' !== $row->post_title ? $row->post_title : __( '(no title)', 'multisite-tools' ),
				'status'   => $row->post_status,
				'missed'   => 'future' === $row->post_status && $row->post_date_gmt < $now_gmt,
				'date'     => $row->post_date,
				'author'   => $authors[ (int) $row->post_author ] ?? '',
				'site'     => $site,
				'edit_url' => $site['admin_url'] . 'post.php?post=' . $id . '&action=edit',
				'view_url' => $site['home_url'] . '?p=' . $id . ( 'publish' === $row->post_status ? '' : '&preview=true' ),
			);
		}

		usort(
			$posts,
			function ( $a, $b ) {
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $posts;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, string> User ID => display name.
	 */
	private function author_names( $ids ) {
		$ids = array_unique( array_map( 'intval', $ids ) );

		if ( ! $ids ) {
			return array();
		}

		$users = get_users(
			array(
				'blog_id' => 0,
				'include' => $ids,
				'fields'  => array( 'ID', 'display_name' ),
			)
		);

		return wp_list_pluck( $users, 'display_name', 'ID' );
	}

	/**
	 * Active sites the current user can edit posts on, as site ID => details.
	 * Super admins get every active site.
	 *
	 * @return array<int, array{site: WP_Site, name: string, admin_url: string, home_url: string, color: string}>
	 */
	private function sites() {
		if ( is_super_admin() ) {
			$sites = MST_Sites::active();
		} else {
			$ids = array();
			foreach ( get_blogs_of_user( get_current_user_id() ) as $site ) {
				// current_user_can_for_blog() is deprecated as of WordPress 6.7.
				$can = function_exists( 'current_user_can_for_site' )
					? current_user_can_for_site( $site->userblog_id, 'edit_posts' )
					: current_user_can_for_blog( $site->userblog_id, 'edit_posts' );

				if ( $can ) {
					$ids[] = (int) $site->userblog_id;
				}
			}

			$sites = $ids ? get_sites(
				array(
					'site__in' => $ids,
					'number'   => 0,
					'archived' => 0,
					'deleted'  => 0,
					'spam'     => 0,
				)
			) : array();
		}

		$options = MST_Sites::get_options( $sites, array( 'blogname', 'home' ) );
		$result  = array();

		foreach ( $sites as $site ) {
			$id   = (int) $site->blog_id;
			$name = (string) ( $options[ $id ]['blogname'] ?? '' );

			$result[ $id ] = array(
				'site'      => $site,
				'name'      => '' !== $name ? $name : MST_Sites::url( $site ),
				'admin_url' => MST_Sites::admin_url( $site ),
				'home_url'  => trailingslashit( $options[ $id ]['home'] ?? 'http://' . $site->domain . $site->path ),
				'color'     => MST_Sites::color( $id ),
			);
		}

		return $result;
	}

	/**
	 * This page's URL, in Network Admin or the current site's admin.
	 *
	 * @param array $args Query args; false values are dropped.
	 * @return string
	 */
	private function url( $args ) {
		$base = is_network_admin() ? network_admin_url( 'index.php' ) : admin_url( 'index.php' );
		return add_query_arg( array_merge( array( 'page' => self::PAGE ), array_filter( $args ) ), $base );
	}
}
