<?php
/**
 * User audit: every user in the network with their role on each site, when
 * they last logged in, and the accounts worth a look flagged, under Network
 * Admin › Users › Audit.
 *
 * WordPress doesn't record logins, so this module does from when it's
 * switched on, in each user's mst_last_login meta. Users with a live login
 * session also have its start time, which fills the gap for them.
 */

defined( 'ABSPATH' ) || exit;

class MST_User_Audit {

	const PAGE = 'mst-user-audit';

	/**
	 * User meta: Unix time of the user's last login.
	 */
	const META = 'mst_last_login';

	/**
	 * Network option: Unix time logins started being recorded.
	 */
	const SINCE_OPTION = 'mst_user_audit_since';

	/**
	 * A user with no login for this long is flagged as inactive.
	 */
	const INACTIVE_AFTER = 90 * DAY_IN_SECONDS;

	/**
	 * Rows per page.
	 */
	const PER_PAGE = 100;

	/**
	 * Sites listed on a row before the rest are folded away.
	 */
	const SITES_SHOWN = 5;

	public static function label() {
		return __( 'User audit', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Network Admin › Users › Audit: every user\'s role on each site and their last login, flagging super admins, site admins, accounts with no sites and accounts unused for 90 days, especially ones that can still administer a site. Starts recording logins when switched on.', 'multisite-tools' );
	}

	public static function category() {
		return 'security';
	}

	/**
	 * Called when the module is switched back on. Logins weren't recorded
	 * while it was off, so a missing login only counts from now.
	 */
	public function enable() {
		update_site_option( self::SINCE_OPTION, time() );
	}

	public function register() {
		add_action( 'wp_login', array( $this, 'record_login' ), 10, 2 );

		if ( is_network_admin() ) {
			add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		}
	}

	/**
	 * @param string  $login
	 * @param WP_User $user
	 */
	public function record_login( $login, $user ) {
		update_user_meta( $user->ID, self::META, time() );

		// Modules start on, so enable() doesn't run on a fresh install.
		if ( ! get_site_option( self::SINCE_OPTION ) ) {
			update_site_option( self::SINCE_OPTION, time() );
		}
	}

	public function add_page() {
		add_submenu_page( 'users.php', __( 'User Audit', 'multisite-tools' ), __( 'Audit', 'multisite-tools' ), 'manage_network_users', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * @return array<string, string> View slug => label.
	 */
	private static function views() {
		return array(
			'all'      => __( 'All', 'multisite-tools' ),
			'super'    => __( 'Super admins', 'multisite-tools' ),
			'admin'    => __( 'Site admins', 'multisite-tools' ),
			'inactive' => __( 'Inactive', 'multisite-tools' ),
			'nosites'  => __( 'No sites', 'multisite-tools' ),
		);
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$view   = sanitize_key( wp_unslash( $_GET['view'] ?? 'all' ) );
		$search = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		$paged  = max( 1, absint( $_GET['paged'] ?? 1 ) );
		// phpcs:enable

		$views = self::views();
		$view  = isset( $views[ $view ] ) ? $view : 'all';
		$sites = MST_Sites::details( MST_Sites::all() );
		$users = $this->get_users( $sites );
		$since = (int) get_site_option( self::SINCE_OPTION, 0 );

		$lists = array_fill_keys( array_keys( $views ), array() );
		foreach ( $users as $id => $user ) {
			if ( '' !== $search && false === stripos( $user['login'] . ' ' . $user['email'] . ' ' . $user['name'], $search ) ) {
				continue;
			}
			$lists['all'][] = $id;
			foreach ( array_keys( $user['flags'] ) as $flag ) {
				if ( isset( $lists[ $flag ] ) ) {
					$lists[ $flag ][] = $id;
				}
			}
		}

		$base = array(
			'page' => self::PAGE,
			's'    => '' !== $search ? rawurlencode( $search ) : false,
		);
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'User Audit', 'multisite-tools' ); ?></h1>
			<?php
			$this->print_styles();
			$this->render_summary( $users, $since );
			?>

			<form method="get" action="<?php echo esc_url( network_admin_url( 'users.php' ) ); ?>" class="mst-audit-search">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>">
				<input type="hidden" name="view" value="<?php echo esc_attr( $view ); ?>">
				<label class="screen-reader-text" for="mst-audit-search"><?php esc_html_e( 'Search users', 'multisite-tools' ); ?></label>
				<input type="search" id="mst-audit-search" name="s" value="<?php echo esc_attr( $search ); ?>" placeholder="<?php esc_attr_e( 'Username, name or email', 'multisite-tools' ); ?>">
				<button type="submit" class="button"><?php esc_html_e( 'Search', 'multisite-tools' ); ?></button>
			</form>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $views as $slug => $label ) : ?>
					<a href="<?php echo esc_url( add_query_arg( array_merge( $base, array( 'view' => $slug ) ), network_admin_url( 'users.php' ) ) ); ?>" class="nav-tab<?php echo $slug === $view ? ' nav-tab-active' : ''; ?>">
						<?php echo esc_html( $label ); ?>
						<span class="count">(<?php echo esc_html( number_format_i18n( count( $lists[ $slug ] ) ) ); ?>)</span>
					</a>
				<?php endforeach; ?>
			</nav>

			<?php
			$this->render_intro( $view, $since );

			if ( ! $lists[ $view ] ) {
				echo '<p>' . esc_html( '' !== $search ? __( 'No users match your search.', 'multisite-tools' ) : __( 'No users found.', 'multisite-tools' ) ) . '</p>';
			} else {
				$this->render_table( $lists[ $view ], $users, $sites, $paged, add_query_arg( array_merge( $base, array( 'view' => $view ) ), network_admin_url( 'users.php' ) ) );
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param array<int, array> $users
	 * @param int               $since When logins started being recorded.
	 */
	private function render_summary( $users, $since ) {
		$inactive_admins = 0;
		$super_inactive  = 0;
		foreach ( $users as $user ) {
			if ( isset( $user['flags']['inactive'] ) && isset( $user['flags']['super'] ) ) {
				++$super_inactive;
			} elseif ( isset( $user['flags']['inactive'] ) && isset( $user['flags']['admin'] ) ) {
				++$inactive_admins;
			}
		}

		$lines = array();
		if ( $super_inactive ) {
			/* translators: %s: number of users */
			$lines[] = sprintf( _n( '%s super admin hasn\'t logged in for 90 days.', '%s super admins haven\'t logged in for 90 days.', $super_inactive, 'multisite-tools' ), number_format_i18n( $super_inactive ) );
		}
		if ( $inactive_admins ) {
			/* translators: %s: number of users */
			$lines[] = sprintf( _n( '%s site admin hasn\'t logged in for 90 days but can still administer a site.', '%s site admins haven\'t logged in for 90 days but can still administer a site.', $inactive_admins, 'multisite-tools' ), number_format_i18n( $inactive_admins ) );
		}

		if ( $lines ) {
			echo '<div class="notice notice-warning inline"><ul>';
			foreach ( $lines as $line ) {
				echo '<li>' . esc_html( $line ) . '</li>';
			}
			echo '<li>' . esc_html__( 'Unused accounts with admin rights are a common way in. Remove their admin role or the account if they no longer need it.', 'multisite-tools' ) . '</li>';
			echo '</ul></div>';
		}

		if ( ! $since || time() - $since < self::INACTIVE_AFTER ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html(
				$since
					/* translators: %s: date */
					? sprintf( __( 'Logins have been recorded since %s. Until that\'s 90 days ago, users are only flagged as inactive if their last recorded login is that old.', 'multisite-tools' ), wp_date( get_option( 'date_format' ), $since ) )
					: __( 'No logins have been recorded yet. They\'re recorded from now on, so last logins will fill in as people log in.', 'multisite-tools' )
			) . '</p></div>';
		}
	}

	/**
	 * @param string $view
	 * @param int    $since
	 */
	private function render_intro( $view, $since ) {
		$intros = array(
			'all'      => __( 'Every user in the network, with their role on each site.', 'multisite-tools' ),
			'super'    => __( 'Super admins can do anything on every site and in Network Admin. Keep this list short.', 'multisite-tools' ),
			'admin'    => __( 'Users with the Administrator role on at least one site, who can add users, change settings and edit everything on it.', 'multisite-tools' ),
			'inactive' => __( 'Users who haven\'t logged in for 90 days, or who registered more than 90 days ago and haven\'t logged in since logins started being recorded.', 'multisite-tools' ),
			'nosites'  => __( 'Users without a role on any site who aren\'t super admins. They can still log in and edit their profile, and usually belonged to a site that\'s gone or were removed from one.', 'multisite-tools' ),
		);

		echo '<p>' . esc_html( $intros[ $view ] ) . '</p>';
	}

	/**
	 * @param int[]             $ids   Users in the view.
	 * @param array<int, array> $users Every user, by ID.
	 * @param array<int, array> $sites Every site's details.
	 * @param int               $paged
	 * @param string            $url   The view's URL, for pagination.
	 */
	private function render_table( $ids, $users, $sites, $paged, $url ) {
		$pages = (int) ceil( count( $ids ) / self::PER_PAGE );
		$paged = min( $paged, $pages );
		$ids   = array_slice( $ids, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );

		$this->render_pagination( $paged, $pages, $url );
		?>
		<table class="widefat striped mst-audit">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'User', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Sites and roles', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Last login', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Registered', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Flags', 'multisite-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $ids as $id ) :
					$user = $users[ $id ];
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( network_admin_url( 'user-edit.php?user_id=' . $id ) ); ?>"><?php echo esc_html( $user['login'] ); ?></a></strong>
							<?php if ( '' !== $user['name'] && $user['name'] !== $user['login'] ) : ?>
								<br><?php echo esc_html( $user['name'] ); ?>
							<?php endif; ?>
							<br><span class="mst-muted"><?php echo esc_html( $user['email'] ); ?></span>
						</td>
						<td><?php $this->render_roles( $user['roles'], $sites ); ?></td>
						<td><?php $this->render_date( $user['last_login'] ); ?></td>
						<td><?php $this->render_date( $user['registered'] ); ?></td>
						<td>
							<?php foreach ( $user['flags'] as $flag => $label ) : ?>
								<span class="mst-flag mst-flag-<?php echo esc_attr( $flag ); ?>"><?php echo esc_html( $label ); ?></span>
							<?php endforeach; ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		$this->render_pagination( $paged, $pages, $url );
	}

	/**
	 * @param array<int, string[]> $roles Site ID => role slugs.
	 * @param array<int, array>    $sites Every site's details.
	 */
	private function render_roles( $roles, $sites ) {
		if ( ! $roles ) {
			echo '<span class="mst-muted">—</span>';
			return;
		}

		$names = wp_roles()->role_names;
		$items = array();

		foreach ( $roles as $site_id => $slugs ) {
			$labels = array();
			foreach ( $slugs as $slug ) {
				$labels[] = isset( $names[ $slug ] ) ? translate_user_role( $names[ $slug ] ) : $slug;
			}

			$status  = MST_Sites::status( $sites[ $site_id ]['site'] );
			$items[] = sprintf(
				'<li>%s<a href="%s">%s</a> <span class="%s">%s</span>%s</li>',
				MST_Sites::swatch( $site_id ),
				esc_url( network_admin_url( 'site-users.php?id=' . $site_id ) ),
				esc_html( $sites[ $site_id ]['name'] ),
				in_array( 'administrator', $slugs, true ) ? 'mst-role-admin' : 'mst-muted',
				esc_html( $labels ? implode( ', ', $labels ) : __( 'No role', 'multisite-tools' ) ),
				'' !== $status ? ' <span class="mst-muted">(' . esc_html( $status ) . ')</span>' : ''
			);
		}

		echo '<ul class="mst-audit-sites">' . implode( '', array_slice( $items, 0, self::SITES_SHOWN ) ) . '</ul>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.

		if ( count( $items ) > self::SITES_SHOWN ) {
			/* translators: %s: number of sites */
			$more = sprintf( __( '%s more', 'multisite-tools' ), number_format_i18n( count( $items ) - self::SITES_SHOWN ) );
			echo '<details><summary>' . esc_html( $more ) . '</summary><ul class="mst-audit-sites">' . implode( '', array_slice( $items, self::SITES_SHOWN ) ) . '</ul></details>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		}
	}

	/**
	 * @param int $time Unix time, or 0 if unknown.
	 */
	private function render_date( $time ) {
		if ( ! $time ) {
			echo '<span class="mst-muted">' . esc_html__( 'Not recorded', 'multisite-tools' ) . '</span>';
			return;
		}

		echo esc_html( wp_date( get_option( 'date_format' ), $time ) );
		echo '<br><span class="mst-muted">';
		/* translators: %s: human-readable time difference, e.g. "3 days" */
		echo esc_html( sprintf( __( '%s ago', 'multisite-tools' ), human_time_diff( $time ) ) );
		echo '</span>';
	}

	/**
	 * @param int    $paged
	 * @param int    $pages
	 * @param string $url
	 */
	private function render_pagination( $paged, $pages, $url ) {
		if ( $pages < 2 ) {
			return;
		}

		echo '<div class="tablenav"><div class="tablenav-pages">';
		echo paginate_links( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			array(
				'base'    => add_query_arg( 'paged', '%#%', $url ),
				'format'  => '',
				'current' => $paged,
				'total'   => $pages,
			)
		);
		echo '</div></div>';
	}

	/**
	 * Every user with their roles, last login and flags, the ones needing
	 * attention first.
	 *
	 * @param array<int, array> $sites Every site's details.
	 * @return array<int, array{login: string, name: string, email: string, registered: int, last_login: int, roles: array<int, string[]>, flags: array<string, string>}>
	 */
	private function get_users( $sites ) {
		global $wpdb;

		$rows = $wpdb->get_results( "SELECT ID, user_login, user_email, display_name, user_registered FROM {$wpdb->users}" );

		$users = array();
		foreach ( $rows as $row ) {
			$users[ (int) $row->ID ] = array(
				'login'      => $row->user_login,
				'name'       => $row->display_name,
				'email'      => $row->user_email,
				'registered' => (int) strtotime( $row->user_registered . ' UTC' ),
				'last_login' => 0,
				'roles'      => array(),
				'flags'      => array(),
			);
		}

		// Each site's roles are in a <prefix>capabilities user meta row.
		$keys = array();
		foreach ( array_keys( $sites ) as $site_id ) {
			$keys[ $wpdb->get_blog_prefix( $site_id ) . 'capabilities' ] = $site_id;
		}

		foreach ( array_chunk( array_keys( $keys ), 500 ) as $chunk ) {
			$in   = implode( ', ', array_fill( 0, count( $chunk ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ({$in})", $chunk ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			foreach ( $rows as $row ) {
				if ( isset( $users[ (int) $row->user_id ] ) ) {
					$caps = maybe_unserialize( $row->meta_value );
					$users[ (int) $row->user_id ]['roles'][ $keys[ $row->meta_key ] ] = is_array( $caps ) ? array_keys( array_filter( $caps ) ) : array();
				}
			}
		}

		// Recorded logins, then the start of any live session, whichever is later.
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key IN (%s, 'session_tokens')", self::META ) );
		foreach ( $rows as $row ) {
			$id = (int) $row->user_id;
			if ( ! isset( $users[ $id ] ) ) {
				continue;
			}

			$time = 0;
			if ( self::META === $row->meta_key ) {
				$time = (int) $row->meta_value;
			} else {
				foreach ( (array) maybe_unserialize( $row->meta_value ) as $session ) {
					$time = max( $time, (int) ( $session['login'] ?? 0 ) );
				}
			}
			$users[ $id ]['last_login'] = max( $users[ $id ]['last_login'], $time );
		}

		$supers   = array_flip( get_super_admins() );
		$since    = (int) get_site_option( self::SINCE_OPTION, 0 );
		$cutoff   = time() - self::INACTIVE_AFTER;
		$tracking = $since && $since < $cutoff;

		foreach ( $users as &$user ) {
			ksort( $user['roles'] );

			$admin_on = count(
				array_filter(
					$user['roles'],
					function ( $roles ) {
						return in_array( 'administrator', $roles, true );
					}
				)
			);
			$has_role = (bool) array_filter( $user['roles'] );

			if ( isset( $supers[ $user['login'] ] ) ) {
				$user['flags']['super'] = __( 'Super admin', 'multisite-tools' );
			}
			if ( $admin_on ) {
				/* translators: %s: number of sites */
				$user['flags']['admin'] = sprintf( _n( 'Admin on %s site', 'Admin on %s sites', $admin_on, 'multisite-tools' ), number_format_i18n( $admin_on ) );
			}
			if ( $user['last_login'] ? $user['last_login'] < $cutoff : ( $tracking && $user['registered'] < $cutoff ) ) {
				$user['flags']['inactive'] = $user['last_login'] ? __( 'No login in 90 days', 'multisite-tools' ) : __( 'Never logged in', 'multisite-tools' );
			}
			if ( ! $has_role && ! isset( $user['flags']['super'] ) ) {
				$user['flags']['nosites'] = __( 'No sites', 'multisite-tools' );
			}
		}
		unset( $user );

		// Inactive admins first, then admins, then everyone else, by login.
		uasort(
			$users,
			function ( $a, $b ) {
				$rank = function ( $user ) {
					$admin = isset( $user['flags']['super'] ) || isset( $user['flags']['admin'] );
					return ( $admin && isset( $user['flags']['inactive'] ) ? 0 : 2 ) + ( $admin ? 0 : 1 );
				};
				return array( $rank( $a ), strtolower( $a['login'] ) ) <=> array( $rank( $b ), strtolower( $b['login'] ) );
			}
		);

		return $users;
	}

	private function print_styles() {
		?>
		<style>
			.mst-audit-search { margin: 1em 0; }
			.mst-audit td, .mst-audit th { vertical-align: top; }
			.mst-audit-sites { margin: 0; }
			.mst-audit-sites li { margin: 0 0 2px; }
			.mst-audit details summary { cursor: pointer; color: #2271b1; }
			.mst-muted { color: #8c8f94; }
			.mst-role-admin { color: #b32d2e; font-weight: 600; }
			.mst-flag { display: inline-block; margin: 0 4px 4px 0; padding: 1px 8px; border-radius: 10px; font-size: 12px; line-height: 1.6; white-space: nowrap; }
			.mst-flag-super { background: #fcf0f1; color: #b32d2e; }
			.mst-flag-admin { background: #fcf9e8; color: #8a6d00; }
			.mst-flag-inactive { background: #f0f0f1; color: #50575e; }
			.mst-flag-nosites { background: #f0f6fc; color: #135e96; }
		</style>
		<?php
	}
}
