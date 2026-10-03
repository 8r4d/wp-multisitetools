<?php
/**
 * Network search: finds posts and pages by title (or content) across every
 * site, from Network Admin › Dashboard › Search.
 */

defined( 'ABSPATH' ) || exit;

class MST_Network_Search {

	const PAGE = 'mst-network-search';

	const POST_TYPES = array( 'post', 'page' );
	const STATUSES   = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Most results read from one site per search.
	 */
	const PER_SITE_LIMIT = 50;

	const MIN_LENGTH = 2;

	public static function label() {
		return __( 'Network search', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds Network Admin › Dashboard › Search, which finds posts and pages by title or content across every site, with a link in the toolbar\'s Network Admin menu.', 'multisite-tools' );
	}

	public static function category() {
		return 'network';
	}

	public function register() {
		add_action( 'network_admin_menu', array( $this, 'add_page' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_toolbar_link' ), 25 );
	}

	public function add_page() {
		add_submenu_page( 'index.php', __( 'Search the Network', 'multisite-tools' ), __( 'Search', 'multisite-tools' ), 'manage_sites', self::PAGE, array( $this, 'render_page' ) );
	}

	/**
	 * Adds Search to My Sites › Network Admin.
	 *
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_toolbar_link( $wp_admin_bar ) {
		if ( ! current_user_can( 'manage_sites' ) || ! $wp_admin_bar->get_node( 'network-admin' ) ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'parent' => 'network-admin',
				'id'     => 'mst-network-search',
				'title'  => __( 'Search', 'multisite-tools' ),
				'href'   => network_admin_url( 'index.php?page=' . self::PAGE ),
			)
		);
	}

	public function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only search form.
		$term     = trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) );
		$content  = ! empty( $_GET['content'] );
		$status   = sanitize_key( $_GET['status'] ?? '' );
		$status   = in_array( $status, self::STATUSES, true ) ? $status : '';
		$type     = sanitize_key( $_GET['type'] ?? '' );
		$type     = in_array( $type, self::POST_TYPES, true ) ? $type : '';
		// phpcs:enable

		$searched = mb_strlen( $term ) >= self::MIN_LENGTH;
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Search the Network', 'multisite-tools' ); ?></h1>

			<form method="get" class="mst-search-form">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE ); ?>" />
				<p>
					<label class="screen-reader-text" for="mst-search-term"><?php esc_html_e( 'Search for', 'multisite-tools' ); ?></label>
					<input type="search" id="mst-search-term" name="s" value="<?php echo esc_attr( $term ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Search posts and pages on every site…', 'multisite-tools' ); ?>" autofocus />

					<label class="screen-reader-text" for="mst-search-type"><?php esc_html_e( 'Type', 'multisite-tools' ); ?></label>
					<select id="mst-search-type" name="type">
						<option value=""><?php esc_html_e( 'Posts and pages', 'multisite-tools' ); ?></option>
						<?php foreach ( self::POST_TYPES as $slug ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $type, $slug ); ?>><?php echo esc_html( get_post_type_object( $slug )->labels->name ); ?></option>
						<?php endforeach; ?>
					</select>

					<label class="screen-reader-text" for="mst-search-status"><?php esc_html_e( 'Status', 'multisite-tools' ); ?></label>
					<select id="mst-search-status" name="status">
						<option value=""><?php esc_html_e( 'Any status', 'multisite-tools' ); ?></option>
						<?php foreach ( self::STATUSES as $slug ) : ?>
							<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $status, $slug ); ?>><?php echo esc_html( get_post_status_object( $slug )->label ); ?></option>
						<?php endforeach; ?>
					</select>

					<label><input type="checkbox" name="content" value="1" <?php checked( $content ); ?> /> <?php esc_html_e( 'Also search content', 'multisite-tools' ); ?></label>

					<?php submit_button( __( 'Search', 'multisite-tools' ), 'primary', '', false ); ?>
				</p>
			</form>

			<?php
			if ( $searched ) {
				$this->render_results( $term, $content, $type ? array( $type ) : self::POST_TYPES, $status ? array( $status ) : self::STATUSES );
			} elseif ( '' !== $term ) {
				/* translators: %d: minimum number of characters */
				echo '<p>' . esc_html( sprintf( __( 'Enter at least %d characters.', 'multisite-tools' ), self::MIN_LENGTH ) ) . '</p>';
			}
			?>
		</div>
		<?php
	}

	/**
	 * @param string   $term
	 * @param bool     $content  Also match post content.
	 * @param string[] $types
	 * @param string[] $statuses
	 */
	private function render_results( $term, $content, $types, $statuses ) {
		global $wpdb;

		$sites = MST_Sites::details( MST_Sites::active() );
		$like  = '%' . $wpdb->esc_like( $term ) . '%';
		$where = $content
			? $wpdb->prepare( '(post_title LIKE %s OR post_content LIKE %s)', $like, $like )
			: $wpdb->prepare( 'post_title LIKE %s', $like );

		$types    = "'" . implode( "', '", array_map( 'esc_sql', $types ) ) . "'";
		$statuses = "'" . implode( "', '", array_map( 'esc_sql', $statuses ) ) . "'";

		$rows = MST_Sites::query(
			wp_list_pluck( $sites, 'site' ),
			function ( $site ) use ( $wpdb, $types, $statuses, $where ) {
				$table = $wpdb->get_blog_prefix( $site->blog_id ) . 'posts';
				return $wpdb->prepare(
					"SELECT %d AS blog_id, ID, post_title, post_type, post_status, post_date FROM `{$table}` WHERE post_type IN ({$types}) AND post_status IN ({$statuses}) AND {$where} ORDER BY post_date DESC LIMIT %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$site->blog_id,
					self::PER_SITE_LIMIT
				);
			}
		);

		usort(
			$rows,
			function ( $a, $b ) {
				return strcmp( $b->post_date, $a->post_date );
			}
		);

		$per_site = array_count_values( wp_list_pluck( $rows, 'blog_id' ) );
		$capped   = $per_site && max( $per_site ) >= self::PER_SITE_LIMIT;

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Nothing found.', 'multisite-tools' ) . '</p>';
			return;
		}
		?>
		<p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: "N results", 2: "on N sites" */
					_x( '%1$s %2$s.', 'search result summary', 'multisite-tools' ),
					/* translators: %s: number of results */
					sprintf( _n( '%s result', '%s results', count( $rows ), 'multisite-tools' ), number_format_i18n( count( $rows ) ) ),
					/* translators: %s: number of sites */
					sprintf( _n( 'on %s site', 'across %s sites', count( $per_site ), 'multisite-tools' ), number_format_i18n( count( $per_site ) ) )
				)
			);

			if ( $capped ) {
				/* translators: %d: results per site */
				echo ' ' . esc_html( sprintf( __( 'Showing the newest %d per site; narrow the search to see more.', 'multisite-tools' ), self::PER_SITE_LIMIT ) );
			}
			?>
		</p>

		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Title', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Site', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'multisite-tools' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Date', 'multisite-tools' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $rows as $row ) :
					$site      = $sites[ (int) $row->blog_id ];
					$id        = (int) $row->ID;
					$published = 'publish' === $row->post_status;
					$title     = '' !== $row->post_title ? $row->post_title : __( '(no title)', 'multisite-tools' );
					?>
					<tr>
						<td>
							<strong><a href="<?php echo esc_url( $site['admin_url'] . 'post.php?post=' . $id . '&action=edit' ); ?>"><?php echo $this->highlight( $title, $term ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></a></strong>
							<div class="row-actions visible">
								<a href="<?php echo esc_url( $site['admin_url'] . 'post.php?post=' . $id . '&action=edit' ); ?>"><?php esc_html_e( 'Edit', 'multisite-tools' ); ?></a>
								| <a href="<?php echo esc_url( $site['home_url'] . '?p=' . $id . ( $published ? '' : '&preview=true' ) ); ?>" target="_blank" rel="noopener"><?php echo $published ? esc_html__( 'View', 'multisite-tools' ) : esc_html__( 'Preview', 'multisite-tools' ); ?></a>
							</div>
						</td>
						<td><?php echo MST_Sites::swatch( (int) $row->blog_id ) . esc_html( $site['name'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( get_post_type_object( $row->post_type )->labels->singular_name ); ?></td>
						<td><?php echo esc_html( get_post_status_object( $row->post_status )->label ); ?></td>
						<td><?php echo esc_html( mysql2date( get_option( 'date_format' ), $row->post_date ) ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Escapes the title and marks where the term appears in it.
	 *
	 * @param string $title
	 * @param string $term
	 * @return string HTML.
	 */
	private function highlight( $title, $term ) {
		$escaped = esc_html( $title );
		$marked  = preg_replace( '/' . preg_quote( esc_html( $term ), '/' ) . '/iu', '<mark>$0</mark>', $escaped );

		// preg_replace() returns null on invalid UTF-8.
		return null !== $marked ? $marked : $escaped;
	}
}
