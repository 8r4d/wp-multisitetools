<?php
/**
 * Network digest: the [network_digest] shortcode shows recent posts from
 * chosen sites as one feed, newest first, with "Load more" paging. Posts are
 * read live from the source sites (cached briefly), not copied. The sites are
 * chosen under Network Admin › Settings › Network Digest.
 */

defined( 'ABSPATH' ) || exit;

class MST_Network_Digest {

	/**
	 * Same option names as the standalone Network Digest plugin, so existing
	 * settings carry over. The source sites are stored as comma-separated IDs.
	 */
	const SITES_OPTION = 'network_digest_source_site_ids';
	const COUNT_OPTION = 'network_digest_posts_per_source';

	const DEFAULT_COUNT = 5;
	const MAX_COUNT     = 50;

	const PAGE      = 'network-digest';
	const ACTION    = 'mst_save_network_digest';
	const CACHE_KEY = 'mst_network_digest';
	const CACHE_TTL = 15 * MINUTE_IN_SECONDS;

	/**
	 * Shortcode, AJAX action, nonce and asset handle, unchanged from the
	 * standalone plugin so existing pages and theme styles keep working.
	 */
	const SHORTCODE   = 'network_digest';
	const AJAX_ACTION = 'network_digest_load_more';
	const NONCE       = 'network_digest_nonce';
	const HANDLE      = 'network-digest';

	public static function label() {
		return __( 'Network digest', 'multisite-tools' );
	}

	public static function description() {
		return __( 'Adds a [network_digest] shortcode that shows recent posts from chosen sites as one feed, with tags and a "Load more" button. Choose the sites under Network Admin › Settings › Network Digest.', 'multisite-tools' );
	}

	public static function category() {
		return 'publishing';
	}

	/**
	 * Called when the module is switched back on. The feed may have gone
	 * stale while the invalidation hooks below weren't registered.
	 */
	public function enable() {
		$this->flush();
	}

	public function register() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );

		// Logged-out visitors use "Load more" too.
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'ajax_load_more' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'ajax_load_more' ) );

		// A post going live, changing or leaving, on any site, invalidates the feed.
		add_action( 'transition_post_status', array( $this, 'flush_on_transition' ), 10, 3 );
		add_action( 'deleted_post', array( $this, 'flush' ) );
		add_action( 'update_option_blogname', array( $this, 'flush' ) );
		add_action( 'wp_uninitialize_site', array( $this, 'flush' ) );
		add_action( 'wp_update_site', array( $this, 'flush' ) );

		if ( is_network_admin() ) {
			add_action( 'network_admin_menu', array( $this, 'add_page' ) );
			add_action( 'network_admin_edit_' . self::ACTION, array( $this, 'save' ) );
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
		// Includes publish → publish, i.e. an edit to a live post.
		if ( 'post' === $post->post_type && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			$this->flush();
		}
	}

	/* Settings ------------------------------------------------------------ */

	public function add_page() {
		add_submenu_page(
			'settings.php',
			__( 'Network Digest', 'multisite-tools' ),
			__( 'Network Digest', 'multisite-tools' ),
			'manage_network_options',
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		$selected = $this->source_site_ids();
		$count    = $this->posts_per_source();
		$details  = MST_Sites::details( MST_Sites::active() );
		$updated  = isset( $_GET['updated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Network Digest', 'multisite-tools' ); ?></h1>

			<?php if ( $updated ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'multisite-tools' ); ?></p></div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: %s: shortcode */
					esc_html__( 'Add %s to a page or post to show recent posts from the sites below as one feed, newest first.', 'multisite-tools' ),
					'<code>[network_digest]</code>'
				);
				?>
			</p>

			<form method="post" action="<?php echo esc_url( network_admin_url( 'edit.php?action=' . self::ACTION ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Source sites', 'multisite-tools' ); ?></th>
						<td>
							<fieldset>
								<legend class="screen-reader-text"><?php esc_html_e( 'Source sites', 'multisite-tools' ); ?></legend>
								<?php foreach ( $details as $id => $site ) : ?>
									<label style="display: block; margin-bottom: 6px;">
										<input type="checkbox" name="mst_digest_sites[]" value="<?php echo esc_attr( $id ); ?>" <?php checked( in_array( $id, $selected, true ) ); ?> />
										<?php echo MST_Sites::swatch( $id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<?php echo esc_html( $site['name'] ); ?>
										<span class="description">(<?php echo esc_html( $site['url'] ); ?>)</span>
									</label>
								<?php endforeach; ?>
							</fieldset>
							<p class="description"><?php esc_html_e( 'Published posts from these sites are shown. Archived, spam and deactivated sites aren\'t listed.', 'multisite-tools' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="mst-digest-count"><?php esc_html_e( 'Posts per source site', 'multisite-tools' ); ?></label></th>
						<td>
							<input type="number" id="mst-digest-count" name="mst_digest_count" value="<?php echo esc_attr( $count ); ?>" min="1" max="<?php echo esc_attr( self::MAX_COUNT ); ?>" class="small-text" />
							<p class="description"><?php esc_html_e( 'How many recent posts to read from each site before merging them by date. This also caps how far back "Load more" can go, so raise it for a deeper archive.', 'multisite-tools' ); ?></p>
						</td>
					</tr>
				</table>

				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Shortcode options', 'multisite-tools' ); ?></h2>
			<ul class="ul-disc">
				<li><?php echo wp_kses( __( '<code>per_page</code>: posts shown at first and per "Load more" click. Default 3.', 'multisite-tools' ), array( 'code' => array() ) ); ?></li>
				<li><?php echo wp_kses( __( '<code>limit</code>: most posts shown in total, or 0 for no cap. Default 60.', 'multisite-tools' ), array( 'code' => array() ) ); ?></li>
			</ul>
			<p><?php echo wp_kses( __( 'For example: <code>[network_digest per_page="10" limit="30"]</code>', 'multisite-tools' ), array( 'code' => array() ) ); ?></p>
		</div>
		<?php
	}

	public function save() {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to manage these settings.', 'multisite-tools' ), 403 );
		}

		$submitted = array_map( 'absint', (array) ( $_POST['mst_digest_sites'] ?? array() ) );
		$count     = absint( $_POST['mst_digest_count'] ?? self::DEFAULT_COUNT );

		// Keep selected sites that aren't on the form (e.g. since archived), so
		// they come back if the site does.
		$active = array_map( 'intval', wp_list_pluck( MST_Sites::active(), 'blog_id' ) );
		$hidden = array_diff( $this->source_site_ids(), $active );
		$ids    = array_unique( array_merge( $hidden, array_intersect( $submitted, $active ) ) );
		sort( $ids );

		update_site_option( self::SITES_OPTION, implode( ',', $ids ) );
		update_site_option( self::COUNT_OPTION, min( self::MAX_COUNT, max( 1, $count ) ) );
		$this->flush();

		wp_safe_redirect( add_query_arg( 'updated', 'true', network_admin_url( 'settings.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * @return int[]
	 */
	private function source_site_ids() {
		$ids = array_filter( array_map( 'absint', explode( ',', (string) get_site_option( self::SITES_OPTION, '' ) ) ) );
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return int
	 */
	private function posts_per_source() {
		return min( self::MAX_COUNT, max( 1, (int) get_site_option( self::COUNT_OPTION, self::DEFAULT_COUNT ) ) );
	}

	/* Feed ---------------------------------------------------------------- */

	/**
	 * Merged posts from every source site, newest first.
	 *
	 * @return array[]
	 */
	private function get_posts() {
		$posts = get_site_transient( self::CACHE_KEY );

		if ( is_array( $posts ) ) {
			return $posts;
		}

		$posts = array();
		$count = $this->posts_per_source();

		foreach ( $this->source_site_ids() as $site_id ) {
			$site = get_site( $site_id );

			// Skip sites that no longer exist or aren't public-facing.
			if ( ! $site || (int) $site->archived || (int) $site->spam || (int) $site->deleted ) {
				continue;
			}

			// Permalinks, excerpts and thumbnails need the site's own context.
			switch_to_blog( $site_id );

			$query = new WP_Query(
				array(
					'post_type'           => 'post',
					'post_status'         => 'publish',
					'posts_per_page'      => $count,
					'orderby'             => 'date',
					'order'               => 'DESC',
					'ignore_sticky_posts' => true,
					'no_found_rows'       => true,
				)
			);

			$site_name = get_bloginfo( 'name' );
			$site_url  = home_url( '/' );

			while ( $query->have_posts() ) {
				$query->the_post();

				$posts[] = array(
					'title'     => get_the_title(),
					'permalink' => get_permalink(),
					'excerpt'   => wp_trim_words( wp_strip_all_tags( get_the_excerpt() ), 30, '&hellip;' ),
					'date'      => (int) get_post_time( 'U', true ),
					'date_fmt'  => get_the_date(),
					'thumbnail' => get_the_post_thumbnail_url( get_the_ID(), 'medium' ),
					'author'    => get_the_author(),
					'site_id'   => $site_id,
					'site_name' => $site_name,
					'site_url'  => $site_url,
					'tags'      => $this->get_tags(),
				);
			}

			wp_reset_postdata();
			restore_current_blog();
		}

		usort(
			$posts,
			function ( $a, $b ) {
				return $b['date'] <=> $a['date'];
			}
		);

		set_site_transient( self::CACHE_KEY, $posts, self::CACHE_TTL );

		return $posts;
	}

	/**
	 * The current post's tags, linking to the source site's tag archives.
	 *
	 * @return array<int, array{name: string, link: string}>
	 */
	private function get_tags() {
		$terms = get_the_terms( get_the_ID(), 'post_tag' );

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$tags = array();
		foreach ( $terms as $term ) {
			$link = get_term_link( $term );

			if ( ! is_wp_error( $link ) ) {
				$tags[] = array(
					'name' => $term->name,
					'link' => $link,
				);
			}
		}

		return $tags;
	}

	/* Front end ------------------------------------------------------------ */

	public function register_assets() {
		$dir = MST_DIR . 'assets/';
		$url = plugins_url( 'assets/', MST_FILE );

		// Enqueued only when the shortcode renders. Versioned by file time so
		// browsers pick up every edit.
		wp_register_script( self::HANDLE, $url . 'network-digest.js', array(), (string) filemtime( $dir . 'network-digest.js' ), true );
		wp_register_style( self::HANDLE, $url . 'network-digest.css', array(), (string) filemtime( $dir . 'network-digest.css' ) );
	}

	/**
	 * @param array|string $atts
	 * @return string
	 */
	public function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'per_page' => 3,
				'limit'    => 60,
			),
			$atts,
			self::SHORTCODE
		);

		$per_page = max( 1, (int) $atts['per_page'] );
		$posts    = $this->limit( $this->get_posts(), (int) $atts['limit'] );

		if ( empty( $posts ) ) {
			return '<p class="network-digest-empty">' . esc_html__( 'No posts to show yet.', 'multisite-tools' ) . '</p>';
		}

		$total = count( $posts );

		wp_enqueue_script( self::HANDLE );
		wp_enqueue_style( self::HANDLE );

		// Config goes in data-* attributes rather than wp_localize_script(),
		// since JS optimisation plugins often strip or reorder inline scripts.
		ob_start();
		?>
		<div class="network-digest" id="network-digest"
			data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>"
			data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE ) ); ?>"
			data-per-page="<?php echo esc_attr( $per_page ); ?>"
			data-total="<?php echo esc_attr( $total ); ?>">
			<div class="network-digest-items">
				<?php
				foreach ( array_slice( $posts, 0, $per_page ) as $post ) {
					echo $this->render_item( $post ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				}
				?>
			</div>

			<?php if ( $total > $per_page ) : ?>
				<div class="network-digest-load-more-wrap">
					<button type="button" class="network-digest-load-more" data-offset="<?php echo esc_attr( $per_page ); ?>">
						<?php esc_html_e( 'Load more', 'multisite-tools' ); ?>
					</button>
					<span class="network-digest-loading" style="display:none;"><?php esc_html_e( 'Loading…', 'multisite-tools' ); ?></span>
				</div>
			<?php endif; ?>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * @param array[] $posts
	 * @param int     $limit 0 for no cap.
	 * @return array[]
	 */
	private function limit( $posts, $limit ) {
		return $limit > 0 ? array_slice( $posts, 0, $limit ) : $posts;
	}

	/**
	 * One post's markup, shared by the shortcode and "Load more".
	 *
	 * @param array $post
	 * @return string HTML.
	 */
	private function render_item( $post ) {
		ob_start();
		?>
		<article class="network-digest-item">
			<?php if ( ! empty( $post['thumbnail'] ) ) : ?>
				<a href="<?php echo esc_url( $post['permalink'] ); ?>" class="network-digest-thumb" target="_blank" rel="noopener">
					<img src="<?php echo esc_url( $post['thumbnail'] ); ?>" alt="" loading="lazy" />
				</a>
			<?php endif; ?>
			<div class="network-digest-body">
				<h3 class="network-digest-title">
					<a href="<?php echo esc_url( $post['permalink'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $post['title'] ); ?></a>
				</h3>
				<p class="network-digest-excerpt"><?php echo esc_html( $post['excerpt'] ); ?></p>
				<p class="network-digest-meta">
					<span class="network-digest-source">
						<a href="<?php echo esc_url( $post['site_url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $post['site_name'] ); ?></a>
					</span>
					&middot;
					<span class="network-digest-author"><?php echo esc_html( $post['author'] ); ?></span>
					&middot;
					<span class="network-digest-date"><?php echo esc_html( $post['date_fmt'] ); ?></span>
				</p>
				<?php if ( ! empty( $post['tags'] ) ) : ?>
					<p class="network-digest-tags">
						<?php foreach ( $post['tags'] as $tag ) : ?>
							<a class="network-digest-tag" href="<?php echo esc_url( $tag['link'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $tag['name'] ); ?></a>
						<?php endforeach; ?>
					</p>
				<?php endif; ?>
			</div>
		</article>
		<?php
		return ob_get_clean();
	}

	public function ajax_load_more() {
		// Some CDNs and caching plugins cache admin-ajax.php regardless.
		nocache_headers();

		check_ajax_referer( self::NONCE, 'nonce' );

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- cast to int.
		$offset   = absint( $_POST['offset'] ?? 0 );
		$per_page = max( 1, absint( $_POST['per_page'] ?? 10 ) );
		$limit    = absint( $_POST['limit'] ?? 60 );
		// phpcs:enable

		$posts = $this->limit( $this->get_posts(), $limit );

		$html = '';
		foreach ( array_slice( $posts, $offset, $per_page ) as $post ) {
			$html .= $this->render_item( $post );
		}

		wp_send_json_success(
			array(
				'html'     => $html,
				'has_more' => ( $offset + $per_page ) < count( $posts ),
			)
		);
	}
}
