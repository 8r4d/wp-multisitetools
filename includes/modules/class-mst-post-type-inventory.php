<?php
/**
 * Post type inventory: lists the custom post types registered on every site,
 * finds content left behind by post types that are no longer registered, and
 * deletes it, under Network Admin › Sites › Post Types.
 *
 * Post types are registered in PHP on each request by a site's plugins and
 * theme, so Network Admin can't see which ones another site has. This works
 * in two halves:
 *
 * 1. Snapshot: on each request, a site records its registered custom post
 *    types in a blog option, writing only when they change. Admin requests
 *    and other requests are recorded separately, since some plugins only
 *    register their post types in one of them, and a type counts as
 *    registered if either has it.
 * 2. Report: Network Admin reads every site's posts grouped by post_type and
 *    compares them with that site's snapshot. A site with no snapshot yet is
 *    never reported as having orphans.
 */

defined( 'ABSPATH' ) || exit;

class MST_Post_Type_Inventory {

	const PAGE           = 'mst-post-types';
	const OPTION         = 'mst_post_types';
	const SINCE_OPTION   = 'mst_post_types_since';
	const REFRESH_ACTION = 'mst_refresh_post_types';
	const DELETE_ACTION  = 'mst_delete_orphans';

	/**
	 * Posts deleted per request.
	 */
	const BATCH = 50;

	public static function label() {
		return __( 'Post type inventory', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Network Admin › Sites › Post Types: which custom post types each site registers and has content in, with content left behind by post types that are no longer registered flagged and deletable.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	/**
	 * Called when the module is switched back on. Snapshots weren't updated
	 * while it was off, so a post type registered in the meantime would look
	 * orphaned. Ignore every snapshot taken before now.
	 */
	public function enable() {
		update_site_option( self::SINCE_OPTION, time() );
	}

	public function register() {
		// Late, so post types registered on init by plugins and themes are in.
		add_action( 'wp_loaded', array( $this, 'snapshot' ), 999 );
		add_action( 'wp_ajax_' . self::DELETE_ACTION, array( $this, 'ajax_delete' ) );

		if ( is_network_admin() ) {
			add_action( 'network_admin_menu', array( $this, 'add_page' ) );
			add_action( 'network_admin_edit_' . self::REFRESH_ACTION, array( $this, 'handle_refresh' ) );
		}
	}

	/**
	 * Records this site's registered custom post types if they've changed.
	 */
	public function snapshot() {
		// Network and user admin run on the main site but aren't a site
		// request, and a switched blog's post types aren't loaded.
		if ( is_network_admin() || is_user_admin() || ms_is_switched() || wp_installing() ) {
			return;
		}

		$types = array();
		foreach ( get_post_types( array( '_builtin' => false ), 'objects' ) as $name => $type ) {
			$types[ $name ] = (string) $type->label;
		}
		ksort( $types );

		$context  = is_admin() ? 'admin' : 'site';
		$snapshot = get_option( self::OPTION );
		$snapshot = is_array( $snapshot ) ? $snapshot : array();

		if ( ( $snapshot[ $context ]['types'] ?? null ) === $types && ( $snapshot[ $context ]['time'] ?? 0 ) >= (int) get_site_option( self::SINCE_OPTION, 0 ) ) {
			return;
		}

		$snapshot[ $context ] = array(
			'types' => $types,
			'time'  => time(),
		);

		update_option( self::OPTION, $snapshot, true );
	}

	public function add_page() {
		add_submenu_page( 'sites.php', __( 'Post Types', 'multisite-tools' ), __( 'Post Types', 'multisite-tools' ), 'manage_network', self::PAGE, array( $this, 'render_page' ) );
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only view parameters.
		$site_id   = absint( $_GET['site'] ?? 0 );
		$post_type = sanitize_key( wp_unslash( $_GET['type'] ?? '' ) );
		$refreshed = absint( $_GET['refreshed'] ?? 0 );
		// phpcs:enable

		echo '<div class="wrap">';
		$this->print_styles();

		if ( $site_id && '' !== $post_type ) {
			$this->render_delete_screen( $site_id, $post_type );
		} else {
			$this->render_report( $refreshed );
		}

		echo '</div>';
	}

	/**
	 * @param int $refreshed Number of sites just asked to refresh, if any.
	 */
	private function render_report( $refreshed ) {
		$inventory = $this->get_inventory();
		$orphans   = $inventory['orphans'];
		$unseen    = array_filter(
			$inventory['sites'],
			function ( $site ) {
				return ! $site['checked'];
			}
		);
		?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Post Types', 'multisite-tools' ); ?></h1>

		<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::REFRESH_ACTION ) ); ?>" style="display: inline;">
			<?php wp_nonce_field( self::REFRESH_ACTION ); ?>
			<button type="submit" class="page-title-action"><?php esc_html_e( 'Refresh all sites', 'multisite-tools' ); ?></button>
		</form>
		<hr class="wp-header-end">

		<?php if ( $refreshed ) : ?>
			<div class="notice notice-info is-dismissible"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: %s: number of sites */
						_n( 'Asked %s site to report its post types. Reload this page in a minute to see the results.', 'Asked %s sites to report their post types. Reload this page in a minute to see the results.', $refreshed, 'multisite-tools' ),
						number_format_i18n( $refreshed )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<p><?php esc_html_e( 'Each site reports the post types its plugins and theme register whenever it gets a visit. Content whose post type isn\'t registered on its site is listed as orphaned. A post type that\'s still registered on other sites usually means a plugin was deactivated on this one, so reactivating it may be the better fix.', 'multisite-tools' ); ?></p>

		<?php if ( $unseen ) : ?>
			<div class="notice notice-warning inline"><p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of sites, 2: list of sites */
						_n( '%1$s site hasn\'t reported its post types yet, so it isn\'t checked for orphans: %2$s. Use Refresh all sites, or visit it.', '%1$s sites haven\'t reported their post types yet, so they aren\'t checked for orphans: %2$s. Use Refresh all sites, or visit them.', count( $unseen ), 'multisite-tools' ),
						number_format_i18n( count( $unseen ) ),
						implode( ', ', wp_list_pluck( $unseen, 'name' ) )
					)
				);
				?>
			</p></div>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Orphaned content', 'multisite-tools' ); ?></h2>

		<?php if ( ! $orphans ) : ?>
			<p><?php esc_html_e( 'No orphaned content found.', 'multisite-tools' ); ?></p>
		<?php else : ?>
			<table class="widefat striped mst-post-types">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Post type', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Posts', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'By status', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Likely cause', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'multisite-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php
					foreach ( $orphans as $orphan ) :
						$site       = $inventory['sites'][ $orphan['site_id'] ];
						$type       = $inventory['types'][ $orphan['post_type'] ];
						$registered = count( $type['registered'] );
						?>
						<tr>
							<td>
								<?php echo MST_Sites::swatch( $orphan['site_id'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
								<a href="<?php echo esc_url( $site['admin_url'] . 'plugins.php' ); ?>"><?php echo esc_html( $site['name'] ); ?></a>
							</td>
							<td>
								<code><?php echo esc_html( $orphan['post_type'] ); ?></code>
								<?php if ( '' !== $type['label'] ) : ?>
									<br><span class="mst-muted"><?php echo esc_html( $type['label'] ); ?></span>
								<?php endif; ?>
							</td>
							<td class="num"><?php echo esc_html( number_format_i18n( $orphan['total'] ) ); ?></td>
							<td><?php echo esc_html( $this->format_statuses( $orphan['statuses'] ) ); ?></td>
							<td>
								<?php if ( $registered ) : ?>
									<span class="mst-flag mst-flag-deactivated">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: number of sites */
												_n( 'Still registered on %s other site', 'Still registered on %s other sites', $registered, 'multisite-tools' ),
												number_format_i18n( $registered )
											)
										);
										?>
									</span>
								<?php else : ?>
									<span class="mst-flag mst-flag-orphaned"><?php esc_html_e( 'Not registered anywhere', 'multisite-tools' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<a href="<?php echo esc_url( $this->delete_url( $orphan['site_id'], $orphan['post_type'] ) ); ?>" class="mst-delete-link"><?php esc_html_e( 'Delete…', 'multisite-tools' ); ?></a>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Custom post types', 'multisite-tools' ); ?></h2>

		<?php
		$types = array_filter(
			$inventory['types'],
			function ( $type ) {
				return $type['registered'] || $type['posts'];
			}
		);
		?>

		<?php if ( ! $types ) : ?>
			<p><?php esc_html_e( 'No custom post types are registered or in use on any site.', 'multisite-tools' ); ?></p>
		<?php else : ?>
			<table class="widefat striped mst-post-types">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Post type', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Registered on', 'multisite-tools' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Has content on', 'multisite-tools' ); ?></th>
						<th scope="col" class="num"><?php esc_html_e( 'Posts', 'multisite-tools' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $types as $name => $type ) : ?>
						<tr>
							<td>
								<code><?php echo esc_html( $name ); ?></code>
								<?php if ( '' !== $type['label'] ) : ?>
									<br><span class="mst-muted"><?php echo esc_html( $type['label'] ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php $this->render_site_list( $type['registered'], $inventory['sites'] ); ?></td>
							<td><?php $this->render_site_list( array_keys( $type['posts'] ), $inventory['sites'], $type['posts'], $name ); ?></td>
							<td class="num"><?php echo esc_html( number_format_i18n( array_sum( $type['posts'] ) ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
		<?php
	}

	/**
	 * @param int[]                 $site_ids
	 * @param array<int, array>     $sites     Inventory sites.
	 * @param array<int, int>       $counts    Site ID => number of posts, to show beside each site.
	 * @param string                $post_type Post type, to link each site's count to its list screen.
	 */
	private function render_site_list( $site_ids, $sites, $counts = array(), $post_type = '' ) {
		if ( ! $site_ids ) {
			echo '<span class="mst-muted">' . esc_html__( 'None', 'multisite-tools' ) . '</span>';
			return;
		}

		$label = sprintf(
			/* translators: %s: number of sites */
			_n( '%s site', '%s sites', count( $site_ids ), 'multisite-tools' ),
			number_format_i18n( count( $site_ids ) )
		);

		echo '<details class="mst-sites"><summary>' . esc_html( $label ) . '</summary><ul>';

		foreach ( $site_ids as $site_id ) {
			$site = $sites[ $site_id ];
			$name = esc_html( $site['name'] );

			if ( isset( $counts[ $site_id ] ) ) {
				$count = esc_html( number_format_i18n( $counts[ $site_id ] ) );

				// Only a registered post type has a list screen to link to.
				if ( in_array( $post_type, $site['registered'], true ) ) {
					$count = sprintf( '<a href="%s">%s</a>', esc_url( $site['admin_url'] . 'edit.php?post_type=' . rawurlencode( $post_type ) ), $count );
				} else {
					$count .= ' <span class="mst-flag mst-flag-orphaned">' . esc_html__( 'orphaned', 'multisite-tools' ) . '</span>';
				}

				$name .= ' <span class="mst-muted">(' . $count . ')</span>';
			}

			printf( '<li>%s%s</li>', MST_Sites::swatch( $site_id ), $name ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}

		echo '</ul></details>';
	}

	/**
	 * @param array<string, int> $statuses Post status => number of posts.
	 * @return string
	 */
	private function format_statuses( $statuses ) {
		$parts = array();

		foreach ( $statuses as $status => $count ) {
			$parts[] = sprintf( '%s: %s', $status, number_format_i18n( $count ) );
		}

		return implode( ', ', $parts );
	}

	/**
	 * Confirmation screen for deleting one site's orphaned posts of one type,
	 * with a dry run of what will go.
	 *
	 * @param int    $site_id
	 * @param string $post_type
	 */
	private function render_delete_screen( $site_id, $post_type ) {
		$back = network_admin_url( 'sites.php?page=' . self::PAGE );
		?>
		<h1><?php esc_html_e( 'Delete Orphaned Content', 'multisite-tools' ); ?></h1>
		<?php

		$error = $this->check_orphaned( $site_id, $post_type );

		if ( is_wp_error( $error ) ) {
			printf( '<div class="notice notice-error"><p>%s</p></div>', esc_html( $error->get_error_message() ) );
			printf( '<p><a href="%s">%s</a></p>', esc_url( $back ), esc_html__( '← Back to Post Types', 'multisite-tools' ) );
			return;
		}

		$site   = get_site( $site_id );
		$counts = $this->dry_run( $site_id, $post_type );
		$name   = get_blog_option( $site_id, 'blogname' );
		$name   = '' !== (string) $name ? $name : MST_Sites::url( $site );

		$rows = array(
			array( __( 'Posts', 'multisite-tools' ), $counts['posts'], __( 'Deleted permanently.', 'multisite-tools' ) ),
			array( __( 'Revisions', 'multisite-tools' ), $counts['revisions'], __( 'Deleted.', 'multisite-tools' ) ),
			array( __( 'Custom fields', 'multisite-tools' ), $counts['meta'], __( 'Deleted.', 'multisite-tools' ) ),
			array( __( 'Comments', 'multisite-tools' ), $counts['comments'], __( 'Deleted.', 'multisite-tools' ) ),
			array( __( 'Category and tag assignments', 'multisite-tools' ), $counts['terms'], __( 'Removed. The terms themselves stay.', 'multisite-tools' ) ),
			array( __( 'Attached media', 'multisite-tools' ), $counts['attachments'], __( 'Kept, but detached from the deleted posts.', 'multisite-tools' ) ),
		);
		?>
		<p>
			<?php
			printf(
				/* translators: 1: post type, 2: site name */
				esc_html__( 'This permanently deletes every %1$s post on %2$s. %1$s isn\'t registered on that site, so its posts can\'t be seen or edited there.', 'multisite-tools' ),
				'<code>' . esc_html( $post_type ) . '</code>',
				MST_Sites::swatch( $site_id ) . '<strong>' . esc_html( $name ) . '</strong>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			);
			?>
		</p>

		<table class="widefat striped mst-post-types" style="max-width: 48em;">
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<th scope="row"><?php echo esc_html( $row[0] ); ?></th>
						<td class="num"><?php echo esc_html( number_format_i18n( $row[1] ) ); ?></td>
						<td><?php echo esc_html( $row[2] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<div class="notice notice-warning inline">
			<p><?php esc_html_e( 'This can\'t be undone. Back up the site\'s database first. If the plugin that registered this post type is only deactivated, reactivating it brings the content back instead.', 'multisite-tools' ); ?></p>
		</div>

		<form id="mst-delete-orphans" data-total="<?php echo esc_attr( $counts['posts'] ); ?>">
			<p>
				<label for="mst-confirm">
					<?php
					printf(
						/* translators: %s: post type */
						esc_html__( 'Type %s to confirm:', 'multisite-tools' ),
						'<code>' . esc_html( $post_type ) . '</code>'
					);
					?>
				</label><br>
				<input type="text" id="mst-confirm" class="regular-text" autocomplete="off" />
			</p>
			<p>
				<button type="submit" class="button button-primary" id="mst-delete-button" disabled><?php esc_html_e( 'Delete permanently', 'multisite-tools' ); ?></button>
				<a href="<?php echo esc_url( $back ); ?>" class="button"><?php esc_html_e( 'Cancel', 'multisite-tools' ); ?></a>
			</p>
			<p id="mst-delete-progress" aria-live="polite"></p>
		</form>

		<script>
		( function () {
			var form = document.getElementById( 'mst-delete-orphans' );
			var input = document.getElementById( 'mst-confirm' );
			var button = document.getElementById( 'mst-delete-button' );
			var progress = document.getElementById( 'mst-delete-progress' );
			var postType = <?php echo wp_json_encode( $post_type ); ?>;
			var total = parseInt( form.dataset.total, 10 );
			var deleted = 0;
			var i18n = <?php echo wp_json_encode( array(
				'progress' => __( 'Deleted %1$s of %2$s…', 'multisite-tools' ),
				'done'     => __( 'Done: deleted %s posts.', 'multisite-tools' ),
				'stuck'    => __( 'Stopped: some posts couldn\'t be deleted.', 'multisite-tools' ),
				'failed'   => __( 'Stopped:', 'multisite-tools' ),
				'back'     => __( 'Back to Post Types', 'multisite-tools' ),
			) ); ?>;
			var back = <?php echo wp_json_encode( $back ); ?>;

			function format( text ) {
				var args = Array.prototype.slice.call( arguments, 1 );
				return text.replace( /%(\d)\$s|%s/g, function ( match, n ) {
					return n ? args[ n - 1 ] : args[ 0 ];
				} );
			}

			function finish( message ) {
				progress.textContent = message + ' ';
				var link = document.createElement( 'a' );
				link.href = back;
				link.textContent = i18n.back;
				progress.appendChild( link );
			}

			function batch() {
				var data = new FormData();
				data.append( 'action', <?php echo wp_json_encode( self::DELETE_ACTION ); ?> );
				data.append( '_ajax_nonce', <?php echo wp_json_encode( wp_create_nonce( $this->nonce_action( $site_id, $post_type ) ) ); ?> );
				data.append( 'site', <?php echo (int) $site_id; ?> );
				data.append( 'post_type', postType );
				data.append( 'confirm', input.value );

				fetch( <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>, { method: 'POST', body: data, credentials: 'same-origin' } )
					.then( function ( response ) { return response.json(); } )
					.then( function ( result ) {
						if ( ! result.success ) {
							finish( i18n.failed + ' ' + ( result.data || '' ) );
							return;
						}

						deleted += result.data.deleted;

						if ( ! result.data.remaining ) {
							finish( format( i18n.done, deleted ) );
						} else if ( ! result.data.deleted ) {
							finish( i18n.stuck );
						} else {
							progress.textContent = format( i18n.progress, deleted, Math.max( total, deleted + result.data.remaining ) );
							batch();
						}
					} )
					.catch( function ( error ) {
						finish( i18n.failed + ' ' + error.message );
					} );
			}

			input.addEventListener( 'input', function () {
				button.disabled = input.value !== postType;
			} );

			form.addEventListener( 'submit', function ( event ) {
				event.preventDefault();
				if ( input.value !== postType ) {
					return;
				}
				button.disabled = true;
				input.disabled = true;
				progress.textContent = format( i18n.progress, 0, total );
				batch();
			} );
		}() );
		</script>
		<?php
	}

	/**
	 * Deletes one batch of a site's orphaned posts of one type.
	 */
	public function ajax_delete() {
		$site_id   = absint( $_POST['site'] ?? 0 );
		$post_type = sanitize_key( wp_unslash( $_POST['post_type'] ?? '' ) );

		check_ajax_referer( $this->nonce_action( $site_id, $post_type ) );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_send_json_error( __( 'Sorry, you are not allowed to do that.', 'multisite-tools' ), 403 );
		}

		if ( sanitize_key( wp_unslash( $_POST['confirm'] ?? '' ) ) !== $post_type ) {
			wp_send_json_error( __( 'The confirmation didn\'t match the post type.', 'multisite-tools' ), 400 );
		}

		// Checked again on every batch: the site may have reported in since.
		$error = $this->check_orphaned( $site_id, $post_type );

		if ( is_wp_error( $error ) ) {
			wp_send_json_error( $error->get_error_message(), 400 );
		}

		global $wpdb;

		switch_to_blog( $site_id );

		$post_ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT %d", $post_type, self::BATCH ) );
		$deleted  = 0;

		wp_defer_term_counting( true );

		foreach ( $post_ids as $post_id ) {
			$this->delete_term_relationships( (int) $post_id );

			if ( wp_delete_post( (int) $post_id, true ) ) {
				$deleted++;
			}
		}

		wp_defer_term_counting( false );

		$remaining = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s", $post_type ) );

		restore_current_blog();

		wp_send_json_success(
			array(
				'deleted'   => $deleted,
				'remaining' => $remaining,
			)
		);
	}

	/**
	 * Removes a post's term relationships and recounts the terms. Core's
	 * wp_delete_post() only removes those for taxonomies registered for the
	 * post's type, which an orphaned type has none of. Call while switched to
	 * the post's site.
	 *
	 * @param int $post_id
	 */
	private function delete_term_relationships( $post_id ) {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT tt.term_taxonomy_id, tt.taxonomy FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d",
				$post_id
			)
		);

		$by_taxonomy = array();

		foreach ( $rows as $row ) {
			// object_id is only a post ID for post taxonomies; link_category,
			// say, uses link IDs, which can collide. Unregistered taxonomies
			// are assumed to have gone with the post type.
			$taxonomy = get_taxonomy( $row->taxonomy );
			if ( $taxonomy && ! array_filter( (array) $taxonomy->object_type, 'post_type_exists' ) ) {
				continue;
			}

			$by_taxonomy[ $row->taxonomy ][] = (int) $row->term_taxonomy_id;
		}

		foreach ( $by_taxonomy as $taxonomy => $tt_ids ) {
			$in = implode( ',', $tt_ids );

			$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->term_relationships} WHERE object_id = %d AND term_taxonomy_id IN ({$in})", $post_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			wp_cache_delete( $post_id, $taxonomy . '_relationships' );

			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count( $tt_ids, $taxonomy );
			} else {
				// No taxonomy object to count with, so count every object.
				$wpdb->query( "UPDATE {$wpdb->term_taxonomy} tt SET count = ( SELECT COUNT(*) FROM {$wpdb->term_relationships} tr WHERE tr.term_taxonomy_id = tt.term_taxonomy_id ) WHERE tt.term_taxonomy_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				clean_term_cache( $tt_ids, '', false );
			}
		}
	}

	/**
	 * What deleting a site's posts of one type would remove.
	 *
	 * @param int    $site_id
	 * @param string $post_type
	 * @return array<string, int>
	 */
	private function dry_run( $site_id, $post_type ) {
		global $wpdb;

		$prefix = $wpdb->get_blog_prefix( $site_id );
		$posts  = "SELECT ID FROM `{$prefix}posts` WHERE post_type = %s";

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = function ( $sql ) use ( $wpdb, $post_type ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $post_type ) );
		};

		return array(
			'posts'       => $count( "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type = %s" ),
			'revisions'   => $count( "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type = 'revision' AND post_parent IN ({$posts})" ),
			'meta'        => $count( "SELECT COUNT(*) FROM `{$prefix}postmeta` WHERE post_id IN ({$posts})" ),
			'comments'    => $count( "SELECT COUNT(*) FROM `{$prefix}comments` WHERE comment_post_ID IN ({$posts})" ),
			'terms'       => $count( "SELECT COUNT(*) FROM `{$prefix}term_relationships` tr INNER JOIN `{$prefix}term_taxonomy` tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy <> 'link_category' AND tr.object_id IN ({$posts})" ),
			'attachments' => $count( "SELECT COUNT(*) FROM `{$prefix}posts` WHERE post_type = 'attachment' AND post_parent IN ({$posts})" ),
		);
		// phpcs:enable
	}

	/**
	 * @param int    $site_id
	 * @param string $post_type
	 * @return true|WP_Error True if the site has posts of a type it's known
	 *                       not to register.
	 */
	private function check_orphaned( $site_id, $post_type ) {
		$site = get_site( $site_id );

		if ( ! $site || (int) $site->network_id !== get_current_network_id() ) {
			return new WP_Error( 'mst_no_site', __( 'That site doesn\'t exist.', 'multisite-tools' ) );
		}

		if ( '' === $post_type || post_type_exists( $post_type ) && get_post_type_object( $post_type )->_builtin ) {
			return new WP_Error( 'mst_builtin', __( 'That post type is built into WordPress.', 'multisite-tools' ) );
		}

		$snapshot = $this->read_snapshot( get_blog_option( $site_id, self::OPTION ) );

		if ( ! $snapshot['checked'] ) {
			return new WP_Error( 'mst_no_snapshot', __( 'That site hasn\'t reported its post types yet, so there\'s no way to tell whether this one is registered there.', 'multisite-tools' ) );
		}

		if ( isset( $snapshot['types'][ $post_type ] ) ) {
			return new WP_Error( 'mst_registered', __( 'That post type is registered on that site, so its content isn\'t orphaned.', 'multisite-tools' ) );
		}

		return true;
	}

	/**
	 * Merges a site's admin and non-admin snapshots, ignoring any taken before
	 * the module was last switched on.
	 *
	 * @param mixed $snapshot Raw option value.
	 * @return array{types: array<string, string>, checked: int} Registered
	 *         post type => label, and when the site last reported (0 if never).
	 */
	private function read_snapshot( $snapshot ) {
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$since    = (int) get_site_option( self::SINCE_OPTION, 0 );
		$merged   = array(
			'types'   => array(),
			'checked' => 0,
		);

		foreach ( array( 'admin', 'site' ) as $context ) {
			$time = (int) ( $snapshot[ $context ]['time'] ?? 0 );

			if ( ! $time || $time < $since ) {
				continue;
			}

			$merged['types']  += (array) ( $snapshot[ $context ]['types'] ?? array() );
			$merged['checked'] = max( $merged['checked'], $time );
		}

		return $merged;
	}

	/**
	 * Every active site's custom post types and content, and the orphans.
	 *
	 * @return array{sites: array<int, array>, types: array<string, array>, orphans: array[]}
	 */
	private function get_inventory() {
		global $wpdb;

		$sites   = MST_Sites::details( MST_Sites::active(), array( self::OPTION ) );
		$builtin = get_post_types( array( '_builtin' => true ) );
		$types   = array();

		$add_type = function ( $name ) use ( &$types ) {
			if ( ! isset( $types[ $name ] ) ) {
				$types[ $name ] = array(
					'label'      => '',
					'registered' => array(),
					'posts'      => array(),
				);
			}
		};

		foreach ( $sites as $id => &$site ) {
			$snapshot           = $this->read_snapshot( maybe_unserialize( $site['options'][ self::OPTION ] ?? '' ) );
			$site['checked']    = $snapshot['checked'];
			$site['registered'] = array_keys( $snapshot['types'] );

			foreach ( $snapshot['types'] as $name => $label ) {
				$add_type( $name );
				$types[ $name ]['registered'][] = $id;
				if ( '' === $types[ $name ]['label'] ) {
					$types[ $name ]['label'] = $label;
				}
			}
		}
		unset( $site );

		$rows = MST_Sites::query(
			wp_list_pluck( $sites, 'site' ),
			function ( $site ) use ( $wpdb ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
				return $wpdb->prepare(
					"SELECT %d AS blog_id, post_type, post_status, COUNT(*) AS posts FROM `{$table}` GROUP BY post_type, post_status", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id
				);
			}
		);

		$orphans = array();

		foreach ( $rows as $row ) {
			$id   = (int) $row->blog_id;
			$name = (string) $row->post_type;

			if ( isset( $builtin[ $name ] ) ) {
				continue;
			}

			$add_type( $name );
			$types[ $name ]['posts'][ $id ] = ( $types[ $name ]['posts'][ $id ] ?? 0 ) + (int) $row->posts;

			if ( $sites[ $id ]['checked'] && ! in_array( $name, $sites[ $id ]['registered'], true ) ) {
				$key = $id . ':' . $name;

				$orphans[ $key ]['site_id']                       = $id;
				$orphans[ $key ]['post_type']                     = $name;
				$orphans[ $key ]['total']                         = ( $orphans[ $key ]['total'] ?? 0 ) + (int) $row->posts;
				$orphans[ $key ]['statuses'][ $row->post_status ] = (int) $row->posts;
			}
		}

		ksort( $types );

		foreach ( $types as &$entry ) {
			ksort( $entry['posts'] );
		}
		unset( $entry );

		// Biggest first.
		usort(
			$orphans,
			function ( $a, $b ) {
				return $b['total'] <=> $a['total'];
			}
		);

		return array(
			'sites'   => $sites,
			'types'   => $types,
			'orphans' => $orphans,
		);
	}

	/**
	 * Pings every active site's wp-cron.php, which loads the site's plugins
	 * and theme, so a site that hasn't reported yet, or whose post types have
	 * changed, takes a snapshot.
	 */
	public function handle_refresh() {
		check_admin_referer( self::REFRESH_ACTION );

		if ( ! current_user_can( 'manage_network' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'multisite-tools' ), 403 );
		}

		$sites = MST_Sites::active();

		foreach ( $sites as $site ) {
			wp_remote_post(
				get_site_url( $site->blog_id, 'wp-cron.php' ),
				array(
					'timeout'   => 0.01,
					'blocking'  => false,
					/** This filter is documented in wp-includes/class-wp-http-streams.php */
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				)
			);
		}

		wp_safe_redirect( add_query_arg( 'refreshed', count( $sites ), network_admin_url( 'sites.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * @param int    $site_id
	 * @param string $post_type
	 * @return string
	 */
	private function delete_url( $site_id, $post_type ) {
		return add_query_arg(
			array(
				'site' => $site_id,
				// Not post_type: on a post type registered here, admin.php
				// would look for the page under sites.php?post_type=… and
				// fail with "Cannot load".
				'type' => $post_type,
			),
			network_admin_url( 'sites.php?page=' . self::PAGE )
		);
	}

	/**
	 * @param int    $site_id
	 * @param string $post_type
	 * @return string
	 */
	private function nonce_action( $site_id, $post_type ) {
		return self::DELETE_ACTION . '_' . $site_id . '_' . $post_type;
	}

	private function print_styles() {
		?>
		<style>
			.mst-post-types td, .mst-post-types th { vertical-align: top; }
			.mst-post-types .num { text-align: right; }
			.mst-post-types code { background: none; padding: 0; }
			.mst-muted { color: #8c8f94; }
			.mst-flag { display: inline-block; padding: 1px 8px; border-radius: 10px; font-size: 12px; line-height: 1.6; white-space: nowrap; }
			.mst-flag-orphaned { background: #fcf0f1; color: #b32d2e; }
			.mst-flag-deactivated { background: #fcf9e8; color: #8a6d00; }
			.mst-sites summary { cursor: pointer; color: #2271b1; }
			.mst-sites ul { margin: 4px 0 0; }
			.mst-sites li { margin: 0; }
			.mst-delete-link { color: #b32d2e; }
		</style>
		<?php
	}
}
